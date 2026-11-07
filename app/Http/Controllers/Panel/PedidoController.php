<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\AsignarCadeteRequest;
use App\Http\Requests\Panel\GuardarPedidoRequest;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\Rol;
use App\Services\AltaDePedido;
use App\Services\AsignacionDeCadete;
use App\Support\MarcaDeImpresion;
use App\Support\Momento;
use App\Support\NumeroBuscado;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
     * Los siete estados que el listado muestra: todos menos «Sin asignar».
     *
     * ⚠️ Es el recorte del sistema viejo, y tiene sentido: un pedido sin asignar está
     * esperando en la lista de espera de Inicio, que es donde se lo atiende. Si
     * apareciera también acá, la oficina lo vería dos veces y en dos órdenes distintos.
     *
     * @var list<string>
     */
    private const ESTADOS_DEL_LISTADO = [
        Pedido::ESTADO_COTIZADO,
        Pedido::ESTADO_ACEPTADO,
        Pedido::ESTADO_ASIGNADO,
        Pedido::ESTADO_EN_CURSO,
        Pedido::ESTADO_RECHAZADO,
        Pedido::ESTADO_CANCELADO,
        Pedido::ESTADO_FINALIZADO,
    ];

    /** Las diez del sistema viejo. */
    private const POR_PAGINA = 10;

    /**
     * El listado de pedidos: el `/pedidos` del sistema viejo, con su mismo criterio.
     *
     * ⚠️ **NO ES LA LISTA DE ESPERA**, y hasta el 2026-11-03 lo era: esta pantalla
     * mostraba `esperandoCadete()`, que es la tercera columna de Inicio. O sea que la
     * cola estaba dos veces y el listado general no estaba en ninguna parte. El sistema
     * viejo tiene las dos cosas separadas —`/panel` la cola, `/pedidos` el listado— y
     * acá vuelven a estarlo.
     *
     * El criterio, tal como está en `PedidoController@index` del sistema viejo:
     *
     * - **El último mes**, o el rango que venga en `desde`/`hasta`.
     * - Los siete estados de `ESTADOS_DEL_LISTADO`.
     * - Del más nuevo al más viejo, de a diez.
     * - Con búsqueda por cliente, por direcciones o por cadete.
     *
     * ⚠️ **Un comercio es otra consulta, no la misma con un filtro más.** El sistema
     * viejo le muestra TODOS sus pedidos: sin recorte de estado y sin recorte de fecha,
     * y sin formulario de búsqueda. Replicarlo importa, porque un comercio que entra a
     * ver un pedido de hace tres meses lo encuentra.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Pedido::class);

        $usuario = $request->user();
        $esComercio = $usuario !== null && $usuario->tieneRol(Rol::RESTRINGIDO);

        [$desde, $hasta] = $this->rango($request);
        $buscar = trim((string) $request->query('buscar', ''));
        $campo = trim((string) $request->query('campo', ''));

        $consulta = Pedido::query()
            ->alcanzablesPor($usuario)
            // Sin esto el listado hace una consulta por pedido para el nombre del
            // cliente y otra para el cadete. El modelo viejo lo resolvía con $with en
            // TODA consulta, que es el otro extremo.
            ->with(['cliente:id,nombre_mostrado', 'cadete:id,numero_movil,nombres,apellidos'])
            ->orderByDesc('created_at');

        if (! $esComercio) {
            $consulta
                ->whereIn('estado', self::ESTADOS_DEL_LISTADO)
                ->whereBetween('created_at', [$desde, $hasta]);

            if ($buscar !== '') {
                $consulta->where($this->filtro($campo, $buscar));
            }
        }

        $pedidos = $consulta
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (Pedido $pedido): array => $this->fila($pedido));

        return Inertia::render('pedidos/index', [
            'pedidos' => $pedidos,
            'filtros' => [
                'buscar' => $buscar,
                'campo' => $campo,
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            // Un comercio no tiene formulario de búsqueda, igual que en el panel viejo:
            // ve todos los suyos y no hay nada que recortar.
            'puede_buscar' => ! $esComercio,
            // Y tampoco ve el botón de los borrados, que es administrativo.
            'puede_ver_eliminados' => $request->user()?->can('verEliminados', Pedido::class) ?? false,
        ]);
    }

    /**
     * El rango de fechas: el que venga, o el último mes.
     *
     * ⚠️ Una fecha mal escrita cae al rango por defecto en lugar de reventar. El sistema
     * viejo hace `Carbon::createFromFormat` sin red, así que `?desde=hola` devuelve un
     * 500. No es un criterio distinto: es el mismo criterio sin la excepción.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function rango(Request $request): array
    {
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        if (is_string($desde) && is_string($hasta) && $desde !== '' && $hasta !== '') {
            try {
                return [
                    CarbonImmutable::createFromFormat('Y-m-d', $desde)->startOfDay(),
                    CarbonImmutable::createFromFormat('Y-m-d', $hasta)->endOfDay(),
                ];
            } catch (InvalidFormatException) {
                // Cae al rango por defecto.
            }
        }

        return [
            CarbonImmutable::now()->subMonth()->startOfDay(),
            CarbonImmutable::now()->endOfDay(),
        ];
    }

    /**
     * La búsqueda, con los mismos cuatro casos del sistema viejo.
     *
     * Allá se traen todos los pedidos del rango y se filtran en PHP con `stripos`; acá
     * es `ilike`, que dice lo mismo y lo resuelve la base. Sobre el último mes son 7.023
     * filas: traerlas para descartarlas no se nota hoy y se nota el día que el rango sea
     * un año.
     *
     * ⚠️ **«Direcciones» mira dirección Y destino; la búsqueda sin campo mira sólo
     * dirección.** Parece un descuido del sistema viejo y puede que lo sea, pero es lo
     * que hace, y el formulario siempre manda un campo: al `default` sólo se llega a
     * mano, por la URL.
     */
    private function filtro(string $campo, string $texto): Closure
    {
        $como = '%'.addcslashes($texto, '%_\\').'%';

        return match ($campo) {
            'cliente' => $this->porCliente($como),
            'direccion' => fn (Builder $q) => $q
                ->where('direccion', 'ilike', $como)
                ->orWhere('destino', 'ilike', $como),
            'cadete' => $this->porCadete($como, $texto),
            default => function (Builder $q) use ($como, $texto): void {
                $q->where('direccion', 'ilike', $como)
                    ->orWhere($this->porCliente($como))
                    ->orWhere($this->porCadete($como, $texto));
            },
        };
    }

    /**
     * Por nombre de cliente.
     *
     * ⚠️ El pedido que TIENE cliente se busca por el nombre del cliente; el que no lo
     * tiene, por el nombre suelto que quedó en la fila. Es un «o» entre dos mundos que
     * no se pisan, no un «o» entre dos columnas: un pedido con cliente no se encuentra
     * por `nombre_cliente` ni allá ni acá.
     *
     * ⚠️ Y busca por `nombre_mostrado`, que es **la única diferencia deliberada con el
     * sistema viejo**. Allá se busca por `clientes.nombre`, que un cliente de la app
     * tiene en null —el suyo son `nombres` y `apellidos`—, así que esos clientes no se
     * encontraban nunca. `nombre_mostrado` es la columna generada que resuelve los dos,
     * y es la que esta misma pantalla muestra en la columna Cliente: buscar por otra
     * cosa que la que se ve es lo que no tiene defensa.
     */
    private function porCliente(string $como): Closure
    {
        return function (Builder $q) use ($como): void {
            $q->whereHas('cliente', fn (Builder $c) => $c->where('nombre_mostrado', 'ilike', $como))
                ->orWhere(fn (Builder $q) => $q
                    ->whereDoesntHave('cliente')
                    ->where('nombre_cliente', 'ilike', $como));
        };
    }

    /**
     * Por cadete: nombres, apellidos o número de móvil exacto.
     *
     * ⚠️ El móvil sólo se compara cuando lo que se buscó son dígitos. El sistema viejo
     * hace `$cadete->numero_movil == $buscar`, y en PHP 7.4 `0 == "juan"` es verdadero:
     * buscar un nombre traería de prepo a los cadetes con móvil 0. Hoy no hay ninguno
     * —se midió—, así que esto no cambia ningún resultado; cambia que no pueda empezar
     * a cambiarlos.
     */
    private function porCadete(string $como, string $texto): Closure
    {
        return fn (Builder $q) => $q->whereHas('cadete', function (Builder $c) use ($como, $texto): void {
            $c->where('nombres', 'ilike', $como)
                ->orWhere('apellidos', 'ilike', $como);

            if (($movil = NumeroBuscado::deTexto($texto)) !== null) {
                $c->orWhere('numero_movil', $movil);
            }
        });
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

            // Si alguien ya dejó marcado que este pedido se imprime al asignarlo. Ver
            // `MarcaDeImpresion`: no es del pedido, es una nota de la oficina.
            'imprimir' => MarcaDeImpresion::puesta($pedido),
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

        // Ya hay a quién darle el papel: la marca cumplió y se saca, imprima o no el
        // navegador que mandó esto. Si no se asignó a nadie, el pedido sigue esperando y
        // la marca con él.
        if ($cadete !== null) {
            MarcaDeImpresion::sacar($pedido);
        }

        return back()->with('success', $cadete === null
            ? 'Pedido guardado.'
            : 'Pedido '.$pedido->numero.' asignado al móvil '.$cadete->numero_movil.'.');
    }

    /**
     * Dejar marcado —o desmarcado— que al asignarle el cadete se imprime el cupón.
     *
     * ⚠️ **Lo que imprime es el navegador, no esto.** Acá sólo se recuerda la decisión, para
     * que siga estando cuando el pedido se asigne más tarde, o lo asigne otra persona. Ver
     * `MarcaDeImpresion`, que explica por qué va a la caché y no a la base.
     */
    public function impresion(Request $request, Pedido $pedido): RedirectResponse
    {
        // El mismo permiso que asignar: la marca no dice nada del pedido, dice qué va a
        // pasar cuando se lo asignen, y eso lo decide quien puede asignarlo.
        $this->authorize('asignarCadete', $pedido);

        $datos = $request->validate(['imprimir' => ['required', 'boolean']]);

        MarcaDeImpresion::poner($pedido, (bool) $datos['imprimir']);

        return back();
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
     * Los pedidos borrados, para poder recuperarlos.
     *
     * ⚠️ **Es de oficina, no del grupo compartido.** En el sistema viejo la ruta vive en
     * `rol:empleado,admin` y el botón está gateado con el mismo `@if`. Un comercio puede
     * borrar los suyos pero no ve esta pantalla: recuperar un pedido es administración.
     *
     * ⚠️ Y acá importa más de lo que parece que exista: la oficina se deshace de los
     * pedidos que no llegan al final borrándolos, así que **la mayor parte de los estados
     * intermedios está entre los borrados**. Sin esta pantalla, un borrado por error no
     * tiene vuelta desde el panel.
     */
    public function eliminados(): Response
    {
        $this->authorize('verEliminados', Pedido::class);

        $pedidos = Pedido::onlyTrashed()
            ->with(['cliente:id,nombre_mostrado', 'cadete:id,numero_movil,nombres,apellidos'])
            ->orderByDesc('created_at')
            ->paginate(self::POR_PAGINA)
            ->through(fn (Pedido $pedido): array => $this->fila($pedido));

        return Inertia::render('pedidos/eliminados', ['pedidos' => $pedidos]);
    }

    /**
     * Devolver un pedido borrado a la lista.
     *
     * ⚠️ El sistema viejo no comprueba a quién pertenece —`Ajax\PedidoController@restaurar`
     * hace `withTrashed()->findOrFail()` y `restore()`, nada más—; lo único que lo cuida es
     * que la ruta esté en el grupo de oficina. Acá lo dice además la política, que es donde
     * se lo puede leer.
     */
    public function restaurar(Pedido $pedido): RedirectResponse
    {
        $this->authorize('restore', $pedido);

        $pedido->restore();

        return back()->with('success', 'Pedido '.$pedido->numero.' restaurado.');
    }

    /**
     * Borrar los pedidos tildados en el listado.
     *
     * ⚠️ **Todo o nada, como el sistema viejo.** Allá se recorren dos veces: la primera
     * comprueba el alcance de todos y contesta 404 al primero que no corresponde, y la
     * segunda recién borra. Si fuera una sola pasada, un id ajeno en el medio dejaría
     * borrada la mitad de la selección y la otra mitad no, sin forma de saber cuál.
     */
    public function destroyMasivo(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'pedidos' => ['required', 'array', 'min:1'],
            'pedidos.*' => ['integer'],
        ]);

        $pedidos = Pedido::query()->whereKey($datos['pedidos'])->get();

        // Un id que no existe es 404 y no se borra nada, igual que el `findOrFail` de allá.
        abort_unless($pedidos->count() === count(array_unique($datos['pedidos'])), 404);

        foreach ($pedidos as $pedido) {
            $this->authorize('delete', $pedido);
        }

        foreach ($pedidos as $pedido) {
            $pedido->delete();
        }

        $cuantos = $pedidos->count();

        return back()->with('success', $cuantos === 1
            ? 'Se eliminó 1 pedido.'
            : 'Se eliminaron '.$cuantos.' pedidos.');
    }

    /**
     * Una fila del listado, que es la misma en los pedidos y en los eliminados.
     *
     * @return array<string, mixed>
     */
    private function fila(Pedido $pedido): array
    {
        return [
            'id' => $pedido->id,
            'numero' => $pedido->numero,
            'estado' => $pedido->estado,
            'cuando' => Momento::fechaYHora($pedido->created_at),
            'cliente' => $pedido->nombreDelCliente(),
            'direccion' => $pedido->direccion,
            'destino' => $pedido->destino,
            'telefono' => $pedido->telefono,
            // ⚠️ Los dos montos viajan como cadena. Son NUMERIC(12,2) y castearlos a
            // float es donde se pierden los centavos. Hay una prueba que lo fija.
            'garantia' => $pedido->garantia,
            'valor' => $pedido->valor,
            'movil' => $pedido->cadete?->numero_movil,
            'cadete' => $pedido->cadete?->nombre_completo,
            // El sistema viejo no ofrece borrar un pedido finalizado: no dibuja el botón
            // ni la casilla. Lo decide el servidor para que la pantalla no tenga que
            // saber cuál de los ocho estados es el final.
            'se_puede_borrar' => $pedido->estado !== Pedido::ESTADO_FINALIZADO,
            // ⚠️ El cupón es el papel que se le entrega AL CADETE, así que sin cadete no
            // hay nada que imprimir: el listado viejo dibuja el botón dentro de un
            // `@if($pedido->cadete)`. Va como bandera propia y no mirando si `movil`
            // vino nulo, que da la misma respuesta hoy por casualidad.
            'se_puede_imprimir' => $pedido->cadete !== null,
        ];
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
