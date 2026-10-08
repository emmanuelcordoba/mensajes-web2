<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

/**
 * El email y la contraseña con que el cadete entra a la app.
 *
 * El email NO se normaliza acá, al revés que en el panel: la comparación del login es la que
 * ignora mayúsculas, porque en MongoDB convivían cuentas que sólo diferían en eso (DATA-7).
 * Ver `CadeteController::login`.
 */
class LoginDeLaAppRequest extends PeticionDeLaApp
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
