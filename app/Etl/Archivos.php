<?php

namespace App\Etl;

use App\Support\RutaDeArchivo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * El lado de escritura de las imágenes: PERF-2 en la práctica.
 *
 * Las tres tablas de imágenes guardan la ruta de un archivo, no su contenido, y
 * esta clase es la única que toca el disco. Hace los dos trabajos que el origen
 * impone, porque tiene las dos formas mezcladas:
 *
 * - **Decodificar** un data URI y escribirlo. Son 2.760 valores y 1.088 MB
 *   de base64 en la base.
 * - **Copiar** un archivo que ya existe en el servidor viejo. Son 1.749, y no
 *   están en ningún backup de la base: salen de un `tar` aparte (DATA-9).
 *
 * El disco es privado a propósito: la API sirve los bytes armando el data URI,
 * así que nada necesita estar en public/, y dos de cada cuatro documentos de
 * postulación son el DNI de una persona.
 */
class Archivos
{
    /**
     * Lo único que el sistema viejo guarda. No se acepta nada más: una
     * extensión que sale de un valor del origen es una extensión que alguien
     * puede elegir.
     */
    private const TIPOS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
    ];

    /** Los tres directorios que escribe, uno por tabla. */
    public const USER_FOTOS = 'user-fotos';

    public const POSTULACION_DOCUMENTOS = 'postulacion-documentos';

    public const PUBLICIDAD_IMAGENES = 'publicidad-app-imagenes';

    public const DIRECTORIOS = [
        self::USER_FOTOS,
        self::POSTULACION_DOCUMENTOS,
        self::PUBLICIDAD_IMAGENES,
    ];

    /**
     * Escribe un data URI como archivo y devuelve su ruta relativa al disco.
     *
     * `$destino` va sin extensión: la pone el tipo declarado en el data URI,
     * que es de donde sale el tipo del archivo —por eso el esquema no necesita
     * una columna mime—.
     */
    public function desdeDataUri(string $valor, string $destino): string
    {
        [$tipo, $base64] = self::partes($valor);

        $extension = self::TIPOS[$tipo] ?? throw new RuntimeException(
            "El data URI declara «{$tipo}», que no es JPEG ni PNG."
        );

        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("El base64 de «{$destino}» no se pudo decodificar.");
        }

        $ruta = "{$destino}.{$extension}";
        self::comprobar($ruta);

        Storage::disk(self::disco())->put($ruta, $bytes);

        return $ruta;
    }

    /**
     * Copia un archivo que ya existe en el servidor viejo y devuelve su ruta
     * nueva. `$origen` es la ruta tal como la guarda MongoDB, relativa al tar
     * desempaquetado.
     */
    public function copiando(string $origen, string $destino): string
    {
        if (! RutaDeArchivo::esValida($origen)) {
            throw new RuntimeException("La ruta de origen «{$origen}» no es una ruta relativa segura.");
        }

        $completa = rtrim((string) config('etl.archivos'), '/').'/'.$origen;

        if (! is_readable($completa)) {
            throw new RuntimeException(
                "Falta el archivo «{$origen}». Sale del tar de postulaciones, que no está en "
                .'ningún backup de la base (DATA-9). Configurar ETL_ARCHIVOS.'
            );
        }

        $extension = strtolower(pathinfo($origen, PATHINFO_EXTENSION));

        if (! in_array($extension, self::TIPOS, true)) {
            throw new RuntimeException("El archivo «{$origen}» no es .jpg ni .png.");
        }

        $ruta = "{$destino}.{$extension}";
        self::comprobar($ruta);

        $puntero = fopen($completa, 'rb');

        try {
            Storage::disk(self::disco())->put($ruta, $puntero);
        } finally {
            fclose($puntero);
        }

        return $ruta;
    }

    /**
     * Borra lo escrito. Va con `--reiniciar`, porque vaciar las tablas y dejar
     * los archivos deja huérfanos en disco: los nombres salen del id nuevo, y
     * al reiniciar las secuencias los ids se reusan, así que un archivo de una
     * corrida anterior puede quedar sin fila —o peor, con la extensión de otra
     * imagen— sin que nada lo note.
     */
    public static function vaciar(): void
    {
        foreach (self::DIRECTORIOS as $directorio) {
            Storage::disk(self::disco())->deleteDirectory($directorio);
        }
    }

    /**
     * El tipo declarado y el base64, de `data:image/png;base64,iVBOR…`.
     *
     * @return array{0: string, 1: string}
     */
    private static function partes(string $valor): array
    {
        if (preg_match('/^data:([a-z0-9.+\/-]+);base64,(.*)$/is', $valor, $partes) !== 1) {
            throw new RuntimeException('El valor no es un data URI en base64.');
        }

        return [strtolower($partes[1]), $partes[2]];
    }

    /**
     * La misma regla que el CHECK de las tres columnas. Se comprueba acá además
     * de en la base porque el error de PostgreSQL no diría qué archivo era.
     */
    private static function comprobar(string $ruta): void
    {
        if (! RutaDeArchivo::esValida($ruta)) {
            throw new RuntimeException("La ruta «{$ruta}» no pasa el CHECK de la columna.");
        }
    }

    private static function disco(): string
    {
        return (string) config('etl.disco');
    }
}
