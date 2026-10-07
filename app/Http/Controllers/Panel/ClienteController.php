<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\GuardarClienteRequest;
use App\Models\Cliente;
use App\Services\BajaDeCliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los clientes.
 *
 * Es la pantalla de Clientes del menú lateral viejo, con sus mismos filtros: origen —app,
 * panel o los dos— y una búsqueda sobre un campo elegido.
 *
 * ⚠️ El campo de búsqueda se elige a propósito y no se busca en los cuatro a la vez. Son
 * 11.057 clientes: buscar por un campo usa su índice, y un OR entre cuatro columnas no
 * usa ninguno. Es además lo que la oficina ya sabe usar.
 *
 * ⚠️ Pero se busca por `nombre_mostrado` y no por `nombre`, que es lo que hacía el
 * listado viejo. `nombre` lo escribe el panel y queda vacío en los 7.139 clientes que
 * vinieron de la app, cuyo nombre sale de `nombres` y `apellidos`: buscar por `nombre`
 * no los encontraba nunca. `nombre_mostrado` es la columna generada que resuelve los dos
 * casos, y es la que tiene índice.
 */
class ClienteController extends Controller
{
    /** Cuántos comercios contesta el buscador del alta de pedidos. */
    public const CUANTOS_AL_BUSCAR = 20;

    /** Los campos sobre los que se puede buscar, como en el listado viejo. */
    public const CAMPOS_DE_BUSQUEDA = ['nombre', 'numero', 'direccion', 'telefono'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Cliente::class);

        $origen = $request->string('origen')->value();
        $campo = $request->string('campo')->value();
        $buscado = trim($request->string('buscar')->value());

        if (! in_array($campo, self::CAMPOS_DE_BUSQUEDA, true)) {
            $campo = 'nombre';
        }

