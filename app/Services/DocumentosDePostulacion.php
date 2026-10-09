<?php

namespace App\Services;

use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Support\ImagenEnDataUrl;
use App\Support\RutaDeArchivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Las cuatro imágenes de una postulación: dónde viven y cómo se muestran.
 *
 * ⚠️ Dos de las cuatro son el DNI de la persona, de frente y de dorso, y **no están en
 * ningún otro respaldo** (DATA-9). Por eso el disco es privado, por eso nada de esto vive
 * en `public/`, y por eso acá no hay ningún método que borre un archivo.
 *
 * ⚠️ En el sistema viejo eran cuatro columnas de la postulación con el base64 adentro, y
 * al contratar se escribían a disco y la columna pasaba a tener una ruta: la misma columna
 * guardaba dos cosas distintas según el momento. Acá son filas de `postulacion_documentos`
 * y siempre son una ruta (PERF-2).
 *
 * ⚠️ El panel viejo las mostraba rotas (PANEL-20): apuntaba al disco público y le faltaba
 * el prefijo. Acá la URL la firma `temporaryUrl()` y dura lo que dura mirar una pantalla.
 *
 * La firma y el disco se piden prestados a `ImagenesDeLaApp`, que es donde ya estaban: es
 * el mismo disco privado y la misma regla. Su nombre quedó más angosto que lo que hace.
 */
class DocumentosDePostulacion
{
    /** La carpeta, la misma que escribió el ETL. Ver App\Etl\Archivos. */
    public const CARPETA = 'postulacion-documentos';

    /**
     * Lo más que puede pesar una de las cuatro, ya decodificada.
     *
     * Medido sobre las 2.900 imágenes migradas —725 postulaciones completas, ninguna sin
     * archivo—: la mediana pesa 656 KB, el percentil 99 son 1,8 MB y la más grande de
     * todas 2,7 MB. La app las saca con `width: 2000, quality: 90`, así que son grandes a
     * propósito: dos de las cuatro son un DNI que alguien tiene que poder leer.
     *
     * 4 MB deja afuera el abuso y no toca nada de lo real. Y ojo con el total: las cuatro
     * juntas en un solo JSON son ~3,6 MB en la mediana y pueden pasar los 12 MB en el peor
     * caso, así que `post_max_size` del servidor tiene que darles lugar o PHP descarta el
     * cuerpo entero antes de que Laravel lo vea.
     */
    public const BYTES_MAXIMOS = 4 * 1024 * 1024;

    /**
     * Los tres que hacen falta para contratar.
     *
     * La foto de perfil no está: el alta la pide, pero no tenerla no es motivo para no
     * contratar a alguien. Es el mismo recorte que hacía el panel viejo.
     */
    public const PARA_CONTRATAR = [
        PostulacionDocumento::TIPO_DNI_FRENTE,
        PostulacionDocumento::TIPO_DNI_DORSO,
        PostulacionDocumento::TIPO_BOLETA_DE_SERVICIO,
    ];

    /**
     * Una URL firmada por cada tipo, con null donde no hay archivo.
     *
     * Devuelve los cuatro tipos siempre, estén o no: la pantalla tiene que poder decir
     * «falta el dorso del DNI», y para eso la ausencia tiene que llegar.
     *
     * @return array<string, string|null>
     */
    public static function urls(Postulacion $postulacion): array
    {
        $porTipo = $postulacion->documentos->keyBy('tipo');

        $urls = [];

        foreach (PostulacionDocumento::TIPOS as $tipo) {
            $urls[$tipo] = ImagenesDeLaApp::url($porTipo->get($tipo)?->ruta_archivo);
        }

        return $urls;
    }

