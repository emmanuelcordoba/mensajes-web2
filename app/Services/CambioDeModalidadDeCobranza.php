<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Pasar un cadete de cobranza semanal a cobranza por saldo, o al revés.
 *
 * ⚠️ Sin esto, la modalidad no se podía cambiar desde ninguna pantalla del sistema nuevo:
 * el alta la pone en 'Semanal' y no había forma de moverla. Es PANEL-8, y era un callejón
 * sin salida —en el sistema viejo había un `switch` por ajax, colgado del listado de
 * cobranzas—.
 *
 * ⚠️ **Se cambia siempre, con plata en el medio o sin ella**, igual que allá: el método
 * viejo eran dos líneas —invertir la modalidad y guardar— y el botón estaba en los dos
 * listados. Acá hubo una versión que lo negaba mientras quedara saldo o deuda, y estaba
 * mal medida: los 65 cadetes que cobran por saldo tienen saldo distinto de cero —es,
 * justamente, la plata que cargaron—, así que la regla no cubría un caso raro sino que
 * bloqueaba la pantalla entera. Lo mismo del otro lado con 439 de 1585 semanales.
 *
 * Lo que esa regla cuidaba —que la plata no quedara fuera de todo listado, que es lo que
 * pasaba allá porque cada listado filtraba duro por modalidad— lo cuida ahora la pantalla:
 * el listado de saldo trae las tres vistas («saldo», «semanal», «todas»), muestra las dos
 * columnas cuando hay algo del otro lado, y el detalle de movimientos no mira la
 * modalidad. La plata queda dormida, no invisible. Y `aviso()` la dice en voz alta antes
 * de cambiar y en el mensaje de vuelta, para que nadie se entere seis meses después.
 */
class CambioDeModalidadDeCobranza
{
    /**
     * Qué plata queda del otro lado al cambiar, o null si no queda nada.
     *
     * ⚠️ Mira la modalidad que el cadete tiene AHORA, así que se pregunta antes de
     * cambiar. Después contesta por la modalidad nueva, que es la otra pregunta.
     */
    public static function aviso(Cadete $cadete): ?string
    {
        if ($cadete->cobraPorSaldo()) {
            $saldo = Pedido::comoMonto($cadete->cobranza_saldo);

            if (bccomp($saldo, '0', 2) === 0) {
                return null;
            }

            // El saldo baja a medida que toma pedidos, así que uno positivo es crédito a
            // favor del cadete y uno negativo es plata que debe.
            return bccomp($saldo, '0', 2) > 0
                ? 'Le queda un saldo de $'.$saldo.' a favor, que no se le va a descontar mientras cobre por semana.'
                : 'Le queda un saldo de $'.$saldo.' en contra, que no se va a recuperar mientras cobre por semana.';
        }

        $deuda = Pedido::comoMonto($cadete->monto_deuda);

        return bccomp($deuda, '0', 2) > 0
            ? 'Le queda una deuda semanal de $'.$deuda.', que deja de aparecer en el listado de cobranza semanal.'
            : null;
    }

    /** A cuál pasaría si se cambiara. */
    public static function laOtra(Cadete $cadete): string
    {
        return $cadete->cobraPorSaldo() ? Cadete::COBRANZA_SEMANAL : Cadete::COBRANZA_SALDO;
    }

    /**
     * Lo cambia. Devuelve la modalidad en la que quedó.
     */
    public function cambiar(Cadete $cadete, ?User $autor = null): string
    {
        $desde = $cadete->modalidad_cobranza;
        $aviso = self::aviso($cadete);

        $cadete->modalidad_cobranza = self::laOtra($cadete);
        $cadete->save();

        Log::info('Modalidad de cobranza cambiada desde /panel', [
            'cadete_id' => $cadete->id,
            'numero_movil' => $cadete->numero_movil,
            'desde' => $desde,
            'hasta' => $cadete->modalidad_cobranza,
            // ⚠️ Queda escrito qué plata quedó del otro lado, y es lo único que sobrevive
            // si alguien pregunta más adelante por un saldo que nadie descontó.
            'plata_que_queda' => $aviso,
            'autor' => $autor?->email,
        ]);

        return $cadete->modalidad_cobranza;
    }
}
