<?php

namespace App\Http\Resources\Api\CadeteApp\V1;

use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Support\Momento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un movimiento del saldo, con la forma que la app espera.
 *
 * La app lo dibuja así: el `tipo` arriba, el `created_at` abajo en chico, y el `monto` a la
 * derecha en verde o en rojo según `monto_positivo`, con un «- » adelante cuando resta.
 *
 * ⚠️ **El signo no va en `monto`**: va en `monto_positivo`, y el «- » lo escribe la
 * plantilla. Mandar el monto negativo pondría dos signos.
 *
 * ⚠️ **`fecha` NO se emite, y la app lo declara.** `MovimientoCobranza.fecha` nunca existió:
 * la tabla no tiene esa columna y el sistema viejo devuelve el modelo entero, así que a la
 * app le llega `undefined` desde el primer día. No lo lee en ninguna parte —la plantilla usa
 * `created_at`—. Inventarlo ahora sería agregar al contrato un campo que nadie pidió.
 *
 * ⚠️ **`user_id` tampoco.** El sistema viejo lo manda —es quién en la oficina cargó el
 * saldo— y la app no lo declara ni lo lee. No es asunto del teléfono del cadete. Es lo mismo
 * de SEC-14.
 *
 * ⚠️ **`saldo_parcial` es el saldo ANTES del movimiento**, y puede venir null: son 44
 * movimientos, todos «Carga de saldo» y todos el PRIMERO de su cadete, porque el sistema
 * viejo escribe `'saldo_parcial' => $cadete->cobranza_saldo` y ese campo todavía estaba en
 * null. El más nuevo es del 2026-09-21, así que no es sólo cosa de los migrados. La app lo
 * declara y no lo lee, pero se emite porque el sistema viejo lo emitía y es el saldo del
 * propio cadete.
 *
 * @mixin MovimientoCobranzaSaldo
 */
class MovimientoDeLaApp extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $peticion): array
    {
        return [
            'tipo' => $this->tipo,
            // Texto y sin los dos ceros de atrás: la app lo imprime tal cual —`$ {{
            // movimiento.monto }}`—. Ver `Pedido::montoParaMostrar`.
            'monto' => Pedido::montoParaMostrar($this->monto),
            'monto_positivo' => $this->monto_positivo,
            'saldo_parcial' => Pedido::montoParaMostrar($this->saldo_parcial),
            // ⚠️ Acá la app SÍ lo pasa por un pipe —`| date: 'd/M H:mm'`—, al revés que en
            // el chat, que lo imprime crudo. El formato es el mismo porque es el que la app
            // publicada viene leyendo: ver `Momento::enApi`.
            'created_at' => Momento::enApi($this->created_at),
        ];
    }
}
