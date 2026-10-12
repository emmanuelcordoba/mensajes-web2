<?php

namespace App\Http\Resources\Api\UserApi\V1;

use App\Models\Pedido;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El pedido que la API devuelve al consumidor que lo creó.
 *
 * ⚠️ **Lista explícita, y el sistema viejo devolvía el modelo entero.** Acá no hay una app
 * publicada que imponga la forma —la API no tiene tráfico desde el 2021 (DEP-3)— así que lo
 * que viaja es lo que un consumidor necesita para seguir su pedido: qué número es, en qué
 * estado está, qué se va a entregar y dónde, y cuánto sale.
 *
 * ⚠️ **`cadete_id` y `user_id` no salen.** Quién lo lleva es un dato interno; el consumidor
 * tiene el número de pedido para preguntar por teléfono.
 *
 * @mixin Pedido
 */
class PedidoDeLaApi extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'estado' => $this->estado,
            'origen' => $this->nombre_cliente,
            'direccion_origen' => $this->direccion,
            'destino' => $this->responsable,
            'direccion_destino' => $this->destino,
            'destino_telefono' => $this->telefono,
            'aclaracion_adicional' => $this->detalle,
            'gastronomia' => $this->gastronomia,
            'retorno_origen' => $this->retorno_origen,
            'envia_dinero_monto' => Pedido::montoParaMostrar($this->garantia),
            // Null cuando el servicio de mapas no estaba: el pedido entró sin precio para que
            // lo cotice una persona.
            'costo' => Pedido::montoParaMostrar($this->valor),
            'created_at' => Momento::enApi($this->created_at),
        ];
    }
}
