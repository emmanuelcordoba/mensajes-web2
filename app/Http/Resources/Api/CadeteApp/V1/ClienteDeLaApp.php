<?php

namespace App\Http\Resources\Api\CadeteApp\V1;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El comercio de un pedido, con lo poco que la app muestra.
 *
 * Son los cinco campos de `interface Cliente`: la app escribe
 * `pedido.cliente ? pedido.cliente.nombre : pedido.nombre_cliente` y nada más. El resto del
 * comercio —su cuenta de la app, su token de notificaciones— no tiene nada que hacer en el
 * teléfono de un cadete.
 *
 * ⚠️ `nombre` sale de `nombre_mostrado`, que es la columna generada que compone el nombre
 * cuando el cliente es una persona y tiene nombre y apellido por separado. El sistema viejo
 * emite `nombre` a secas, que en esas filas viene vacío: el cadete veía el pedido sin a
 * quién va.
 *
 * @mixin Cliente
 */
class ClienteDeLaApp extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            '_id' => (string) $this->id,
            'numero' => (string) $this->numero,
            'nombre' => $this->nombre_mostrado,
            'direccion' => $this->direccion,
            'telefono' => $this->telefono,
        ];
    }
}
