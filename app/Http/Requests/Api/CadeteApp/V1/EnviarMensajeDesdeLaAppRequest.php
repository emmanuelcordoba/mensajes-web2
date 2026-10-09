<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

/**
 * Un mensaje que el cadete escribe desde la app.
 *
 * ⚠️ **El tope es alto a propósito, y no los 255 del panel.** La app no le pone `maxlength`
 * al campo y —esto es lo que manda— `enviarMensaje()` se suscribe **sin manejador de
 * error**: ante un 422 no muestra nada y `btnDisabledEnviar` se queda en true, así que el
 * botón de enviar queda muerto hasta que el cadete salga de la pantalla. Un tope que el
 * cadete pueda tocar escribiendo normalmente le rompe el chat sin decirle por qué.
 *
 * Medido sobre los 8.543 mensajes guardados: el promedio son 29 caracteres y el más largo
 * 6.247. Los 5 que pasan de 255 los escribió un cadete. Entonces esto no es una regla de
 * redacción sino un freno al abuso: la columna es TEXT y sin ningún tope, quien tenga un
 * token escribe filas del tamaño que quiera.
 *
 * ⚠️ `cadete_id` NO se acepta. Es SEC-17 y está explicado en `ConversacionConElCadete`.
 */
class EnviarMensajeDesdeLaAppRequest extends PeticionDeLaApp
{
    /** Por encima de todo lo que un cadete escribió en seis años, y muy por debajo del abuso. */
    public const LARGO_MAXIMO = 10_000;

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
