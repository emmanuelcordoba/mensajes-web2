<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Lo que comparten las peticiones de la app de clientes: cómo se contesta que los datos
 * están mal.
 *
 * ⚠️ El 422 va en el formato que la app lee: `register.page.ts` hace
 * `this.respuesta_messages = error.error.error` y la plantilla recorre
 * `respuesta_messages.nombres`. Con el `{message, errors}` de Laravel, la persona ve un
 * «Hubo un error» genérico y no sabe qué campo corregir.
 */
abstract class PeticionDeLaApp extends FormRequest
{
    /** El token y el rol los exige la ruta; acá no hay nada más que autorizar. */
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => $validator->errors(),
            'code' => 422,
        ], 422));
    }
}
