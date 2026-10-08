<?php

namespace App\Http\Resources\Api\CadeteApp\V1;

use App\Models\Pedido;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pedido, con la forma que la app del cadete espera.
 *
 * ⚠️ El contrato está en `src/app/interfaces/interfaces.ts` de la app. Valen las mismas tres
 * decisiones que `CadeteDeLaApp`: `_id` en lugar de `id`, los ids como texto, y las fechas
 * con el formato que la app imprime crudo en pantalla.
 *
 * ⚠️ **Los montos salen sin los ceros de más.** La app hace `$ {{ pedido.valor }}`, así que
 * «3000.00» aparecería tal cual en el teléfono donde antes decía «3000». Ver
 * `Pedido::montoParaMostrar()`.
 *
 * ⚠️ **El cliente y el cadete viajan sólo cuando se piden**, y el usuario que cargó el
 * pedido no viaja nunca. En el sistema viejo el modelo tiene `$with = ['user','cliente']`,
 * o sea que **en cada consulta de pedidos, en cualquier pantalla**, van embebidos el
 * comercio entero y la cuenta de quien lo cargó. La app ni siquiera declara un `user` en su
 * interfaz: lo recibe y lo tira. Son los datos de un comercio y los de un empleado viajando
 * a un teléfono que no los mira.
 *
 * @mixin Pedido
 */
class PedidoDeLaApp extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            '_id' => (string) $this->id,
            'numero' => $this->numero,
            'cliente_id' => $this->cliente_id === null ? null : (string) $this->cliente_id,
            'nombre_cliente' => $this->nombre_cliente,
            'direccion' => $this->direccion,
            'destino' => $this->destino,
            'responsable' => $this->responsable,
            'telefono' => $this->telefono,
            'retorno_origen' => $this->retorno_origen,
            'tipo_paquete' => $this->tipo_paquete,
            'peso_paquete' => $this->peso_paquete,
            'detalle' => $this->detalle,
            'garantia' => Pedido::montoParaMostrar($this->garantia),
            'valor' => Pedido::montoParaMostrar($this->valor),
            'estado' => $this->estado,
            'cadete_id' => $this->cadete_id === null ? null : (string) $this->cadete_id,
            'user_id' => $this->user_id === null ? null : (string) $this->user_id,
            'plataforma_origen' => $this->plataforma_origen,
            'created_at' => Momento::enApi($this->created_at),
            'updated_at' => Momento::enApi($this->updated_at),
            // La pantalla de detalle lee `pedido.cliente.nombre` y el móvil del cadete; el
            // listado no, y por eso el listado no los pide.
            'cliente' => $this->whenLoaded('cliente', fn () => new ClienteDeLaApp($this->cliente)),
            'cadete' => $this->whenLoaded('cadete', fn () => new CadeteDeLaApp($this->cadete)),
        ];
    }
}
