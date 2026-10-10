<?php

namespace App\Http\Controllers\Api\ClienteApp\V2;

use App\Http\Controllers\Api\ClienteApp\V1\ControladorDeLaApp;
use App\Http\Requests\Api\ClienteApp\V2\CotizarPedidoRequest;
use App\Http\Requests\Api\ClienteApp\V2\CrearPedidoRequest;
use App\Http\Resources\Api\ClienteApp\V1\PedidoDelCliente;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Services\Cotizador;
use App\Services\PedidoDelClienteApp;
use Illuminate\Http\JsonResponse;

/**
 * Cargar un pedido y cotizarlo. Es lo único que vive en la V2.
 *
 * ⚠️ **La V2 no reemplaza a la V1: la completa.** La app usa las dos a la vez —pide el
 * listado y el detalle por la V1 y carga el pedido por la V2— porque la V2 son dos endpoints
 * que se agregaron después, cuando el alta pasó a cotizar contra el servidor. No es una
 * versión nueva del contrato, y por eso extiende la misma base y usa el mismo recurso: el
 * formato de error es del consumidor, no de la versión.
 *
 * ⚠️ El alta de pedidos de la V1 **no existe**: se borró del sistema viejo el 2026-08-11
 * porque el último pedido creado por esa vía fue del 2025-01-30 mientras la V2 seguía activa.
 * Ver DEP-2.
 */
class PedidoController extends ControladorDeLaApp
{
    /**
     * Cuánto sale el envío.
     *
     * ⚠️ **Usa el mismo `Cotizador` que el mostrador**, y ahí está lo que se gana: en el
     * sistema viejo esto llama a `Pedido::cotizar()`, el mismo método que el panel, pero
     * nadie garantizaba que siguieran coincidiendo. Una tarifa, dos lectores. Las pruebas
     * cruzadas de la cotización —20 viajes reales contra el sistema viejo— están en
     * `CotizadorTest`.
     *
     * ⚠️ **La distancia llega en metros** y no en kilómetros; lo manda así la app.
     */
    public function cotizar(CotizarPedidoRequest $request, Cotizador $cotizador): JsonResponse
    {
        self::elCliente($request);

        $garantia = $request->input('envia_dinero_monto');

        $costo = $cotizador->cotizar(
            $request->integer('distancia'),
            $request->boolean('retorno_origen'),
            is_numeric($garantia) ? (string) $garantia : null,
        );

        return response()->json([
            'ok' => true,
            // ⚠️ La app lo declara `costo: number` —es su ÚNICA interfaz, `RespCotizarPedido`—
            // y lo guarda para mandarlo de vuelta en el alta. Sale sin los ceros de atrás,
            // que es lo que escribía el float del sistema viejo.
            'costo' => Pedido::montoParaMostrar($costo),
            // Y esto es texto o null, también declarado. Es un cartel que la oficina edita.
            'tiempo_de_demora' => Configuracion::valor('TIEMPO_DE_DEMORA'),
        ]);
    }

    /**
     * El cliente carga el pedido.
     *
     * Nace **Aceptado**: el precio ya está acordado —lo cotizó el servidor dos pantallas
     * antes— así que no hay nada que confirmar. De ahí lo toma la central y lo asigna.
     *
     * Qué se acepta está en `CrearPedidoRequest`; cómo se escribe —y por qué el precio que
     * manda el cliente se cree—, en `PedidoDelClienteApp::crear()`.
     */
    public function store(CrearPedidoRequest $request, PedidoDelClienteApp $pedidos): JsonResponse
    {
        $cliente = self::elCliente($request);

        $pedido = $pedidos->crear($request->validated(), $cliente, self::laCuenta($request));

        return response()->json([
            'ok' => true,
            'pedido' => new PedidoDelCliente($pedido),
        ]);
    }
}
