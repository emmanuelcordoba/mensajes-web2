<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

use Illuminate\Validation\Rule;

/**
 * Los datos con que un cliente que el panel ya tenía cargado pasa a tener cuenta.
 *
 * Son los mismos que el alta nueva menos el teléfono: ése ya está en la fila, es el que la
 * oficina usaba para llamarlo, y es con el que la app comprueba que la persona sea quien
 * dice. No se deja reescribir desde acá, como en el sistema viejo.
 */
class RegistrarClienteDelPanelRequest extends PeticionDeLaApp
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->value()))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombres' => ['required', 'string', 'max:60'],
            'apellidos' => ['required', 'string', 'max:60'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => [
                'required',
                'string',
                'min:'.RegistrarseRequest::LARGO_MINIMO_DE_CONTRASENA,
                'max:255',
                'confirmed',
            ],
            'direccion' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ese email ya está en uso.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña tiene que tener al menos '
                .RegistrarseRequest::LARGO_MINIMO_DE_CONTRASENA.' caracteres.',
        ];
    }
}
