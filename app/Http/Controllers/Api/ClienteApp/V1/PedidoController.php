<?php

namespace App\Http\Controllers\Api\ClienteApp\V1;

use App\Http\Resources\Api\ClienteApp\V1\PedidoDelCliente;
use App\Models\Pedido;
use App\Services\PedidoDelClienteApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los pedidos de un cliente: mirarlos, aceptar la cotización y cancelar.
 *
 * El alta y la cotización están en la V2, que es donde la app las pide.
 *
 * ⚠️ **El binding de la ruta resuelve CUALQUIER pedido por id, sin mirar de quién es**, así
 * que los tres endpoints lo comprueban antes de leer o escribir. Y contestan **404 y no
 * 403**: un 403 confirma que el pedido existe, y los ids son correlativos.
 */
class PedidoController extends ControladorDeLaApp
{
    /** Los pedidos del cliente, el último movido primero. */
    public function index(Request $request, PedidoDelClienteApp $pedidos): JsonResponse
    {
        $cliente = self::elCliente($request);

        return response()->json([
            'ok' => true,
            'pedidos' => PedidoDelCliente::collection($pedidos->suyos($cliente)),
        ]);
    }

    /** Uno. */
    public function show(Request $request, Pedido $pedido): JsonResponse
    {
        $cliente = self::elCliente($request);

        if (! PedidoDelClienteApp::esDe($pedido, $cliente)) {
            return self::error('No se encontró el pedido solicitado.', 404);
        }

        return response()->json([
            'ok' => true,
            'pedido' => new PedidoDelCliente($pedido->load('cadete.user.foto')),
        ]);
    }

    /**
     * El cliente acepta la cotización.
     *
     * ⚠️ **Sólo desde Cotizado, o desde Aceptado, que es el mismo paso repetido.** Antes
     * cualquier estado pasaba a «Aceptado», así que un pedido En curso o Finalizado volvía
     * atrás. Ver API-3.
     */
    public function aceptar(Request $request, Pedido $pedido, PedidoDelClienteApp $pedidos): JsonResponse
    {
        $cliente = self::elCliente($request);

        if (! PedidoDelClienteApp::esDe($pedido, $cliente)) {
            return self::error('No se encontró el pedido solicitado.', 404);
        }

        if (! $pedidos->aceptar($pedido, self::laCuenta($request))) {
            return self::error('El pedido ya no se puede aceptar.', 422);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * El cliente cancela el pedido.
     *
     * ⚠️ **Sólo mientras no tenga cadete.** Antes se borraba en cualquier estado, En curso y
     * Finalizado incluidos: o sea con el cadete en la calle. Ver API-3.
     *
     * La app llama a esto desde dos botones —«rechazar» y «cancelar»— y muestra su propio
     * texto en cada uno; para el servidor es la misma cosa.
     */
    public function destroy(Request $request, Pedido $pedido, PedidoDelClienteApp $pedidos): JsonResponse
    {
        $cliente = self::elCliente($request);

        if (! PedidoDelClienteApp::esDe($pedido, $cliente)) {
            return self::error('No se encontró el pedido solicitado.', 404);
        }

        if (! $pedidos->cancelar($pedido)) {
            return self::error('El pedido ya no se puede cancelar.', 422);
        }

        return response()->json(['ok' => true]);
    }
}
