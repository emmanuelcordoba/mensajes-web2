<?php

namespace App\Http\Requests\Panel;

use App\Models\MotivoBloqueo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que hace falta para bloquear a un cadete.
 *
 * ⚠️ El motivo tiene que ser uno de los tres del catálogo, y la clave foránea de
 * `users.bloqueado` no deja entrar otro. En el sistema viejo era texto libre en la columna
 * y tres constantes de `User`, y por eso PANEL-12 pudo pasar: el bloqueo automático
 * comparaba contra un valor que no existía en ninguna fila.
 *
 * El mensaje se pide sólo cuando el motivo es «otro», que es lo que hacía el modal viejo
 * —allá lo decidía el JavaScript del navegador, y el validador del servidor tenía la misma
 * regla escrita aparte—.
 */
class BloquearCadeteRequest extends FormRequest
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
            'motivo' => ['required', Rule::exists('motivos_bloqueo', 'codigo')],
            'mensaje' => [
                Rule::requiredIf(fn (): bool => $this->input('motivo') === MotivoBloqueo::OTRO_MOTIVO),
                'nullable',
                'string',
                // Lo mismo que aceptaba el modal viejo, y lo que entra en una notificación.
                'max:120',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Elegí un motivo.',
            'mensaje.required' => 'Con «otro motivo» hace falta escribir cuál.',
        ];
    }
}
