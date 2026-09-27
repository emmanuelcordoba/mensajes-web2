<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
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
                'actualizado' => $pedido->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('pedidos/index', [
            'pedidos' => $pedidos,
            'estados' => Pedido::ESTADOS,
        ]);
    }
}
