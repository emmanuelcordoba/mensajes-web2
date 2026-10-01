<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Support\Momento;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los pedidos, desde el panel.
 *
 * ⚠️ El listado filtra con `alcanzablesPor`, no con la policy: una policy responde
 * por una fila y acá hay que no traer las que no corresponden. Las dos reglas son la
 * misma y hay una prueba que lo comprueba. Ver SEC-7.
 */
class PedidoController extends Controller
{
    /**
     * La cola de trabajo: los pedidos que esperan un cadete.
     *
     * Es la pantalla que la oficina mira todo el día, y el orden no es cosmético —los
     * resueltos arriba, y después el que espera hace más tiempo—. Ver
     * `Pedido::scopeEsperandoCadete()`.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Pedido::class);

        $pedidos = Pedido::query()
            ->alcanzablesPor($request->user())
            ->esperandoCadete()
            // Sin esto el listado hace una consulta por pedido para el nombre del
            // cliente. El modelo viejo lo resolvía con $with en TODA consulta, que es
            // el otro extremo.
            ->with(['cliente:id,nombre_mostrado'])
            ->paginate(50)
            ->through(fn (Pedido $pedido): array => [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                'estado' => $pedido->estado,
                'cliente' => $pedido->nombreDelCliente(),
                'direccion' => $pedido->direccion,
                'destino' => $pedido->destino,
                'valor' => $pedido->valor,
                // Ya formateado: la zona horaria la sabe el servidor. Ver Momento.
                'actualizado' => Momento::hace($pedido->updated_at),
            ]);

        return Inertia::render('pedidos/index', [
            'pedidos' => $pedidos,
            'estados' => Pedido::ESTADOS,
        ]);
    }

    /**
     * Un pedido, con su historia.
     *
     * Es la mitad de SEC-7 que el listado no cubre. El listado no trae lo que no
     * corresponde; acá llega un id desde la URL y hay que preguntar por esa fila.
     * Sin esto, un comercio veía el pedido de cualquier otro escribiendo el número.
     *
     * ⚠️ La negativa es 404 y no 403: ver `PedidoPolicy::view()`. Un pedido borrado
     * también da 404, por el binding, y eso importa más de lo que parece: la oficina
     * se deshace de los pedidos que no llegan al final borrándolos, así que la mayor
     * parte de los estados intermedios está entre los borrados.
     *
     * Las cuatro relaciones se cargan de una. `logs` no se carga nunca sola —son
     * 3.637.456 filas—, así que se pide acá y no en el modelo.
     */
    public function show(Pedido $pedido): Response
    {
        $this->authorize('view', $pedido);

        $pedido->load(['cliente:id,numero,nombre_mostrado', 'cadete:id,numero_movil', 'user:id,name', 'logs']);

        return Inertia::render('pedidos/show', [
            'pedido' => [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                'estado' => $pedido->estado,
                'plataforma' => $pedido->plataforma_origen,
                'creado' => Momento::fechaYHora($pedido->created_at),
                'actualizado' => Momento::fechaYHora($pedido->updated_at),

                'cliente' => $pedido->nombreDelCliente(),
                'cliente_numero' => $pedido->cliente?->numero,
                'telefono' => $pedido->telefono,
                'responsable' => $pedido->responsable,

                'direccion' => $pedido->direccion,
                'destino' => $pedido->destino,
                'detalle' => $pedido->detalle,
                'retorno_origen' => $pedido->retorno_origen,
                'gastronomia' => $pedido->gastronomia,

                // Los montos viajan como cadena para no perder centavos: NUMERIC(12,2)
                // no se convierte a float en ningún punto del camino.
                'valor' => $pedido->valor,
                'garantia' => $pedido->garantia,
                'valor_declarado' => $pedido->valor_declarado,

                'tipo_paquete' => $pedido->tipo_paquete,
                'peso_paquete' => $pedido->peso_paquete,

                'movil' => $pedido->cadete?->numero_movil,
                'cargado_por' => $pedido->user?->name,

                'logs' => $pedido->logs->map(fn (LogEstadoPedido $log): array => [
                    'id' => $log->id,
                    'estado' => $log->estado,
                    'mensaje' => $log->mensaje,
                    'plataforma' => $log->plataforma_origen,
                    'cuando' => Momento::fechaYHora($log->created_at),
                ])->all(),
            ],
        ]);
    }
}
