<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFoto;
use App\Support\RutaDeArchivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

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
 */
class FotosDePerfil
{
    /** La carpeta que escribió el ETL, y donde siguen entrando las nuevas. */
    public const CARPETA = 'user-fotos';

    /** Cuánto vale una URL firmada. Alcanza para mirar una pantalla. */
    public const MINUTOS_DE_LA_URL = 30;

    /** Lo más grande que se acepta, ya decodificado: la app manda 500x500. */
    public const BYTES_MAXIMOS = 2 * 1024 * 1024;

    /** Lo que el recortador de la app produce, y lo único que se acepta como foto nueva. */
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

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
        return is_string($valor) && str_starts_with($valor, 'data:');
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
        [$extension, $contenido] = self::interpretar($dataUrl);

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
     * Separa el tipo y los bytes de un `data:` URL, o frena diciendo qué tiene de malo.
     *
     * @return array{string, string} La extensión y el contenido.
     */
    protected static function interpretar(string $dataUrl): array
    {
        if (preg_match('#^data:([a-z/+-]+);base64,(.+)$#is', $dataUrl, $partes) !== 1) {
            throw new RuntimeException('La foto no viene en el formato esperado.');
        }

        $tipo = strtolower($partes[1]);

        if (! array_key_exists($tipo, self::TIPOS)) {
            throw new RuntimeException('Ese tipo de imagen no se acepta: '.$tipo.'.');
        }

        // ⚠️ `strict` en true: sin eso, base64_decode se come los caracteres inválidos y
        // guarda un archivo corrupto en lugar de avisar.
        $contenido = base64_decode($partes[2], true);

        if ($contenido === false || $contenido === '') {
            throw new RuntimeException('La foto no se pudo leer.');
        }

        if (strlen($contenido) > self::BYTES_MAXIMOS) {
            throw new RuntimeException('La foto es demasiado grande.');
        }

        return [self::TIPOS[$tipo], $contenido];
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
