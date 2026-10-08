<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Lo que comparten las peticiones de la app del cadete: cómo se contesta que los datos
 * están mal.
 *
 * ⚠️ **El formato del 422 es parte del contrato.** La app hace
 * `if (err.error.code == 422) { this.respuesta_messages = err.error.error }` y después
 * recorre `respuesta_messages.nombres` para poner el texto rojo debajo de cada campo
 * —`perfil-editar.page.ts` y su plantilla—. El 422 que Laravel manda por omisión es
 * `{message, errors}`: con eso la app entra por la rama del «Hubo un error. Vuelva a
 * intentar más tarde», el cadete no ve qué campo está mal y no tiene cómo averiguarlo.
 *
 * Es el `errorResponse($validator->errors(), 422)` del sistema viejo, que acá queda en un
 * solo lugar en vez de repetido en cada método.
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
