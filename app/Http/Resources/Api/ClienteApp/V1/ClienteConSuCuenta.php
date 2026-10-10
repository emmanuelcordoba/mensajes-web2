<?php

namespace App\Http\Resources\Api\ClienteApp\V1;

use App\Models\Cliente;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El cliente que la app guarda al entrar, con su cuenta adentro.
 *
 * ⚠️ **El nombre es largo porque dice lo que trae.** `ClienteDeLaApp` ya existe en
 * `Api\CadeteApp\V1` y es otra cosa: el comercio **visto por el cadete**, con los dos campos
 * que necesita para llamar por teléfono. Éste es el cliente **visto por sí mismo**, con su
 * cuenta anidada.
 *
 * ⚠️ **Los campos salen de leer las pantallas, no de una interfaz.** Esta app declara una
 * sola interfaz en todo `interfaces.ts`, así que esto es el contrato medido:
 *
 *     _id         registercliente.page.ts:91, para el segundo paso del registro
 *     numero      perfil.page.html:7
 *     nombres     perfil.page.html:11 y editar.page.ts:52
 *     apellidos   perfil.page.html:15 y editar.page.ts:51
 *     direccion   perfil.page.html:19 y editar.page.ts:53
 *     telefono    perfil.page.html:23, editar.page.ts:54 y alta.page.ts:101
 *     user.email  perfil.page.html:27 y editar.page.ts:55
 *
 * ⚠️ **`nombre` y `nombre_empleado` NO salen.** Son los que escribe el panel, y
 * `nombre_mostrado` es lo que lee el resto del sistema; la app muestra `nombres` y
 * `apellidos`, que son los suyos. El sistema viejo manda el modelo entero, con
 * `fcm_token` incluido: el token de notificaciones del propio teléfono no tiene nada que
 * hacer de vuelta en el teléfono.
 *
 * @mixin Cliente
 */
class ClienteConSuCuenta extends JsonResource
{
    /**
     * El token recién emitido, cuando corresponde.
     *
     * ⚠️ Sólo lo lleva la respuesta de `verificar`, porque la app lo lee de
     * `res.cliente.user.api_token` —`verify.page.ts:62`—. En el login viaja arriba, en
     * `api_token`, y no acá. Son dos pantallas que lo buscan en dos lugares distintos, y las
     * dos están publicadas.
     */
    private ?string $token = null;

    public function conElToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            // `_id` y no `id`: era el de MongoDB y así lo lee la app.
            '_id' => (string) $this->id,
            'numero' => $this->numero,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'direccion' => $this->direccion,
            'telefono' => $this->telefono,
            'created_at' => Momento::enApi($this->created_at),
            'updated_at' => Momento::enApi($this->updated_at),
            'user' => $this->cuenta(),
        ];
    }

    /**
     * La cuenta, con lo mínimo.
     *
     * ⚠️ El sistema viejo manda el modelo entero: `rol_id`, `bloqueado`,
     * `codigo_de_verificacion` cuando está visible, `deleted_at`. Es la misma forma de
     * SEC-14.
     *
     * @return array<string, mixed>|null
     */
    protected function cuenta(): ?array
    {
        $cuenta = $this->resource->user;

        if ($cuenta === null) {
            // Un cliente sin cuenta no entra por acá —hace falta un token para llegar—,
            // pero la relación es nullable y la plantilla hace `cliente.user.email`.
            return null;
        }

        $datos = [
            '_id' => (string) $cuenta->id,
            'name' => $cuenta->name,
            'email' => $cuenta->email,
            'email_verified_at' => Momento::enApi($cuenta->email_verified_at),
        ];

        if ($this->token !== null) {
            $datos['api_token'] = $this->token;
        }

        return $datos;
    }
}
