<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

/** El email y la contraseña con que un cliente entra a la app. */
class LoginDeClienteRequest extends PeticionDeLaApp
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
