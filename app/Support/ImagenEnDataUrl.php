<?php

namespace App\Support;

use RuntimeException;

/**
 * Una imagen que llegó como `data:image/jpeg;base64,…`, que es cómo mandan las fotos las dos
 * apps: el recortador del perfil y la cámara de la postulación producen eso.
 *
 * ⚠️ **Está acá porque son dos lectores de una regla.** La foto de perfil y las cuatro
 * imágenes de una postulación se interpretan igual —mismo formato, mismos tipos aceptados,
 * mismo cuidado con el base64— y lo único que cambia es cuánto pesa cada una. Tenerlo en dos
 * servicios era tenerlo dos veces: el día que haya que aceptar un tipo nuevo, o que aparezca
 * una forma de `data:` que el regex no cubre, se arregla en uno y el otro queda roto.
 *
 * ⚠️ **Y pregunta antes de escribir.** `porQueNoSirve()` contesta lo mismo que
 * `interpretar()` decide, pero devolviendo el motivo en vez de frenar: la validación lo usa
 * para que una imagen rota sea un 422 con el motivo en la pantalla y no un 500. Una regla,
 * dos lectores, con un test que los compara.
 */
final class ImagenEnDataUrl
{
    /** Lo que producen las apps, y lo único que se acepta. */
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Si lo que llegó es una imagen nueva y no una URL que emitimos nosotros. */
    public static function esUnaImagen(mixed $valor): bool
    {
        return is_string($valor) && str_starts_with($valor, 'data:');
    }

    /**
     * Qué tiene de malo, o null si se puede guardar.
     *
     * Lo que no es un `data:` no se revisa: es la URL firmada que la app devuelve tal como
     * la recibió, y el que escribe la ignora.
     */
    public static function porQueNoSirve(mixed $valor, int $bytesMaximos): ?string
    {
        if (! self::esUnaImagen($valor)) {
            return null;
        }

        try {
            self::interpretar((string) $valor, $bytesMaximos);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Separa la extensión y los bytes, o frena diciendo qué tiene de malo.
     *
     * @return array{string, string} La extensión y el contenido.
     */
    public static function interpretar(string $dataUrl, int $bytesMaximos): array
    {
        if (preg_match('#^data:([a-z/+-]+);base64,(.+)$#is', $dataUrl, $partes) !== 1) {
            throw new RuntimeException('La imagen no viene en el formato esperado.');
        }

        $tipo = strtolower($partes[1]);

        if (! array_key_exists($tipo, self::TIPOS)) {
            throw new RuntimeException('Ese tipo de imagen no se acepta: '.$tipo.'.');
        }

        // ⚠️ `strict` en true: sin eso, base64_decode se come los caracteres inválidos y
        // guarda un archivo corrupto en lugar de avisar.
        $contenido = base64_decode($partes[2], true);

        if ($contenido === false || $contenido === '') {
            throw new RuntimeException('La imagen no se pudo leer.');
        }

        if (strlen($contenido) > $bytesMaximos) {
            throw new RuntimeException(
                'La imagen es demasiado grande: pesa '.self::enMegas(strlen($contenido))
                .' y el máximo es '.self::enMegas($bytesMaximos).'.'
            );
        }

        return [self::TIPOS[$tipo], $contenido];
    }

    /** Para que el motivo diga un número que una persona entienda. */
    public static function enMegas(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
    }
}
