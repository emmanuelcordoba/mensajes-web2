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
 *
 * ## El nombre del archivo sale del ObjectId viejo, no del id nuevo
 *
 * Es deliberado, y por dos razones distintas:
 *
 * - **Permite subir los archivos antes del corte.** El id nuevo no existe hasta
 *   que corre la carga, así que con nombres derivados de él no se puede subir
 *   nada por adelantado y los 2,2 GB caen dentro de la ventana. Con el ObjectId
 *   las rutas se conocen de antemano: se suben días antes y en la ventana sólo
 *   se sincroniza lo que cambió —medido sobre el origen: 68 archivos y 31 MB con
 *   una semana de anticipación, contra 4.509 y 2,2 GB—.
 * - **Un ObjectId es único para siempre; un id de secuencia, no.** `--reiniciar`
 *   reinicia las secuencias, así que con el id nuevo un archivo de una corrida
 *   anterior podía quedar apareado con una fila que no era la suya.
 *
 * Para `postulacion_documentos` hay un premio extra: el sistema viejo ya guarda
 * sus archivos en `postulaciones/{oid}/{tipo}.jpg` —verificado, el oid es el
 * `_id` en los 583 casos—, así que la ruta nueva es la misma cambiando el
 * prefijo y subirlos es un `sync` de un directorio a otro.
 *
 * ⚠️ **`ruta_archivo` es una ruta opaca.** Nada debe deducir nada de su forma:
 * las filas que escriba la aplicación después del corte no van a tener un
 * ObjectId y usarán otro nombre. El `CHECK` de la columna valida la forma de una
 * ruta, no de qué salió.
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

        if ($puntero === false) {
            throw new RuntimeException("No se pudo abrir «{$origen}» para leerlo.");
        }

        try {
            Storage::disk(self::disco())->put($ruta, $puntero);
        } finally {
            fclose($puntero);
        }

        return $ruta;
    }

    /**
     * Borra lo escrito. Va con `--reiniciar`, y sigue haciendo falta aunque los
     * nombres ya no dependan de la secuencia: si el origen dejó de tener una
     * imagen, su archivo queda sin fila, y si una imagen pasó de PNG a JPEG
     * quedan las dos —`{oid}.png` y `{oid}.jpg`— para una sola fila.
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

    /**
     * El disco de la CARGA, no el definitivo: la carga escribe local y después
     * `etl:archivos` sube. Ver config/etl.php.
     */
    private static function disco(): string
    {
        return (string) config('etl.disco_carga');
    }
}
