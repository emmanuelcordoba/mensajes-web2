<?php

namespace App\Http\Requests\Panel;

use App\Services\CobranzaSemanal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Abrir un ciclo de cobranza semanal: el primero de un cadete, o el siguiente.
 *
 * ⚠️ **El rango de las fechas es lo único que se agrega sobre el validador viejo**, que
 * sólo exigía `before:fecha_vencimiento` y `after:fecha_inicio`. Por eso hay 15 cadetes
 * con vencimientos en 2027, 2029 y **3030**: una fecha mal tipeada entraba sin más, y la
 * pantalla del mostrador la lee para decidir si alguien puede ponerse en la cola. Es la
 * ausencia de una validación, no la aritmética que quedó pendiente en PANEL-21.
 *
 * ⚠️ Los montos pagados se validan y se usan tal cual vienen, que es lo que hace que
 * «reconocer semana» calcule la deuda con los pagos acumulados. Ver PANEL-21: se porta
 * así a propósito.
 */
class AbrirCicloRequest extends FormRequest
{
    /** Cuánto para atrás y para adelante se acepta una fecha de inicio. */
    public const MESES_HACIA_ATRAS = 12;

    public const MESES_HACIA_ADELANTE = 1;

    /** La autorización la hace la política, desde el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha_inicio' => [
                'required',
                'date',
                'after_or_equal:'.now()->subMonths(self::MESES_HACIA_ATRAS)->toDateString(),
                'before_or_equal:'.now()->addMonths(self::MESES_HACIA_ADELANTE)->toDateString(),
            ],

            'fecha_vencimiento' => [
                'required',
                'date',
                'after:fecha_inicio',
                // Un ciclo dura una semana; un año de plazo ya es otra cosa, y es por donde
                // entraron los vencimientos de 3030.
                'before_or_equal:'.now()->addYear()->toDateString(),
            ],

            'monto_semanal' => ['required', 'numeric', 'min:1'],
            'monto_pagado_efectivo' => ['required', 'numeric', 'min:0'],
            'monto_pagado_tickets' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_inicio.after_or_equal' => 'La fecha de inicio no puede ser de hace más de un año.',
            'fecha_inicio.before_or_equal' => 'La fecha de inicio no puede estar tan adelante en el tiempo.',
            'fecha_vencimiento.after' => 'El vencimiento tiene que ser posterior al inicio.',
            'fecha_vencimiento.before_or_equal' => 'El vencimiento no puede estar a más de un año: un ciclo dura '
                .CobranzaSemanal::DIAS_DEL_CICLO.' días.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fecha_inicio' => 'fecha de inicio',
            'fecha_vencimiento' => 'fecha de vencimiento',
            'monto_semanal' => 'cuota semanal',
            'monto_pagado_efectivo' => 'pagado en efectivo',
            'monto_pagado_tickets' => 'pagado en tickets',
        ];
    }
}
