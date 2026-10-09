<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

/**
 * El token con el que Firebase le manda notificaciones a ESTE teléfono.
 *
 * La app lo pide al registrarse en el sistema de notificaciones y lo manda sola, cada vez
 * que arranca y cada vez que Firebase se lo renueva —`notificaciones.service.ts`—.
 *
 * ⚠️ **El sistema viejo no valida nada**: `$cadete->fcm_token = $request->fcm_token`, así que
 * una petición sin el campo le BORRA el token al cadete y deja de recibir avisos sin que
 * nadie se entere. Acá es obligatorio.
 *
 * ⚠️ El largo máximo es amplio a propósito. Los tokens guardados miden entre 142 y 163
 * caracteres, pero los emite Google y el formato cambió más de una vez: un tope ajustado a
 * lo que hay hoy es un tope que se rompe solo el día que Google los alargue, y lo que se
 * rompe es que un cadete no reciba avisos. 512 frena el abuso sin tocar lo real.
 */
class GuardarFcmTokenRequest extends PeticionDeLaApp
{
    public const LARGO_MAXIMO = 512;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fcm_token' => ['required', 'string', 'max:'.self::LARGO_MAXIMO],
        ];
    }
}
