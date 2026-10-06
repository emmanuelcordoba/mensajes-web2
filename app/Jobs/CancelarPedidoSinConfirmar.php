<?php

namespace App\Jobs;

use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * El reloj del cadete de la app: cinco minutos para confirmar, o pierde el pedido.
 *
 * Lo encola `AsignacionDeCadete` cuando el pedido va a un cadete que está trabajando desde
 * la app. Si cuando vence sigue **Asignado** —o sea que el cadete no lo tomó— el pedido se
 * cancela, se le saca el cadete y vuelve a la lista de espera.
 *
 * ⚠️ **Si el pedido ya no está Asignado, no hace nada.** Ésa es toda la lógica: el cadete lo
 * aceptó y está En curso, o alguien lo canceló, o se lo reasignaron a otro. El trabajo se
 * ejecuta igual —no hay forma de cancelar uno encolado— y la comprobación es lo que lo hace
 * inofensivo.
 *
 * ⚠️ **Necesita un worker corriendo.** `QUEUE_CONNECTION=database`, así que sin un
 * `queue:work` el pedido se queda Asignado para siempre y el cadete lo retiene sin haberlo
 * confirmado. En el sistema viejo pasa lo mismo y es parte del despliegue, no del código.
 *
 * ⚠️ La notificación al cadete que perdió el pedido no se porta: no hay Firebase en el
 * sistema nuevo.
 */
class CancelarPedidoSinConfirmar implements ShouldQueue
{
    use Queueable;

    public function __construct(public Pedido $pedido) {}

    public function handle(): void
    {
        // Se relee de la base: entre que se encoló y ahora pasaron cinco minutos.
        $pedido = Pedido::find($this->pedido->id);

        if ($pedido === null || $pedido->estado !== Pedido::ESTADO_ASIGNADO) {
            return;
        }

        // El que lo tenía, para el registro: después de `dissociate()` el pedido ya no lo
        // sabe, y el log quiere decir quién perdió el pedido.
        $cadete = $pedido->cadete()->first();

        $pedido->estado = Pedido::ESTADO_CANCELADO;
        $pedido->cadete()->dissociate();
        $pedido->save();

        // ⚠️ Sin `user_id`: esto no lo hace nadie, lo hace el reloj. El método viejo sacaba
        // el usuario de `Auth`, que desde un trabajo encolado no existe, así que quedaba en
        // null sin que nadie lo decidiera. Acá queda en null porque se decide que quede.
        LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB, $cadete);
    }
}
