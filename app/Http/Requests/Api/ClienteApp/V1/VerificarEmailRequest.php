<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

/**
 * El código que la persona copió del mail, y opcionalmente el token del teléfono.
 *
 * ⚠️ `fcm_token` es opcional porque la app lo manda sólo si ya lo tiene: en el primer
 * arranque puede no haberse registrado todavía en Firebase. El sistema viejo pregunta
 * `if($request->fcm_token)` y acá lo dice la regla.
 */
class VerificarEmailRequest extends PeticionDeLaApp
{
    /** El mismo tope que el del cadete, y por lo mismo: lo emite Google. */
    public const LARGO_MAXIMO_DEL_TOKEN = 512;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required'],
            'fcm_token' => ['nullable', 'string', 'max:'.self::LARGO_MAXIMO_DEL_TOKEN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'El campo codigo es obligatorio.',
        ];
    }
}
