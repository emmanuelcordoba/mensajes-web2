<?php

namespace App\Jobs;

use App\Models\Cadete;
use App\Models\Pedido;
use App\Services\NotificacionesAlTelefono;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Una hora después de aceptar un pedido, si sigue en curso, se le avisa al cadete.
 *
 * Lo encola `PedidoDelCadete::aceptar()`. Es el aviso previo al bloqueo: el cadete tiene un
 * pedido tomado y no lo finalizó, así que o se olvidó de cerrarlo en la app o algo pasó.
 *
 * ⚠️ **Comprueba las dos cosas que lo hacen inofensivo**: que el pedido siga En curso y que
 * siga siendo de ESE cadete. Lo segundo importa porque entre el encolado y la hora el pedido
 * pudo reasignarse, y avisarle al que ya no lo tiene es ruido; `SerializesModels` relee los
 * dos modelos de la base al desencolar, así que lo que se compara está fresco.
 *
 * ⚠️ **El bloqueo automático de las dos horas NO se porta, porque allá está comentado.** En
 * `Api\v1\PedidoController@aceptar` la línea de `BloqueoCadetePedidoEnCurso` está anulada,
 * así que hoy ese bloqueo no corre para nadie y el aviso amenaza con algo que no pasa. Se
 * replica el comportamiento, no la intención: si alguna vez se quiere el bloqueo, es una
 * decisión nueva y no un olvido de esta portación.
 */
class AdvertenciaPedidoEnCurso implements ShouldQueue
{
    use Queueable;

    public function __construct(public Pedido $pedido, public Cadete $cadete) {}

    public function handle(): void
    {
        if ($this->pedido->estado !== Pedido::ESTADO_EN_CURSO) {
            return;
        }

        if ($this->pedido->cadete_id !== $this->cadete->id) {
            return;
        }

        NotificacionesAlTelefono::pedidoSinFinalizar($this->cadete->fcm_token);
    }
}
