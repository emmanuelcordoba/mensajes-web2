<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar sólo a quien tenga uno de los roles que la ruta pide.
 *
 * Se usa como `rol:admin` o `rol:empleado,admin`, igual que en el sistema viejo.
 *
 * ⚠️ **Un usuario sin rol recibe 403, no un error 500.** Hay 20 usuarios activos sin
 * `rol_id` en producción, y el middleware viejo leía una propiedad de null hasta que
 * se arregló. `User::tieneRol()` ya lo tolera, pero la comprobación explícita queda
 * porque el 403 tiene que ser la respuesta a «no sé quién sos», no un efecto
 * secundario.
 *
 * ⚠️ **Y esto no alcanza solo.** El middleware dice quién entra a una ruta, no a qué
 * fila llega: un comercio con rol `restringido` pasa el filtro de la ruta de pedidos
 * y después tiene que comprobarse que el pedido sea suyo. Eso lo hace
 * `PedidoPolicy`, y no tenerlo fue SEC-7.
 */
class VerificarRol
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->tieneRol(array_values($roles))) {
            abort(403, 'No tenés autorización para entrar acá.');
        }

        return $next($request);
    }
}
