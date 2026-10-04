<?php

namespace App\Http\Requests\Panel;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registrar un pago contra el ciclo en curso.
 *
 * ⚠️ Sólo se valida lo que el método usa: el vencimiento y los dos montos que se cobran.
 * El panel viejo validaba además `monto_semanal`, `monto_pagado_efectivo` y
 * `monto_pagado_tickets` —tres campos de sólo lectura que el método ignora—, y eso
 * sugería lo contrario de lo que pasa. Se sacaron al cerrar PANEL-9.
 */
class RegistrarPagoRequest extends FormRequest
{
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
            'fecha_vencimiento' => [
                'required',
                'date',
                'before_or_equal:'.now()->addYear()->toDateString(),
            ],
            'monto_efectivo' => ['required', 'numeric', 'min:0'],
            'monto_tickets' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fecha_vencimiento' => 'fecha de vencimiento',
            'monto_efectivo' => 'efectivo',
            'monto_tickets' => 'tickets',
        ];
    }
}
