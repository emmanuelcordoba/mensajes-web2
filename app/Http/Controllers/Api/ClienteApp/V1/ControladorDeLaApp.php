<?php

namespace App\Http\Controllers\Api\ClienteApp\V1;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que comparten los controladores de la app de clientes.
 *
 * Es el gemelo del de la app del cadete, y están separados a propósito: el formato de una
 * respuesta es parte del contrato de cada app, y cuando la base es común, cambiarla para una
 * la cambia para la otra sin que nadie lo pida. En el sistema viejo los cuatro consumidores
 * comparten `Api\v1\ApiController`.
 *
 * ⚠️ **Esta app declara muchísimo menos que la del cadete.** `interfaces.ts` tiene UNA
 * interfaz —`RespCotizarPedido`— así que el contrato no está escrito en ningún lado: está
 * implícito en lo que leen las pantallas. Lo que cada respuesta tiene que traer se midió
 * leyendo las plantillas y los componentes, y está anotado en cada recurso.
 *
 * ⚠️ **El formato de error es el mismo `{error, code}`**, porque la app lee
 * `error.error.error` para pintar los mensajes debajo de cada campo —`register.page.ts:67`,
 * `verify.page.ts:70`—.
 */
abstract class ControladorDeLaApp extends Controller
{
    /** La cuenta de quien está autenticado. */
    protected static function laCuenta(Request $peticion): User
    {
        $cuenta = $peticion->user();

        if (! $cuenta instanceof User) {
            self::frenar('No hay una cuenta autenticada.', 401);
        }

        return $cuenta;
    }

    /**
     * El cliente de quien está autenticado.
     *
     * ⚠️ `User::$cliente` es nullable y el sistema viejo hace `Auth::user()->cliente->algo`
     * sin mirar: con una cuenta de cliente sin fila de cliente eso es un 500 con «Attempt to
     * read property on null» en la cara de la persona.
     */
    protected static function elCliente(Request $peticion): Cliente
    {
        $cliente = self::laCuenta($peticion)->cliente;

        if ($cliente === null) {
            self::frenar('Esta cuenta no tiene un cliente asociado.', 403);
        }

        return $cliente;
    }

    /** El formato de error que la app sabe leer. */
    protected static function error(mixed $mensaje, int $codigo): JsonResponse
    {
        return response()->json(['error' => $mensaje, 'code' => $codigo], $codigo);
    }

    /** Lo mismo, pero cortando ahí nomás. */
    protected static function frenar(string $mensaje, int $codigo): never
    {
        throw new HttpResponseException(self::error($mensaje, $codigo));
    }
}
