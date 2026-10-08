<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Resources\Api\CadeteApp\V1\PedidoDeLaApp;
use App\Models\Cadete;
use App\Models\Pedido;
use App\Services\PedidoDelCadete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los pedidos, desde la app del cadete.
 *
 * Son los cinco que el cadete usa de verdad: su lista, el detalle de uno, y los tres
 * botones —aceptar, rechazar, finalizar—. La regla de cada uno vive en `PedidoDelCadete`,
 * porque lo que tienen de delicado es que un teléfono repite las pulsaciones.
 *
 * ⚠️ **Los tres botones son GET con `?id=`**, que para algo que escribe está mal, y se
 * replica igual: así los llama la app publicada —`/api/pedidos/aceptar?id=` en
 * `data.service.ts`— y cambiarlo es romperle la pantalla a quien no actualizó.
 *
 * ⚠️ **`getAsignados` y `getAceptados` no se portan.** Buscados en toda la historia del
 * repositorio de la app: nunca se llamaron.
 */
class PedidoController extends ControladorDeLaApp
{
    /**
     * Los pedidos del cadete, en dos grupos.
     *
     * ⚠️ Van los campos de la fila y **no el comercio ni quien lo cargó**. El listado de la
     * app muestra número, fecha, dirección y destino; el detalle se pide aparte. En el
     * sistema viejo el modelo tiene `$with = ['user','cliente']`, así que en cada carga de
     * esta pantalla viajaban al teléfono el comercio entero y la cuenta del empleado.
     */
    public function index(Request $request): JsonResponse
    {
        $cadete = self::elCadete($request);

        $suyos = fn (string $estado) => PedidoDeLaApp::collection(
            Pedido::query()
                ->where('cadete_id', $cadete->id)
                ->where('estado', $estado)
                ->orderBy('created_at')
                ->get(),
        );

        return response()->json([
            'pedidos' => [
                'asignados' => $suyos(Pedido::ESTADO_ASIGNADO),
                'aceptados' => $suyos(Pedido::ESTADO_EN_CURSO),
            ],
        ]);
    }

    /**
     * Un pedido, con el comercio y el cadete.
     *
     * ⚠️ **Sólo el cadete que lo tiene.** Antes lo leía cualquier token de la API, con los
     * datos del comercio y del cadete adentro. Ver SEC-10. La negativa es 404 y no 403, por
     * lo mismo que en el panel: un 403 confirma que el pedido existe.
     */
    public function show(Request $request, Pedido $pedido): JsonResponse
    {
        $cadete = self::elCadete($request);

        if (! PedidoDelCadete::esDe($pedido, $cadete)) {
            return self::error('No se encontró el pedido solicitado.', 404);
        }

        $pedido->load(['cliente', 'cadete']);

        return response()->json(['pedido' => new PedidoDeLaApp($pedido)]);
    }

    /** El cadete toma el pedido. */
    public function aceptar(Request $request, PedidoDelCadete $servicio): JsonResponse
    {
        return $this->elBoton($request, 'No puede aceptar el pedido.', function (
            Pedido $pedido,
            Cadete $cadete,
        ) use ($servicio): bool {
            if ($servicio->aceptar($pedido, $cadete)) {
                return true;
            }

            // ⚠️ Repetir el botón sobre el propio pedido ya En curso contesta como la
            // primera vez: desde el teléfono las dos pulsaciones son la misma intención.
            return $pedido->estado === Pedido::ESTADO_EN_CURSO
                && PedidoDelCadete::esDe($pedido, $cadete);
        });
    }

    /** El cadete suelta el pedido, que vuelve a la lista de espera. */
    public function rechazar(Request $request, PedidoDelCadete $servicio): JsonResponse
    {
        return $this->elBoton(
            $request,
            'No puede rechazar el pedido.',
            fn (Pedido $pedido, Cadete $cadete): bool => $servicio->rechazar($pedido, $cadete),
        );
    }

    /** El cadete entregó. */
    public function finalizar(Request $request, PedidoDelCadete $servicio): JsonResponse
    {
        return $this->elBoton($request, 'No puede finalizar el pedido solicitado.', function (
            Pedido $pedido,
            Cadete $cadete,
        ) use ($servicio): bool {
            // Un finalizar repetido contesta igual que el primero, sin cobrar de nuevo.
            $servicio->finalizar($pedido, $cadete);

            return true;
        });
    }

    /**
     * Lo que los tres botones tienen en común: encontrar el pedido, comprobar que es suyo,
     * hacer lo que cada uno hace, y contestar el pedido como quedó.
     *
     * @param  callable(Pedido, Cadete): bool  $accion
     */
    protected function elBoton(Request $request, string $siNo, callable $accion): JsonResponse
    {
        $cadete = self::elCadete($request);

        $id = $request->query('id');

        // ⚠️ `ctype_digit` antes de buscar: sobre Postgres un id que no es un número hace
        // fallar la consulta con un 500, y la app manda lo que tenga guardado. Es la misma
        // trampa que apareció en el panel.
        $pedido = is_string($id) && ctype_digit($id)
            ? Pedido::query()->find((int) $id)
            : null;

        if ($pedido === null || ! PedidoDelCadete::esDe($pedido, $cadete)) {
            return self::error($siNo, 400);
        }

        if (! $accion($pedido, $cadete)) {
            return self::error($siNo, 400);
        }

        $pedido->refresh()->load(['cliente', 'cadete']);

        return response()->json(['pedido' => new PedidoDeLaApp($pedido)]);
    }
}
