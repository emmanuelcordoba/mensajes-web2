<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el cadete le hace a un pedido desde la app: aceptarlo, rechazarlo o finalizarlo.
 *
 * ⚠️ **Las tres son escrituras condicionadas, y ése es todo el punto.** Un teléfono repite:
 * se toca dos veces el botón, se corta la red y la app reintenta, llega el mismo pedido por
 * duplicado. Si el cambio de estado fuera «leer, decidir, escribir», dos finalizar del
 * mismo pedido le cobrarían el saldo dos veces al cadete (COB-1) y dos aceptar volverían a
 * programar avisos y a notificar al cliente (API-4).
 *
 * La forma es siempre la misma: un UPDATE con el estado esperado en el WHERE, y **sólo
 * sigue el que logra cambiar una fila**. El que llega segundo no modifica nada y recibe la
 * misma respuesta que el primero, porque desde el teléfono las dos pulsaciones son la misma
 * intención.
 *
 * ⚠️ **Las notificaciones push no se portan.** Acá el sistema viejo manda tres: al cliente
 * cuando su pedido sale, al cliente cuando llega, y un aviso programado al cadete que deja
 * un pedido en curso más de una hora (`AdvertenciaPedidoEnCurso`). No hay Firebase en el
 * sistema nuevo, y ese trabajo diferido no hace otra cosa que notificar, así que no tiene
 * nada que hacer todavía.
 */
class PedidoDelCadete
{
    /**
     * Si este pedido es de este cadete.
     *
     * ⚠️ Comparando enteros. En el sistema viejo esto era `$pedido->cadete_id !== $cadete->id`
     * entre un ObjectId y una cadena, y en `finalizar()` directamente `$pedido->cadete->id`,
     * que revienta con «Attempt to read property on null» cuando el pedido no tiene cadete.
     */
    public static function esDe(Pedido $pedido, Cadete $cadete): bool
    {
        return $pedido->cadete_id !== null && $pedido->cadete_id === $cadete->id;
    }

    /**
     * El cadete toma el pedido: pasa a estar En curso y el cadete queda con pedido.
     *
     * ⚠️ Sólo desde **Asignado**, que es donde la app muestra el botón. Antes un pedido
     * Finalizado o Cancelado volvía a En curso. Repetirlo sobre el propio pedido ya En curso
     * responde igual que la primera vez, sin hacer nada más.
     *
     * @return bool Si de verdad cambió algo. Falso cuando ya estaba aceptado por él mismo.
     */
    public function aceptar(Pedido $pedido, Cadete $cadete): bool
    {
        $cambio = Pedido::query()
            ->whereKey($pedido->id)
            ->where('estado', Pedido::ESTADO_ASIGNADO)
            ->update(['estado' => Pedido::ESTADO_EN_CURSO]);

        $pedido->refresh();

        if ($cambio === 0) {
            return false;
        }

        $cadete->estado = Cadete::ESTADO_CON_PEDIDO_APP;
        $cadete->save();

        LogEstadoPedido::registrar($pedido, LogEstadoPedido::CADETE_APP, $cadete);

        return true;
    }

    /**
     * El cadete suelta el pedido: vuelve a la lista de espera sin cadete.
     *
     * ⚠️ Sólo desde **Asignado**. Antes se podía soltar un pedido En curso o Finalizado, o
     * sea devolver algo ya entregado.
     *
     * ⚠️ El cadete se captura antes de soltarlo: después de `cadete_id = null` el pedido ya
     * no sabe quién lo tenía, y el registro quiere decir justamente eso.
     */
    public function rechazar(Pedido $pedido, Cadete $cadete): bool
    {
        $cambio = Pedido::query()
            ->whereKey($pedido->id)
            ->where('estado', Pedido::ESTADO_ASIGNADO)
            ->update(['estado' => Pedido::ESTADO_RECHAZADO, 'cadete_id' => null]);

        if ($cambio === 0) {
            return false;
        }

        $pedido->refresh();

        LogEstadoPedido::registrar($pedido, LogEstadoPedido::CADETE_APP, $cadete);

        return true;
    }

    /**
     * El cadete entregó: el pedido queda Finalizado y a él se le cobra el saldo.
     *
     * ⚠️ **Acá es donde la escritura condicionada protege plata.** La condición es «que no
     * esté ya Finalizado»: si llegan dos finalizar del mismo pedido, sólo uno cobra. El
     * movimiento además guarda `pedido_id`, y su índice único es el segundo cerrojo (COB-1).
     *
     * ⚠️ **Vuelve a la cola y va al FINAL**, con un `orden_cola` nuevo, como el sistema
     * viejo: ya hizo un viaje, le toca a los que esperan. Y sólo si no le queda otro pedido
     * en curso, porque un cadete puede tener más de uno.
     *
     * ⚠️ Todo en una transacción: el estado del pedido, el del cadete y el cobro son una
     * sola decisión.
     */
    public function finalizar(Pedido $pedido, Cadete $cadete): bool
    {
        return DB::transaction(function () use ($pedido, $cadete): bool {
            $cambio = Pedido::query()
                ->whereKey($pedido->id)
                ->where('estado', '!=', Pedido::ESTADO_FINALIZADO)
                ->update(['estado' => Pedido::ESTADO_FINALIZADO]);

            $pedido->refresh();

            if ($cambio === 0) {
                return false;
            }

            LogEstadoPedido::registrar($pedido, LogEstadoPedido::CADETE_APP, $cadete);

            if (! $cadete->tienePedidoEnCurso()) {
                $cadete->estado = Cadete::ESTADO_ACTIVO_APP;
                $cadete->orden_cola = now()->getTimestamp();
                $cadete->save();
            }

            // ⚠️ `user_id` en null a propósito: esto no lo hace nadie del panel, lo hace el
            // cadete desde su teléfono. Ver PANEL-13.
            $cadete->cobrarSaldoPedido($pedido);

            return true;
        });
    }
}
