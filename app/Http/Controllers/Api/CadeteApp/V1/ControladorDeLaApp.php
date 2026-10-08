<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Controllers\Controller;
use App\Models\Cadete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lo que comparten los controladores de la app del cadete: de quién es el token y cómo se
 * contesta que no.
 *
 * Es el `Api\v1\ApiController` del sistema viejo, con la diferencia de que allá la base la
 * comparten los cuatro consumidores —cadetes, las dos apps de clientes y la integración— y
 * acá es de uno solo. El formato de una respuesta es parte del contrato de cada app, y
 * cuando la base es común, cambiarla para uno la cambia para todos sin que nadie lo pida.
 *
 * ⚠️ **El formato de error no es decorativo**: la app lee `error.error.error` para armar el
 * texto del alerta que ve el cadete —`pedidos.page.ts`—. Si la respuesta no trae `error`,
 * la app muestra «Hubo un error.» y el cadete se queda sin saber qué pasó.
 * ⚠️ Las clases que dan forma a las respuestas se llaman `PedidoDeLaApp`, `CadeteDeLaApp`
 * y compañía, y no `PedidoResource`: en este proyecto **`CadeteResource` y `ClienteResource`
 * ya existen** y son los de Filament, que es otra cosa. Dos clases con el mismo nombre
 * corto, una de la administración y otra del contrato con un teléfono, es exactamente el
 * tipo de confusión que no conviene sembrar.
 */
abstract class ControladorDeLaApp extends Controller
{
    /**
     * El cadete de quien está autenticado.
     *
     * ⚠️ Una cuenta con rol de cadete pero sin fila de cadete no existe en la práctica, pero
     * el tipo lo permite y el sistema viejo hacía `Auth::user()->cadete->algo` sin mirar:
     * eso es un 500 con «Attempt to read property on null» en la cara del cadete.
     */
    protected static function elCadete(Request $peticion): Cadete
    {
        $cadete = $peticion->user()?->cadete;

        if ($cadete === null) {
            throw ValidationException::withMessages([
                'cadete' => 'Esta cuenta no tiene un cadete asociado.',
            ]);
        }

        return $cadete;
    }

    /** El formato de error que la app sabe leer. */
    protected static function error(string $mensaje, int $codigo): JsonResponse
    {
        return response()->json(['error' => $mensaje, 'code' => $codigo], $codigo);
    }
}
