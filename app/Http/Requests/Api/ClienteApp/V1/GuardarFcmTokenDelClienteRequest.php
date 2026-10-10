<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

/**
 * El token con el que Firebase le manda notificaciones a este teléfono.
 *
 * ⚠️ **El sistema viejo no valida nada**: `$cliente->fcm_token = $request->fcm_token`, así que
 * una petición sin el campo le BORRA el token al cliente y deja de recibir avisos de que su
 * pedido salió o llegó, sin que nadie se entere. Es el mismo descuido que en la app del
 * cadete, y el mismo arreglo.
 */
class GuardarFcmTokenDelClienteRequest extends PeticionDeLaApp
{
    /** El mismo tope que el del cadete: lo emite Google y el formato cambió más de una vez. */
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