    /**
     * Los que hacen falta para contratar y no están.
     *
     * Mira el archivo y no sólo la fila: una fila que apunta a un archivo que no se subió
     * es exactamente el caso que el panel viejo no distinguía, porque la columna tenía
     * base64 o una ruta según el momento y comprobaba `strpos($valor, 'data:') === 0`.
     *
     * @return list<string>
     */
    public static function faltanParaContratar(Postulacion $postulacion): array
    {
        $porTipo = $postulacion->documentos->keyBy('tipo');
        $disco = Storage::disk(ImagenesDeLaApp::disco());

        $faltan = [];

        foreach (self::PARA_CONTRATAR as $tipo) {
            $ruta = $porTipo->get($tipo)?->ruta_archivo;

            if ($ruta === null || ! $disco->exists($ruta)) {
                $faltan[] = $tipo;
            }
        }

        return $faltan;
    }

    /** Qué tiene de malo una de las imágenes que mandó la app, o null si sirve. */
    public static function porQueNoSirve(mixed $valor): ?string
    {
        return ImagenEnDataUrl::porQueNoSirve($valor, self::BYTES_MAXIMOS);
    }

    /**
     * Guarda una imagen que llegó como `data:`, que es cómo las manda la app.
     *
     * Es el camino de la postulación desde el teléfono; `guardar()` es el de un archivo
     * subido desde el panel. Los dos terminan en una fila con una ruta.
     *
     * ⚠️ El nombre lo pone el servidor, igual que en el otro camino, y por lo mismo.
     */
    public static function guardarDataUrl(Postulacion $postulacion, string $tipo, string $dataUrl): PostulacionDocumento
    {
        if (! in_array($tipo, PostulacionDocumento::TIPOS, true)) {
            throw new RuntimeException("«{$tipo}» no es uno de los cuatro documentos.");
        }

        [$extension, $contenido] = ImagenEnDataUrl::interpretar($dataUrl, self::BYTES_MAXIMOS);

        $carpeta = self::CARPETA.'/'.Str::ulid()->toString();
        $ruta = $carpeta.'/'.$tipo.'.'.$extension;

        if (! RutaDeArchivo::esValida($ruta)) {
            throw new RuntimeException("La ruta «{$ruta}» no cumple la forma que exige la columna.");
        }

        Storage::disk(ImagenesDeLaApp::disco())->put($ruta, $contenido);

        return PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'tipo' => $tipo,
            'ruta_archivo' => $ruta,
        ]);
    }

    /**
     * Guarda una imagen subida y deja escrita su fila.
     *
     * ⚠️ El nombre del archivo lo pone el servidor y no quien sube: un nombre que viene
     * del cliente es por donde se escribe fuera de la carpeta. La carpeta de cada
     * postulación es un ULID y no su id, para que la ruta no diga a quién pertenece ni se
     * pueda tantear de a una.
     *
     * ⚠️ La ruta del ETL lleva adentro el ObjectId viejo; las que escribe la aplicación no
     * tienen ninguno, y está bien: el CHECK de la columna valida la forma de una ruta
     * relativa segura, no de dónde salió.
     */
    public static function guardar(Postulacion $postulacion, string $tipo, UploadedFile $imagen): PostulacionDocumento
    {
        if (! in_array($tipo, PostulacionDocumento::TIPOS, true)) {
            throw new RuntimeException("«{$tipo}» no es uno de los cuatro documentos.");
        }

        $extension = strtolower($imagen->extension() ?: 'jpg');
        $carpeta = self::CARPETA.'/'.Str::ulid()->toString();
        $ruta = $carpeta.'/'.$tipo.'.'.$extension;

        if (! RutaDeArchivo::esValida($ruta)) {
            // No puede pasar —el ULID, el tipo y la extensión son alfanuméricos—, pero la
            // columna lo exige y acá el error se puede leer.
            throw new RuntimeException("La ruta «{$ruta}» no cumple la forma que exige la columna.");
        }

        Storage::disk(ImagenesDeLaApp::disco())->putFileAs($carpeta, $imagen, basename($ruta));

        return PostulacionDocumento::query()->create([
            'postulacion_id' => $postulacion->id,
            'tipo' => $tipo,
            'ruta_archivo' => $ruta,
        ]);
    }
}
