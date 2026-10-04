<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\MontoSemanal;
use App\Models\Pedido;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La cobranza semanal: el cadete paga una cuota por semana.
 *
 * ⚠️ **ESTÁ MUERTA, Y SE PORTÓ IGUAL.** Los datos: 521 cadetes abrieron un ciclo alguna
 * vez, **10 registraron un pago**, y el último fue el **2022-04-09**. Los 15 ciclos
 * vigentes no tienen un solo pago. La empresa cobra por saldo —13.412 movimientos, el
 * último de la semana pasada—. Se porta porque la pantalla existe en el menú viejo y la
 * decisión de sacarla no es de quien porta.
 *
 * ⚠️ **LA ARITMÉTICA DE «RECONOCER SEMANA» ESTÁ MAL Y SE DEJÓ COMO ESTÁ.** Ver PANEL-21.
 * Abrir un ciclo nuevo recalcula la deuda restando los pagos ACUMULADOS, así que la
 * segunda semana de quien pagó completo arranca sin deuda, y así todas las siguientes.
 * Hay un test que congela ese resultado: si alguien lo arregla, el test avisa que la
 * cuenta cambió, que es una decisión y no un detalle.
 *
 * ⚠️ Lo que SÍ se corrigió es el rango de las fechas. El validador viejo sólo exigía
 * `before:fecha_vencimiento`, y por eso hay vencimientos en 2027, 2029 y **3030**. Eso es
 * la ausencia de una validación, no la aritmética que quedó pendiente.
 *
 * ⚠️ Y las cuentas van con `bcmath` sobre cadenas, no con floats. Las columnas son
 * `NUMERIC(12,2)`: el resultado es el mismo que el del sistema viejo, sin el riesgo de
 * perder centavos por el camino.
 */
class CobranzaSemanal
{
    /** Lo que dura un ciclo, y lo que el formulario propone como vencimiento. */
    public const DIAS_DEL_CICLO = 7;

    /**
     * La cuota que le toca: la suya si tiene una propia, si no la de su vehículo.
     *
     * @return numeric-string
     */
    public static function cuotaDe(Cadete $cadete): string
    {
        if ($cadete->tiene_monto_semanal_personal && $cadete->monto_semanal !== null) {
            return Pedido::comoMonto($cadete->monto_semanal);
        }

        return Pedido::comoMonto(MontoSemanal::para($cadete->tipo_vehiculo));
    }

    /**
     * Abre un ciclo: el primero, o el siguiente.
     *
     * ⚠️ Es el `storeNuevaCobranza` del panel viejo, con su cuenta intacta —PANEL-21—:
     * la deuda sale de la cuota menos los pagos que se le pasen, y a «reconocer semana»
     * el formulario le pasa los acumulados.
     *
     * ⚠️ Desbloquea la cuenta, como allá. Abrir un ciclo es reconocer que la cobranza está
     * al día para empezar, y quien estaba bloqueado por falta de pago deja de estarlo.
     *
     * Los montos entran como cadenas cualesquiera y se normalizan acá con
     * `comoMonto()`: el validador ya exigió que sean números, y esto hace que el tipo lo
     * garantice el servicio en vez de la confianza en quien llama.
     */
    public function abrirCiclo(
        Cadete $cadete,
        CarbonInterface $inicio,
        CarbonInterface $vencimiento,
        string $cuota,
        string $efectivo,
        string $tickets,
    ): void {
        $cuota = Pedido::comoMonto($cuota);
        $efectivo = Pedido::comoMonto($efectivo);
        $tickets = Pedido::comoMonto($tickets);

        DB::transaction(function () use ($cadete, $inicio, $vencimiento, $cuota, $efectivo, $tickets): void {
            // Las columnas son DATE y el modelo las castea a Illuminate\Support\Carbon;
            // lo que llega puede ser un CarbonImmutable, que es lo que devuelve
            // `$request->date()`.
            $cadete->fecha_inicio = Carbon::instance($inicio);
            $cadete->fecha_vencimiento = Carbon::instance($vencimiento);
            $cadete->monto_semanal = $cuota;
            $cadete->monto_pagado_efectivo = $efectivo;
            $cadete->monto_pagado_tickets = $tickets;
            $cadete->monto_deuda = bcsub(bcsub($cuota, $efectivo, 2), $tickets, 2);
            $cadete->save();

            $cadete->user?->update(['bloqueado' => null, 'bloqueado_mensaje' => null]);
        });
    }

    /**
     * Registra un pago contra el ciclo en curso.
     *
     * ⚠️ La deuda se recalcula con la cuota **guardada**, no con la que venga en el
     * cuerpo. Es PANEL-9, ya arreglado en el sistema viejo: el formulario mandaba
     * `monto_semanal` como campo de sólo lectura, el método lo usaba para la cuenta y
     * nunca lo guardaba, así que daba bien por accidente. `readonly` es del navegador.
     *
     * Los montos se normalizan acá, por lo mismo que en `abrirCiclo()`.
     */
    public function registrarPago(
        Cadete $cadete,
        CarbonInterface $vencimiento,
        string $efectivo,
        string $tickets,
    ): void {
        $efectivo = Pedido::comoMonto($efectivo);
        $tickets = Pedido::comoMonto($tickets);

        $cadete->fecha_vencimiento = Carbon::instance($vencimiento);
        $cadete->monto_pagado_efectivo = bcadd(Pedido::comoMonto($cadete->monto_pagado_efectivo), $efectivo, 2);
        $cadete->monto_pagado_tickets = bcadd(Pedido::comoMonto($cadete->monto_pagado_tickets), $tickets, 2);
        $cadete->monto_deuda = bcsub(
            bcsub(Pedido::comoMonto($cadete->monto_semanal), $cadete->monto_pagado_efectivo, 2),
            $cadete->monto_pagado_tickets,
            2,
        );
        $cadete->save();
    }

    /**
     * Le da un día más de plazo, y lo desbloquea.
     *
     * Es el `extenderVencimiento` viejo. Un día por vez, como allá: es lo que la oficina
     * concede cuando alguien dice que paga mañana.
     */
    public function extenderUnDia(Cadete $cadete): void
    {
        if ($cadete->fecha_vencimiento === null) {
            return;
        }

        DB::transaction(function () use ($cadete): void {
            $cadete->fecha_vencimiento = $cadete->fecha_vencimiento->copy()->addDay();
            $cadete->save();

            $cadete->user?->update(['bloqueado' => null, 'bloqueado_mensaje' => null]);
        });
    }
}
