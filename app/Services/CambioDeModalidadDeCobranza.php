<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pasar un cadete de cobranza semanal a cobranza por saldo, o al revés.
 *
 * ⚠️ Sin esto, la modalidad no se podía cambiar desde ninguna pantalla del sistema nuevo:
 * el alta la pone en 'Semanal' y no había forma de moverla. Es PANEL-8, y era un callejón
 * sin salida —en el sistema viejo había un `switch` por ajax, colgado del listado de
 * cobranzas—.
 *
 * ⚠️ NO SE CAMBIA CON PLATA EN EL MEDIO, y es la parte que el `switch` viejo no tenía.
 * Allá el método era literalmente dos líneas: invertir la modalidad y guardar. Un cadete
 * con saldo a favor que pasaba a semanal dejaba ese saldo en su columna, fuera de todo
 * listado: plata que nadie volvía a ver, ni él ni la oficina. Lo mismo en el otro sentido
 * con una deuda semanal.
 *
 * Es el mismo criterio que `BajaDeCadete::porQueNo()`: antes de que una fila se vuelva
 * invisible, lo que tiene adentro se cierra.
 */
class CambioDeModalidadDeCobranza
{
    /**
     * Por qué no se puede cambiar, o null si se puede.
     */
    public static function porQueNo(Cadete $cadete): ?string
    {
        if ($cadete->cobraPorSaldo()) {
            $saldo = Pedido::comoMonto($cadete->cobranza_saldo);

            if (bccomp($saldo, '0', 2) !== 0) {
                // El saldo baja a medida que toma pedidos, así que uno positivo es crédito
                // a favor del cadete y uno negativo es plata que debe.
                return 'El móvil '.$cadete->numero_movil.' tiene un saldo de $'.$saldo
                    .(bccomp($saldo, '0', 2) > 0 ? ' a favor' : ' en contra')
                    .'. Cerralo antes de pasarlo a cobranza semanal.';
            }

            return null;
        }

        $deuda = Pedido::comoMonto($cadete->monto_deuda);

        if (bccomp($deuda, '0', 2) > 0) {
            return 'El móvil '.$cadete->numero_movil.' tiene una deuda de $'.$deuda
                .'. Cobrala antes de pasarlo a cobranza por saldo.';
        }

        return null;
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
        $motivo = self::porQueNo($cadete);

        if ($motivo !== null) {
            // Llegar acá significa que el listado ofreció algo que la regla niega.
            throw new RuntimeException($motivo);
        }

        $desde = $cadete->modalidad_cobranza;
        $cadete->modalidad_cobranza = self::laOtra($cadete);
        $cadete->save();

        Log::info('Modalidad de cobranza cambiada desde /panel', [
            'cadete_id' => $cadete->id,
            'numero_movil' => $cadete->numero_movil,
            'desde' => $desde,
            'hasta' => $cadete->modalidad_cobranza,
            'autor' => $autor?->email,
        ]);

        return $cadete->modalidad_cobranza;
    }
}
