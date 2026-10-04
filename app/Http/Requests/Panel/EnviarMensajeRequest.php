<?php

namespace App\Http\Requests\Panel;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un mensaje de la central a un cadete.
 *
 * ⚠️ El largo máximo es el que aceptaba el formulario viejo —`maxlength="255"`—, aunque la
 * columna es TEXT y aguantaría más. Lo que allá era una restricción del navegador acá la
 * exige el servidor, que es donde sirve.
 *
 * ⚠️ El mensaje no puede ser vacío. La columna es nullable y hay mensajes históricos en
 * null, así que la pantalla tiene que tolerarlos al leer; escribir uno nuevo así, no.
 */
class EnviarMensajeRequest extends FormRequest
{
    public const LARGO_MAXIMO = 255;

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
            'mensaje' => ['required', 'string', 'max:'.self::LARGO_MAXIMO],
        ];
    }
}
