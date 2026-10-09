<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

/**
 * El código y la contraseña nueva.
 *
 * ⚠️ **`min:6` y no el PIN numérico de los cadetes.** Es el largo que valida el sistema
 * viejo acá, y este endpoint lo comparten las dos apps: la de clientes tiene contraseñas
 * normales. El PIN de 3 a 8 dígitos es lo que el panel exige al crear un cadete desde el
 * mostrador —`PinDeCadete`—, otra pantalla y otra decisión.
 *
 * ⚠️ El sistema viejo valida además que el email exista, contando los borrados. No se copia:
 * decir «ese email no existe» en un endpoint público es un oráculo para averiguar qué
 * casillas están registradas, y el paso siguiente —el código no coincide— ya contesta lo
 * mismo sin delatar nada.
 */
class RestablecerContrasenaRequest extends PeticionDeLaApp
{
    /** Lo que exige el sistema viejo en este endpoint. */
    public const LARGO_MINIMO = 6;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required'],
            'password' => ['required', 'string', 'min:'.self::LARGO_MINIMO, 'confirmed'],
            'password_confirmation' => ['required'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña tiene que tener al menos '.self::LARGO_MINIMO.' caracteres.',
        ];
    }
}