        $clientes = Cliente::query()
            ->withCount('pedidos')
            ->when($origen === 'app', fn (Builder $q) => $q->where('plataforma', Cliente::PLATAFORMA_APP))
            // Los cargados desde el panel tienen la columna en NULL.
            ->when($origen === 'panel', fn (Builder $q) => $q->whereNull('plataforma'))
            ->when($buscado !== '', fn (Builder $q) => match ($campo) {
                'numero' => $q->where('numero', ctype_digit($buscado) ? (int) $buscado : -1),
                'nombre' => $q->where('nombre_mostrado', 'ilike', '%'.$buscado.'%'),
                default => $q->where($campo, 'ilike', '%'.$buscado.'%'),
            })
            ->orderBy('nombre_mostrado')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Cliente $cliente): array => [
                'id' => $cliente->id,
                'numero' => $cliente->numero,
                'nombre' => $cliente->nombre_mostrado,
                'direccion' => $cliente->direccion,
                'telefono' => $cliente->telefono,
                'de_la_app' => $cliente->plataforma === Cliente::PLATAFORMA_APP,
                'pedidos' => $cliente->pedidos_count,
                // La misma pregunta que hace el controlador antes de escribir, y además
                // el motivo cuando la respuesta es que no.
                'puede_darse_de_baja' => $request->user()?->can('delete', $cliente) ?? false,
                'por_que_no' => BajaDeCliente::porQueNo($cliente),
            ]);

        return Inertia::render('clientes/index', [
            'clientes' => $clientes,
            'filtros' => [
                'origen' => in_array($origen, ['app', 'panel'], true) ? $origen : '',
                'campo' => $campo,
                'buscar' => $buscado,
            ],
            'campos' => self::CAMPOS_DE_BUSQUEDA,
        ]);
    }

    /**
     * Los comercios que el alta de pedidos ofrece, buscados contra el servidor.
     *
     * ⚠️ **El panel viejo se trae la lista entera.** `ajax/clientes/getAllNombres` manda
     * los 11.057 clientes en cada carga de la pantalla de Inicio —679 KB— y el filtrado lo
     * hace jQuery en el navegador. Acá se busca contra la base, que es donde están.
     *
     * ⚠️ **Se busca también por número, y es lo que la oficina usa.** El campo «Cliente»
     * del alta vieja es el número, y el nombre se completa solo: `cargarClienteByNumero`.
     *
     * ⚠️ Viajan los cinco campos que el formulario completa y ninguno más. El endpoint
     * viejo contestaba `Cliente::where(...)->first()` sin lista de columnas, o sea el
     * documento completo del comercio —token de la app incluido— a cualquiera que supiera
     * un número. Ver SEC-5.
     *
     * @return array<int, array{id: int, numero: int, nombre: string, direccion: string, telefono: string, responsable: string|null}>
     */
    public function buscar(Request $request): array
    {
        $this->authorize('viewAny', Cliente::class);

        $buscado = trim($request->string('q')->value());

        if ($buscado === '') {
            return [];
        }

        return Cliente::query()
            ->where(function (Builder $w) use ($buscado): void {
                // `nombre_mostrado` es la columna generada que ya usa el listado: sirve
                // para los comercios y para las personas, que guardan nombre y apellido
                // por separado.
                $w->where('nombre_mostrado', 'ilike', '%'.$buscado.'%');

                if (ctype_digit($buscado)) {
                    $w->orWhere('numero', (int) $buscado);
                }
            })
            // El número exacto primero: si alguien escribe «820» quiere el comercio 820,
            // no el primero que tenga un 820 en el nombre.
            ->orderByRaw('case when numero = ? then 0 else 1 end', [ctype_digit($buscado) ? (int) $buscado : -1])
            ->orderBy('nombre_mostrado')
            ->orderBy('numero')
            ->limit(self::CUANTOS_AL_BUSCAR)
            ->get(['id', 'numero', 'nombre_mostrado', 'direccion', 'telefono', 'nombre_empleado'])
            ->values()
            ->map(fn (Cliente $cliente): array => [
                'id' => $cliente->id,
                'numero' => $cliente->numero,
                'nombre' => $cliente->nombre_mostrado,
                'direccion' => $cliente->direccion,
                'telefono' => $cliente->telefono,
                // El alta vieja lo pone en «Nombre de Responsable», que es quien atiende.
                'responsable' => $cliente->nombre_empleado,
            ])
            ->all();
    }

    public function create(): Response
    {
        $this->authorize('create', Cliente::class);

        return Inertia::render('clientes/create');
    }

    public function store(GuardarClienteRequest $request): RedirectResponse
    {
        $this->authorize('create', Cliente::class);

        $cliente = new Cliente;
        $this->guardar($cliente, $request);

        // ⚠️ `numero` y `nombre_mostrado` los escribe PostgreSQL —la secuencia y la
        // columna generada—, así que el modelo recién creado no los tiene hasta
        // releerlos. Ver el trait LeeLoQueEscribeLaBase.
        $cliente->refresh();

        return to_route('panel.clientes.index')
            ->with('success', 'Cliente creado con el N° '.$cliente->numero.'.');
    }

    /**
     * Dar de alta un comercio sin salir del alta de pedidos.
     *
     * Es el `modalAltaCliente` del panel viejo, y existe por un motivo concreto: si para
     * cargar un comercio nuevo hay que irse de la pantalla, el pedido que se estaba
     * tipeando se pierde. Por eso contesta el comercio creado y no una redirección: el
     * formulario completa con él los campos de cliente y sigue donde estaba.
     *
     * ⚠️ **La validación y el guardado son los mismos que el alta de la pantalla de
     * Clientes** —`GuardarClienteRequest` y `guardar()`—. Lo único distinto es la forma de
     * la respuesta. En el sistema viejo son dos altas separadas, `ajax/clientes/store` y
     * `ClienteController@store`, cada una con su validación: la del modal pide cuatro
     * campos obligatorios y la de la pantalla cinco reglas, y el número lo controlaba sólo
     * una de las dos.
     *
     * ⚠️ **El número no se pide**, acá tampoco: lo asigna `clientes_numero_seq`. El modal
     * viejo lo precargaba con una sugerencia de `ajax/clientes/getNuevoNumero` que el
     * empleado podía reescribir, y entonces la secuencia no avanzaba. Ver
     * `GuardarClienteRequest`.
     *
     * @return array{id: int, numero: int, nombre: string, direccion: string, telefono: string, responsable: string|null}
     */
    public function crearRapido(GuardarClienteRequest $request): array
    {
        $this->authorize('create', Cliente::class);

        $cliente = new Cliente;
        $this->guardar($cliente, $request);

        // `numero` y `nombre_mostrado` los escribe PostgreSQL. Ver LeeLoQueEscribeLaBase.
        $cliente->refresh();

        return [
            'id' => $cliente->id,
            'numero' => $cliente->numero,
            'nombre' => $cliente->nombre_mostrado,
            'direccion' => $cliente->direccion,
            'telefono' => $cliente->telefono,
            'responsable' => $cliente->nombre_empleado,
        ];
    }

    public function edit(Cliente $cliente): Response
    {
        $this->authorize('update', $cliente);

        return Inertia::render('clientes/edit', [
            'cliente' => [
                'id' => $cliente->id,
                'numero' => $cliente->numero,
                'nombre' => $cliente->nombre,
                'nombre_mostrado' => $cliente->nombre_mostrado,
                'direccion' => $cliente->direccion,
                'telefono' => $cliente->telefono,
                'nombre_empleado' => $cliente->nombre_empleado,
                'nombres' => $cliente->nombres,
                'apellidos' => $cliente->apellidos,
                'de_la_app' => $cliente->plataforma === Cliente::PLATAFORMA_APP,
                // ⚠️ El email de la cuenta, no el token de notificaciones: `fcm_token` no
                // sale de acá. Con ese token se le mandan notificaciones al teléfono de
                // esa persona.
                'cuenta' => $cliente->user?->email,
            ],
        ]);
    }

    public function update(GuardarClienteRequest $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $this->guardar($cliente, $request);

        return to_route('panel.clientes.index')
            ->with('success', 'Cliente actualizado.');
    }

    public function destroy(Cliente $cliente): RedirectResponse
    {
        // La política niega con el motivo cuando el cliente está asignado a una cuenta de
        // panel, así que el mensaje de PANEL-6 llega hasta la pantalla.
        $this->authorize('delete', $cliente);

        $llevo = (new BajaDeCliente)->dar($cliente, request()->user());

        return to_route('panel.clientes.index')
            ->with('success', 'Cliente dado de baja, con sus '.$llevo['pedidos'].' pedidos.');
    }

    /**
     * Escribe los campos que esta pantalla edita, uno por uno.
     *
     * ⚠️ Asignación explícita y no `fill($request->all())`: `fcm_token` y `user_id` están
     * en el `fillable` del modelo, y con un campo de más en el cuerpo se podría mover un
     * cliente de cuenta o pisarle el token. Mover un cliente de cuenta es lo que hace
     * FusionDeClientes, con sus reglas.
     */
    protected function guardar(Cliente $cliente, GuardarClienteRequest $request): void
    {
        $cliente->nombre = $request->string('nombre')->value();
        $cliente->direccion = $request->string('direccion')->value();
        $cliente->telefono = $request->string('telefono')->value();
        $cliente->nombre_empleado = $request->input('nombre_empleado');

        if ($request->filled('numero')) {
            $cliente->numero = $request->integer('numero');
        }

        $cliente->save();
    }
}
