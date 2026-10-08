<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Controllers\Controller;
use App\Models\Cadete;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 *
 * ⚠️ **Y el 422 tiene su propio formato**, que no es el de Laravel: vive en
 * `App\Http\Requests\Api\CadeteApp\V1\PeticionDeLaApp`, de la que cuelgan las tres
 * peticiones de este consumidor.
 *
 * ⚠️ Las clases que dan forma a las respuestas se llaman `PedidoDeLaApp`, `CadeteDeLaApp`
 * y compañía, y no `PedidoResource`: en este proyecto **`CadeteResource` y `ClienteResource`
 * ya existen** y son los de Filament, que es otra cosa. Dos clases con el mismo nombre
 * corto, una de la administración y otra del contrato con un teléfono, es exactamente el
 * tipo de confusión que no conviene sembrar.
 */
abstract class ControladorDeLaApp extends Controller
{
    /**
     * La cuenta de quien está autenticado.
     *
     * El token y el rol los exige la ruta, así que acá siempre hay una; lo que hace falta es
     * decírselo a los tipos, que `$peticion->user()` devuelve un `Authenticatable|null`.
     */
    protected static function laCuenta(Request $peticion): User
    {
        $cuenta = $peticion->user();

        if (! $cuenta instanceof User) {
            self::frenar('No hay una cuenta autenticada.', 401);
        }

        return $cuenta;
    }

    /**
     * El cadete de quien está autenticado.
     *
     * ⚠️ Una cuenta con rol de cadete pero sin fila de cadete no existe en la práctica, pero
     * el tipo lo permite y el sistema viejo hacía `Auth::user()->cadete->algo` sin mirar:
     * eso es un 500 con «Attempt to read property on null» en la cara del cadete.
     */
    protected static function elCadete(Request $peticion): Cadete
    {
        $cadete = self::laCuenta($peticion)->cadete;

        if ($cadete === null) {
            self::frenar('Esta cuenta no tiene un cadete asociado.', 403);
        }

        return $cadete;
    }

    /** El formato de error que la app sabe leer. */
    protected static function error(string $mensaje, int $codigo): JsonResponse
    {
        return response()->json(['error' => $mensaje, 'code' => $codigo], $codigo);
    }

    /**
     * Lo mismo, pero cortando ahí nomás.
     *
     * Es para los guardas que están antes de poder contestar algo: devolver una respuesta
     * desde ellos obligaría a que cada método mire si lo que recibió es un cadete o un error.
     */
    protected static function frenar(string $mensaje, int $codigo): never
    {
        throw new HttpResponseException(self::error($mensaje, $codigo));
    }
}
