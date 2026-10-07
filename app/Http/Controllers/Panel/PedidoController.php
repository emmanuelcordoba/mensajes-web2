<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\AsignarCadeteRequest;
use App\Http\Requests\Panel\GuardarPedidoRequest;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Services\AltaDePedido;
use App\Services\AsignacionDeCadete;
use App\Support\Momento;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
     * Un pedido en JSON, para el modal de la lista de espera.
     *
     * ⚠️ Es lo mismo que hacía `ajax/pedidos/getById`, y por la misma razón que el detalle
     * de un cadete: el teléfono y el nombre del responsable de un pedido no tienen por qué
     * viajar en cada refresco de una pantalla que está abierta todo el día. El listado manda
     * lo que se ve en la fila; el resto sale cuando alguien abre el modal.
     *
     * `editable` dice si los campos se pueden tocar: los pedidos de la app tienen el precio
     * puesto por el cliente y se cotizan por otra rama. Ver `AsignacionDeCadete`.
     *
     * @return array<string, mixed>
     */
    public function detalle(Pedido $pedido): array
    {
        $this->authorize('view', $pedido);

        $pedido->load(['cliente:id,numero,nombre_mostrado', 'user:id,name,iniciales,color']);

        return [
            'id' => $pedido->id,
            'numero' => $pedido->numero,
            'estado' => $pedido->estado,
            'plataforma' => $pedido->plataforma_origen,
            'cliente_numero' => $pedido->cliente?->numero,
            'cliente' => $pedido->nombreDelCliente(),
            'cargado_por' => $pedido->user?->name,
            // El mismo distintivo que la fila de la lista, para que la cabecera del modal
            // diga lo mismo que la lista de la que se abrió. Ver `User::distintivo()`.
            'distintivo' => $pedido->user?->distintivo(),

            'direccion' => $pedido->direccion,
            'destino' => $pedido->destino,
            'responsable' => $pedido->responsable,
            'telefono' => $pedido->telefono,
            'detalle' => $pedido->detalle,
            'valor' => $pedido->valor,
            'garantia' => $pedido->garantia,
            'retorno_origen' => $pedido->retorno_origen,

            // Los tres que el modal muestra y no deja editar.
            'tipo_paquete' => $pedido->tipo_paquete,
            'peso_paquete' => $pedido->peso_paquete,
            'valor_declarado' => $pedido->valor_declarado,

            'editable' => in_array(
                $pedido->plataforma_origen,
                AsignacionDeCadete::PLATAFORMAS_EDITABLES,
                true,
            ),
            'cadete_id' => $pedido->cadete_id,
        ];
    }

    /**
     * Guardar el pedido y, si eligieron uno, darle el cadete.
     *
     * Las dos cosas van juntas porque el modal tiene un solo botón. La regla entera —a qué
     * estado va el pedido, quién cobra, quién tiene cinco minutos para confirmar— vive en
     * `AsignacionDeCadete`.
     */
    public function asignar(AsignarCadeteRequest $request, Pedido $pedido): RedirectResponse
    {
        $this->authorize('asignarCadete', $pedido);

        $cadete = $request->cadete();

        (new AsignacionDeCadete)->guardar(
            $pedido,
            $cadete,
            $request->safe()->except('cadete_id'),
            $request->user()?->id,
        );

        return back()->with('success', $cadete === null
            ? 'Pedido guardado.'
            : 'Pedido '.$pedido->numero.' asignado al móvil '.$cadete->numero_movil.'.');
    }

    /**
     * Cargar un pedido: el botón «Enviar» de la columna del medio de Inicio.
     *
     * La regla entera —de quién es, a qué estado va, quién cobra, quién tiene cinco minutos
     * para confirmar— vive en `AltaDePedido`, que comparte con el modal de la lista de espera
     * la parte que decide el estado del cadete.
     *
     * ⚠️ **El comercio no elige a nombre de quién.** Su pedido es de su propio comercio, que
     * sale de la relación de la cuenta y no de lo que mande el formulario. El panel viejo
     * hace lo mismo con un `merge()`; acá los campos que no le corresponden ya los negó la
     * validación, así que esto no pisa nada: lo completa.
     */
    public function store(GuardarPedidoRequest $request, AltaDePedido $alta): RedirectResponse
    {
        $this->authorize('create', Pedido::class);

        $usuario = $request->user();

        if ($usuario === null) {
            abort(403);
        }

        $campos = $request->safe()->except('cadete_id');

        if ($request->esDeUnComercio()) {
            // La validación ya comprobó que el comercio existe; sin él no se llega acá.
            $campos['cliente_id'] = $request->comercioPropio()?->id;
            $campos['cliente'] = null;
        }

        $pedido = $alta->guardar($campos, $request->cadete(), $usuario);

        return back()->with([
            'success' => 'Pedido '.$pedido->numero.' cargado.',
            // Para el botón de imprimir, que el panel viejo también muestra recién cargado
            // el pedido y sólo entonces: es el comprobante que se le da al cadete.
            'ultimoPedido' => ['id' => $pedido->id, 'numero' => $pedido->numero],
        ]);
    }

    /**
     * El comprobante para imprimir: el cupón que se le entrega al cadete.
     *
     * ⚠️ **No es un PDF, y en el sistema viejo tampoco lo es.** `PdfController@pedido`
     * devuelve una vista HTML con un `window.print()` al cargar, pese al nombre de la ruta
     * y del controlador —el único que de verdad arma un PDF con Dompdf es el listado de
     * cadetes—. Se replica así: abre una pestaña, sale el diálogo de impresión, y el papel
     * queda igual.
     *
     * ⚠️ **Las medidas son las de allá, en centímetros, y no se tocan.** Se imprime sobre
     * un cupón preimpreso: la fecha va corrida 7,5 cm porque ahí está el recuadro. Lo que sí
     * cambia es de dónde sale el CSS: el sistema viejo trae Bootstrap 3 de un CDN, así que
     * sin internet el cupón sale desarmado. Acá las reglas que usaba están escritas en la
     * vista, con los tamaños que Bootstrap 3 les daba.
     *
     * ⚠️ Es una vista Blade y no una pantalla de Inertia, y es la única del panel: una
     * página que se imprime y se cierra no es parte de la aplicación, no lleva su menú y
     * tiene que poder abrirse en otra pestaña.
     */
    public function cupon(Pedido $pedido): View
    {
        // La negativa es 404 y no 403, como el resto: un 403 le confirmaría a un comercio
        // que el pedido de otro existe. Ver `PedidoPolicy::view()`.
        $this->authorize('view', $pedido);

        $pedido->load(['cliente:id,numero,nombre_mostrado', 'cadete:id,numero_movil,apellidos,nombres']);

        return view('cupon', ['pedido' => $pedido]);
    }

    /**
     * Borrar un pedido.
     *
     * ⚠️ Borrado lógico, que es lo que hace el sistema viejo, y no es un detalle: la oficina
     * se deshace así de los pedidos que no llegan al final, de modo que entre los borrados
     * está la mayor parte de los estados intermedios. Ver `show()`.
     */
    public function destroy(Pedido $pedido): RedirectResponse
    {
        $this->authorize('delete', $pedido);

        $pedido->delete();

        return back()->with('success', 'Pedido '.$pedido->numero.' eliminado.');
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
