<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use App\Services\FotosDePerfil;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La cuenta del cadete, con la forma que la app espera.
 *
 * ⚠️ **Lista explícita, y el sistema viejo mandaba el modelo entero.** El login de allá
 * contesta `'user' => $user`, o sea todas las columnas: `rol_id`, `bloqueado_mensaje`,
 * `codigo_de_verificacion`, `cliente_restringido_id`, `deleted_at`. Es la misma forma de
 * SEC-14: el servidor manda todo lo que tiene y confía en que el cliente no mire.
 *
 * ⚠️ **`foto` es una URL firmada, no la imagen.** En el sistema viejo este campo guarda el
 * base64 completo adentro del documento del usuario —1.129 MB en MongoDB—, así que la foto
 * entera viajaba en cada login y en cada `/getperfil`. Acá viaja un enlace. La app hace
 * `<img [src]="usuario.foto">` sin mandar el token, por eso la URL tiene que llevar su
 * propia autorización. Ver `FotosDePerfil`.
 *
 * ⚠️ **`bloqueado` sale booleano**, que es como lo declara la app, y no el código del
 * motivo que guarda la columna. La app no lo lee en ninguna pantalla: se emite porque está
 * en el contrato, con el tipo del contrato y sin decir de más.
 *
 * @mixin User
 */
class UsuarioDeLaApp extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            '_id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'foto' => FotosDePerfil::url($this->foto?->ruta_archivo),
            'rol_id' => $this->rol_id === null ? null : (string) $this->rol_id,
            'bloqueado' => $this->estaBloqueado(),
            'created_at' => Momento::enApi($this->created_at),
            'updated_at' => Momento::enApi($this->updated_at),
            // El cadete va adentro, como en el sistema viejo: la app lee
            // `usuario.cadete.apellidos` en la pantalla de editar perfil.
            'cadete' => $this->whenLoaded(
                'cadete',
                fn () => new CadeteDeLaApp($this->cadete),
            ),
        ];
    }
}
