<?php

namespace App\Http\Requests\Panel;

use App\Models\Cadete;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que la oficina carga cuando el cadete paga su saldo.
 *
 * ⚠️ El mínimo no es una constante: sale de `Cadete::saldoMinimo()`, que lo calcula con
 * `COBRANZA_SALDO_PORCENTAJE` y `COSTO_MINIMO`. Cargar menos que eso deja al cadete
 * igual de frenado que antes de pagar, así que es una carga que no sirve para nada. Es la
 * misma regla que tenía el ajax viejo.
 *
 * ⚠️ Pesos enteros, como allá. No es una limitación del esquema —la columna es
 * `NUMERIC(12,2)`— sino lo que se carga en el mostrador.
 */
class CargarSaldoRequest extends FormRequest
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
        $minimo = Cadete::saldoMinimoSiSePuede();

        return [
            // ⚠️ Sin el porcentaje configurado no hay mínimo que exigir. No se cae acá:
            // la política niega la carga por el mismo motivo, y con un mensaje que se
            // puede leer. Pedir un entero positivo igual es lo mínimo honesto.
            'monto' => ['required', 'integer', 'min:'.($minimo === null ? 1 : (int) $minimo)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto.min' => 'La carga mínima es de $'.(int) (Cadete::saldoMinimoSiSePuede() ?? 1)
                .': con menos que eso el cadete sigue sin poder tomar pedidos.',
            'monto.integer' => 'El monto se carga en pesos enteros.',
        ];
    }
}
