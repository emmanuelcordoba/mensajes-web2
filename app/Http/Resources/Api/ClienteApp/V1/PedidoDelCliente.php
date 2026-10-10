<?php

namespace App\Http\Resources\Api\ClienteApp\V1;

use App\Models\Pedido;
use App\Services\FotosDePerfil;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pedido como lo leen las pantallas del cliente.
 *
 * ⚠️ **Los campos salen de leer las plantillas, porque esta app no declara tipos.** Esto es
 * el contrato medido:
 *
 *     el listado        numero, created_at, estado, valor        (listado.page.html:13-24)
 *     el detalle        + direccion, destino, garantia, telefono, detalle
 *                                                                (pedido.page.html:7-85)
 *     el cadete         nombres, apellidos, numero_movil, user.foto, y SÓLO cuando el
 *                       pedido está «En curso»                   (pedido.page.html:87-99)
 *
 * ⚠️ **`estado` viaja con la palabra en castellano** —«Sin asignar», «Cotizado», «En curso»—
 * porque las dos pantallas la comparan con un `===` contra esos literales, que están
 * escritos en los teléfonos. No es un código interno que se pueda renombrar.
 *
 * ⚠️ **Del cadete salen cuatro campos y el sistema viejo mandaba el cadete entero**: DNI,
 * teléfono, dirección, saldo, deuda y el token de notificaciones, más su cuenta con el email.
 * Allá ya se había recortado con `setVisible()`, que es la lista correcta aplicada en el
 * lugar frágil: cualquier consulta que no pase por ese método vuelve a mandar todo. Acá la
 * lista es la forma de la respuesta. Ver SEC-14.
 *
 * ⚠️ **Y el cadete sólo viaja cuando el pedido está en curso**, que es lo único que la
 * pantalla dibuja. Mientras está asignado y sin confirmar, quién lo tiene no es asunto del
 * cliente.
 *
 * ⚠️ **`user` no se emite.** Es quién cargó el pedido, y si lo cargó el panel es un empleado
 * con su email. La app no lo lee.
 *
 * @mixin Pedido
 */
class PedidoDelCliente extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            '_id' => (string) $this->id,
            'numero' => $this->numero,
            'estado' => $this->estado,
            'direccion' => $this->direccion,
            'destino' => $this->destino,
            'telefono' => $this->telefono,
            'detalle' => $this->detalle,
            // Texto y sin los ceros de atrás: las dos pantallas lo imprimen tal cual.
            'valor' => Pedido::montoParaMostrar($this->valor),
            'garantia' => Pedido::montoParaMostrar($this->garantia),
            'created_at' => Momento::enApi($this->created_at),
            'updated_at' => Momento::enApi($this->updated_at),
            'cadete' => $this->elCadete(),
        ];
    }

    /**
     * El cadete, sólo si el pedido está en curso.
     *
     * @return array<string, mixed>|null
     */
    protected function elCadete(): ?array
    {
        if ($this->resource->estado !== Pedido::ESTADO_EN_CURSO) {
            return null;
        }

        // ⚠️ `Pedido::$cadete` es nullable porque `Cadete` usa SoftDeletes: con el cadete
        // dado de baja la relación no lo devuelve, y el sistema viejo hacía
        // `$pedido->cadete->user` sin mirar.
        $cadete = $this->resource->cadete;

        if ($cadete === null) {
            return null;
        }

        return [
            '_id' => (string) $cadete->id,
            'numero_movil' => $cadete->numero_movil,
            'nombres' => $cadete->nombres,
            'apellidos' => $cadete->apellidos,
            'user' => [
                '_id' => (string) $cadete->user_id,
                // La plantilla hace `<img [src]="pedido.cadete.user.foto">`, así que acá va
                // una URL firmada y no el base64 que guardaba la columna vieja.
                'foto' => FotosDePerfil::url($cadete->user?->foto?->ruta_archivo),
            ],
        ];
    }
}
