<?php

namespace App\Services;

use App\Models\ActividadCadete;
use App\Models\Cadete;
use App\Models\MotivoBloqueo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El cadete se pone activo —o inactivo— desde su teléfono.
 *
 * Es el `cambiarestado` de la API vieja, y es el gemelo de `IngresoALaCola`, que es el
 * mismo movimiento hecho desde el mostrador. Son dos servicios y no uno porque **el
 * sistema viejo les pregunta cosas distintas**, y eso no se unifica acá:
 *
 * | | Mostrador | App |
 * |---|---|---|
 * | Bloqueado | no entra | no entra |
 * | Cuota vencida | no entra | **entra** |
 * | Saldo bajo el mínimo | **entra** | no entra |
 *
 * ⚠️ La asimetría es real y está en el código viejo: el control de vencimiento del endpoint
 * de la app está **comentado** —`CadeteController@cambiarEstado`, líneas 123-130— y el del
 * saldo no existe en el camino del mostrador. O sea que hoy un cadete con la cuota vencida
 * entra por la app pero no por el mostrador, y uno sin saldo al revés. Se replica tal cual
 * porque cambiarlo es cambiar a quién se le deja trabajar, y eso no es una decisión de un
 * porteo. Queda anotado para que alguien lo decida alguna vez.
 *
 * ⚠️ Las tres negativas sólo se preguntan al ENTRAR. Salir de la cola se puede siempre: un
 * cadete bloqueado mientras trabajaba tiene que poder ponerse inactivo.
 */
class IngresoALaColaDesdeLaApp
{
    /**
     * Por qué no puede ponerse activo desde la app, o null si puede.
     *
     * ⚠️ Los mensajes son los del sistema viejo, incluido el del saldo con sus saltos de
     * línea en HTML: la app los muestra con `innerHTML`, así que el `<br>` es parte del
     * formato y no un descuido.
     */
    public static function porQueNo(Cadete $cadete): ?string
    {
        // Salir se puede siempre.
        if ($cadete->estaTrabajando()) {
            return null;
        }

        $motivo = $cadete->user?->motivoBloqueo;

        // ⚠️ Para estos dos el mensaje ES la descripción del motivo, palabra por palabra:
        // «Bloqueado por falta de pago.» y «Bloqueado por reclamo en un pedido.». En el
        // sistema viejo eran dos constantes de `User` con ese mismo texto escrito otra vez.
        // Un tercer lugar donde escribirlo es un tercer lugar donde quedarse viejo.
        if ($motivo !== null && in_array(
            $motivo->codigo,
            [MotivoBloqueo::FALTA_DE_PAGO, MotivoBloqueo::RECLAMO_PEDIDO],
            true,
        )) {
            return $motivo->descripcion;
        }

        // Cualquier otro motivo —hoy `otro-motivo`— no dice cuál, y eso es a propósito:
        // «Bloqueado por otro motivo» no le sirve a nadie, y el cadete tiene que ir a
        // preguntar igual.
        if ($cadete->user?->estaBloqueado() === true) {
            return 'Cadete bloqueado. Pasar pronto por administración.';
        }

        if (! $cadete->cobraPorSaldo()) {
            return null;
        }

        // ⚠️ Si falta una configuración se dice, en vez de dejar pasar. El sistema viejo
        // reventaba acá con «Attempt to read property on null» en medio de la cuenta.
        $minimo = Cadete::saldoMinimoSiSePuede();

        if ($minimo === null) {
            return 'No se puede comprobar el saldo mínimo: falta configurarlo.'
                .' Pasar por administración.';
        }

        if ((float) $cadete->cobranza_saldo >= $minimo) {
            return null;
        }

        return 'Saldo insuficiente.<br><br>Saldo actual: $ '.$cadete->cobranza_saldo
            .'<br>Saldo mínimo: $ '.$minimo;
    }

    /**
     * Lo pone activo, o lo saca.
     *
     * ⚠️ En una transacción porque son dos escrituras: el estado del cadete y el tramo de
     * actividad. Un tramo abierto sin el cadete activo es una hora que después se cobra.
     */
    public function alternar(Cadete $cadete): void
    {
        $motivo = self::porQueNo($cadete);

        if ($motivo !== null) {
            // Llegar acá significa que algo aceptó lo que la regla niega.
            throw new RuntimeException($motivo);
        }

        DB::transaction(function () use ($cadete): void {
            $quedoActivo = $cadete->alternarEstadoDesdeLaApp();

            // El cierre del tramo lo dispara el `saving` del modelo, por cualquier camino.
            $cadete->save();

            if ($quedoActivo) {
                // ⚠️ Y la apertura va acá, explícita: el modelo cierra solo pero no abre
                // solo, porque entrar por el mostrador no abre tramo.
                ActividadCadete::create([
                    'cadete_id' => $cadete->id,
                    'inicio' => now(),
                ]);
            }
        });
    }
}
