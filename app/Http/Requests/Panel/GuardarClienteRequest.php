<?php

namespace App\Http\Requests\Panel;

use App\Models\Cliente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que el panel acepta al crear o editar un cliente.
 *
 * Son los cuatro campos que editaba la pantalla vieja —número, nombre, dirección y
 * teléfono— más el nombre del empleado que atiende.
 */
class GuardarClienteRequest extends FormRequest
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
        $editando = $this->route('cliente') instanceof Cliente ? $this->route('cliente') : null;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],
            'nombre_empleado' => ['nullable', 'string', 'max:255'],

            // ⚠️ El número NO se pide al crear: lo asigna `clientes_numero_seq`. Si el
            // panel deja escribirlo a mano la secuencia no avanza, y un `nextval`
            // posterior choca con lo que se puso. El UNIQUE lo atrapa, pero el alta que
            // falla es la de OTRO cliente, que es donde nadie lo va a entender. Al editar
            // sí, porque es la única forma de corregir uno mal puesto.
            //
            // Sin `withoutTrashed()`: un cliente dado de baja sigue ocupando su número,
            // porque el UNIQUE lo cuenta.
            'numero' => $editando === null
                ? ['prohibited']
                : [
                    'required',
                    'integer',
                    'min:0',
                    Rule::unique('clientes', 'numero')->ignore($editando->id),
                ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero.prohibited' => 'El número lo asigna el sistema al crear el cliente.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre_empleado' => 'nombre del empleado',
        ];
    }
}
