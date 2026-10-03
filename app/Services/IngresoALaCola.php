<?php

namespace App\Services;

use App\Models\Cadete;
use App\Support\Momento;
use RuntimeException;

/**
 * Dejar entrar —o no— a un cadete a la cola, desde el mostrador.
 *
 * Son las comprobaciones que hacía el `cola()` del panel viejo, en el mismo orden, más una
 * que no hacía. Viven en un servicio y no en el controlador porque la pantalla las
 * pregunta para decir por qué no, y el controlador las aplica antes de escribir: una
 * regla, dos lectores.
 *
 * ⚠️ El orden importa y es el del sistema viejo: primero el bloqueo, después la cobranza,
 * después el estado. Alguien bloqueado **por falta de pago** tiene que leer que está
 * bloqueado, no que la cuota venció.
 */
class IngresoALaCola
{
    /**
     * Por qué no puede entrar, o null si puede.
     */
    public static function porQueNo(Cadete $cadete): ?string
    {
        $movil = 'El móvil '.$cadete->numero_movil;

        if ($cadete->user?->estaBloqueado() === true) {
            $descripcion = $cadete->user->motivoBloqueo?->descripcion;

            return $movil.' está bloqueado.'
                .($descripcion === null ? '' : ' '.$descripcion)
                .' Pasar por administración.';
        }

        // ⚠️ El día del vencimiento todavía entra: se compara contra el día, no contra el
        // instante. El sistema viejo hacía `endOfDay()->lessThan(today())`, que es lo
        // mismo escrito con más pasos.
        if ($cadete->fecha_vencimiento !== null && $cadete->fecha_vencimiento->lt(today())) {
            return $movil.' tiene la cuota vencida el '
                .Momento::fecha($cadete->fecha_vencimiento).'. Pasar por administración.';
        }

        // ⚠️ Acá el sistema viejo miraba sólo `activo-app`, y `con-pedido-app` pasaba de
        // largo: un cadete con un pedido en la mano entraba por el mostrador, perdía ese
        // estado y el pedido quedaba en curso con el cadete puesto en la cola web.
        if ($cadete->estaEnLaApp()) {
            return $movil.' ya está activo en la app. Para entrar por acá tiene que ponerse'
                .' inactivo desde la app.';
        }

        // ⚠️ Esto el sistema viejo no lo preguntaba, y no era inofensivo: volvía a escribir
        // `orden_cola` con la hora de ahora, así que quien tocaba dos veces por las dudas
        // —o se equivocaba de móvil y volvía a intentar— se iba al FINAL de la cola y
        // perdía su lugar sin enterarse.
        if ($cadete->estado === Cadete::ESTADO_ACTIVO_WEB) {
            $posicion = $cadete->posicionEnCola();

            return $movil.' ya está en la cola'
                .($posicion === null ? '.' : ', en el puesto '.$posicion.'.');
        }

        return null;
    }

    /**
     * Lo pone en la cola.
     *
     * Reusa el mismo método del modelo que usa el listado de cadetes: `porQueNo()` ya
     * garantizó que no está trabajando, así que alternar sólo puede hacerlo entrar.
     */
    public function agregar(Cadete $cadete): void
    {
        $motivo = self::porQueNo($cadete);

        if ($motivo !== null) {
            // Llegar acá significa que la pantalla aceptó algo que la regla niega.
            throw new RuntimeException($motivo);
        }

        $cadete->alternarEstadoDesdeLaCentral();
        $cadete->save();
    }
}
