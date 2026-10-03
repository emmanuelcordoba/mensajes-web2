<?php

namespace App\Services;

use App\Support\RutaDeArchivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dónde viven las imágenes que el panel sube, y cómo se muestran.
 *
 * ⚠️ Las tres tablas de imágenes guardan **la ruta de un archivo, no la imagen**
 * (PERF-2). En el sistema viejo el base64 estaba en el documento, así que la publicidad
 * entera viajaba en el `get-setup` de la app sin que nadie lo pidiera.
 *
 * ⚠️ Y el disco es **privado**. Para mostrarlas hace falta una URL firmada, que es la que
 * da `temporaryUrl()`: Laravel registra la ruta que las sirve porque el disco tiene
 * `serve => true`, y `ServeFile` exige firma válida cuando la visibilidad es privada.
 * Eso importa más allá de la publicidad: en el mismo disco están los documentos de
 * identidad de las postulaciones (DATA-9), que no están en ningún otro respaldo.
 */
class ImagenesDeLaApp
{
    /** La carpeta de las publicidades, la misma que escribió el ETL. */
    public const CARPETA_PUBLICIDADES = 'publicidad-app-imagenes';

    /** Cuánto vale una URL firmada. Alcanza para mirar una pantalla. */
    public const MINUTOS_DE_LA_URL = 30;

    /**
     * La URL con la que se puede ver una imagen, o null si el archivo no está.
     *
     * Devuelve null en vez de una URL rota: hay filas cuyo archivo puede no haberse
     * subido todavía —el ETL sube en dos tiempos— y una imagen rota no dice nada,
     * mientras que la ausencia se puede mostrar.
     */
    public static function url(?string $ruta): ?string
    {
        if ($ruta === null || ! Storage::disk(self::disco())->exists($ruta)) {
            return null;
        }

        return Storage::disk(self::disco())->temporaryUrl(
            $ruta,
            now()->addMinutes(self::MINUTOS_DE_LA_URL),
        );
    }

    /**
     * Guarda una imagen subida y devuelve su ruta.
     *
     * ⚠️ El nombre lo pone el servidor, no quien sube. Un nombre de archivo que viene del
     * cliente es por donde se escribe fuera de la carpeta, y el CHECK de la columna
     * —`RutaDeArchivo`— está justamente para que una ruta así no entre ni por error.
     */
    public static function guardarPublicidad(UploadedFile $imagen): string
    {
        $extension = strtolower($imagen->extension() ?: 'png');
        $ruta = self::CARPETA_PUBLICIDADES.'/'.Str::ulid()->toString().'.'.$extension;

        if (! RutaDeArchivo::esValida($ruta)) {
            // No puede pasar —el ULID y la extensión son alfanuméricos—, pero la columna
            // lo exige y acá el error se puede leer.
            throw new RuntimeException("La ruta «{$ruta}» no cumple la forma que exige la columna.");
        }

        Storage::disk(self::disco())->putFileAs(
            self::CARPETA_PUBLICIDADES,
            $imagen,
            basename($ruta),
        );

        return $ruta;
    }

    /**
     * El disco donde viven.
     *
     * ⚠️ No se nombra uno acá: lo elige `config/filesystems.php`, igual que en el ETL.
     * En desarrollo es el local y en producción va a ser el bucket, y el código que las
     * lee no tiene por qué enterarse.
     */
    public static function disco(): string
    {
        return (string) config('filesystems.default');
    }
}
