<?php

namespace App\Http\Requests\Panel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo único que hace falta elegir para contratar: con qué número de móvil entra.
 *
 * Todo lo demás sale de la postulación, que es el punto: lo que se revisó es lo que se
 * contrata.
 *
 * ⚠️ El número se compara **contando a los cadetes dados de baja**, porque el UNIQUE de la
 * columna los cuenta. El valor que la pantalla propone es el último más uno, y es sólo una
 * propuesta: en la práctica se recicla el número del que se va, así que los números vuelven
 * para atrás y no hay secuencia (PANEL-5).
 */
class ContratarPostulacionRequest extends FormRequest
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
            'numero_movil' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('cadetes', 'numero_movil'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_movil.unique' => 'Ese número de móvil ya lo tiene otro cadete, aunque esté dado de baja.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['numero_movil' => 'número de móvil'];
    }
}
