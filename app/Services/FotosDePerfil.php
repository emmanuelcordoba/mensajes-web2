<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFoto;
use App\Support\ImagenEnDataUrl;
use App\Support\RutaDeArchivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La foto de perfil del cadete: dónde vive y cómo se muestra.
 *
 * ⚠️ **Es un archivo y la tabla guarda su ruta** (PERF-2). En el sistema viejo el campo
 * `users.foto` guarda el base64 entero adentro del documento: **1.129 MB en MongoDB**, que
 * viajaban completos en cada login y en cada `/getperfil`. Migradas a disco son 308 MB de
 * JPEG, y lo que viaja ahora es un enlace.
 *
 * ⚠️ **La URL tiene que llevar su propia autorización.** La app hace
 * `<img [src]="usuario.foto">`, y un `<img>` no manda el token del cadete: una ruta detrás
 * de `auth:sanctum` devolvería 401 y la app mostraría el avatar por defecto sin decir por
 * qué. Por eso es una URL firmada, igual que las imágenes del carrusel. Ver
 * `ImagenesDeLaApp`, que resuelve lo mismo para el panel.
 *
 * ⚠️ **Al guardar, la app devuelve el campo tal como lo recibió.** `perfil-editar` hace
 * `this.usuario.foto = this.foto` y manda el objeto entero, así que si el cadete no cambió
 * la foto, lo que llega es la URL que emitimos nosotros. Sólo es una foto nueva cuando
 * llega un `data:image/...;base64,...`, que es lo que produce el recortador de la app.
 *
 * ⚠️ **Interpretar ese `data:` no vive acá**: es `App\Support\ImagenEnDataUrl`, compartido
 * con las cuatro imágenes de una postulación, que llegan igual. Lo único propio es cuánto
 * puede pesar.
 */
class FotosDePerfil
{
    /** La carpeta que escribió el ETL, y donde siguen entrando las nuevas. */
    public const CARPETA = 'user-fotos';

    /** Cuánto vale una URL firmada. Alcanza para mirar una pantalla. */
    public const MINUTOS_DE_LA_URL = 30;

    /** Lo más grande que se acepta, ya decodificado: la app manda 500x500. */
    public const BYTES_MAXIMOS = 2 * 1024 * 1024;

    /**
     * La URL con la que se puede ver una foto, o null si no hay.
     *
     * Null en vez de una URL rota: la app hace `usuario.foto || 'assets/images/avatar.png'`,
     * así que con null muestra su avatar por omisión, que es exactamente lo que corresponde.
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

    /** Si lo que mandó la app es una foto nueva y no la URL que le dimos. */
    public static function esFotoNueva(mixed $valor): bool
    {
        return ImagenEnDataUrl::esUnaImagen($valor);
    }

    /**
     * Qué tiene de malo la foto que llegó, o null si se puede guardar.
     *
     * ⚠️ **Es la misma pregunta que contesta `guardar()`, hecha antes.** La validación del
     * perfil la usa para que un `data:` roto sea un 422 con el motivo en la pantalla del
     * cadete y no un 500: `interpretar()` frena con una excepción, que es lo correcto para
     * el que escribe —si llegó ahí, ya está validado— y lo inútil para el que valida. Una
     * regla, dos lectores; hay un test que los compara.
     *
     * Lo que no es una foto nueva no se revisa: es la URL que emitimos nosotros, que la app
     * devuelve tal como la recibió, y se ignora al guardar.
     */
    public static function porQueNoSirve(mixed $valor): ?string
    {
        return ImagenEnDataUrl::porQueNoSirve($valor, self::BYTES_MAXIMOS);
    }

    /**
     * Guarda la foto que mandó la app y deja la fila apuntando al archivo nuevo.
     *
     * ⚠️ El nombre lo pone el servidor. Un nombre que venga del cliente es por donde se
     * escribe fuera de la carpeta, y el CHECK de la columna —`RutaDeArchivo`— está para que
     * una ruta así no entre ni por error.
     *
     * ⚠️ El archivo viejo se borra DESPUÉS de que la fila apunte al nuevo. Al revés, si la
     * escritura falla, queda una fila apuntando a un archivo que ya no está.
     */
    public function guardar(User $usuario, string $dataUrl): UserFoto
    {
        [$extension, $contenido] = ImagenEnDataUrl::interpretar($dataUrl, self::BYTES_MAXIMOS);

        $ruta = self::CARPETA.'/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk(self::disco())->put($ruta, $contenido);

        $anterior = $usuario->foto?->ruta_archivo;

        $foto = DB::transaction(fn (): UserFoto => UserFoto::query()->updateOrCreate(
            ['user_id' => $usuario->id],
            ['ruta_archivo' => $ruta],
        ));

        if ($anterior !== null && $anterior !== $ruta) {
            Storage::disk(self::disco())->delete($anterior);
        }

        return $foto;
    }

    /**
     * El disco donde viven.
     *
     * No se nombra uno acá: lo elige `config/filesystems.php`, igual que las imágenes del
     * panel y que el ETL. En desarrollo es el local —que sirve URLs firmadas porque está
     * con `serve => true`— y en producción el bucket.
     */
    public static function disco(): string
    {
        return (string) config('filesystems.default');
    }
}
