<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Cadete;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Services\ColaDeCadetes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La pantalla de Inicio del panel: la que la oficina mira todo el día.
 *
 * Son tres columnas, y son las mismas tres que el panel viejo:
 *
 *   1. **Cadetes activos** — la cola, con lo que la central puede hacerle.
 *   2. **Nuevo pedido** — el formulario de carga.
 *   3. **Lista de espera** — los pedidos que esperan cadete.
 *
 * ⚠️ **Quién ve qué, igual que el sistema viejo.** La primera y la tercera columna son de
 * oficina —`@if(tieneRol(['admin','empleado']))` en `panel.blade.php:15` y `:273`—, y la
 * del medio la ve también un comercio: es la única razón por la que un `restringido` entra
 * a esta pantalla. Acá eso no se pregunta por rol sino por la policy, que es donde ya vive.
 *
 * ⚠️ Y la del medio además **cambia** según quién mire: un comercio no pone precio, no
 * elige cadete y no tiene el autocompletado de direcciones ni el mapa, porque en el panel
 * viejo todo eso está dentro del mismo `@if`. Ver `PedidoPolicy::ponerElPrecio()`.
 *
 * ⚠️ **El detalle de un cadete no sale de acá.** Lo pide la pantalla con un `fetch` a
 * `CadeteController@detalle`, que devuelve JSON. Ver el comentario de `inicio/index.tsx`:
 * qué diálogo está abierto es estado de la pantalla, no de la dirección, y pasarlo por la
 * URL dejaba el parámetro puesto al cerrar.
 */
class InicioController extends Controller
{
    /**
     * Cada cuánto la pantalla se vuelve a pedir sola, en milisegundos.
     *
     * ⚠️ Cinco segundos, que es lo que hace el panel viejo: un `setInterval` de 5.000 que
     * llama a `recargarCadetesActivos()` y `recargarListaDeEspera()`. Acá es un refresco
     * parcial de Inertia —se vuelve a pedir `cola`, no la pantalla entera—, así que cuesta
     * menos que las dos llamadas de allá.
     */
    public const REFRESCO = 5_000;

    public function __invoke(Request $request): Response
    {
        $usuario = $request->user();

        if ($usuario === null) {
            // No se llega: la ruta está detrás de `auth`. Está para que el tipo sea el que es
            // y no haya que pasarle un `?User` a algo que necesita un usuario.
            abort(403);
        }

        // ⚠️ Dos puertas y no una, porque son dos preguntas. La cola es de cadetes; la lista
        // de espera es de pedidos. Hoy las dos contestan lo mismo —admin y empleado—, que es
        // el recorte del panel viejo, pero si mañana una cambia, la otra no tiene por qué.
        $laCola = Gate::allows('viewAny', Cadete::class);
        $laEspera = Gate::allows('verListaDeEspera', Pedido::class);
        // Y la tercera: si el que mira es de la central. Es lo que decide qué tiene el
        // formulario del medio —precio, cadete, mapa, autocompletado— y es la misma
        // pregunta que el `@if` del panel viejo. Ver `PedidoPolicy::ponerElPrecio()`.
        $deLaOficina = Gate::allows('ponerElPrecio', Pedido::class);

        return Inertia::render('inicio/index', [
            // null y no un arreglo vacío: «no te corresponde» y «no hay nadie» son dos
            // cosas distintas, y la pantalla las dibuja distinto.
            'cola' => $laCola ? $this->cola() : null,
            'porQueNoLimpiar' => $laCola ? ColaDeCadetes::porQueNoLimpiar() : null,
            'espera' => $laEspera ? $this->espera($usuario) : null,
            // Los cadetes que el modal de la espera ofrece: son datos de cadetes, así que
            // pasan por la puerta de cadetes.
            'paraAsignar' => $laCola ? $this->paraAsignar() : null,
            // Lo que el mapa del detalle necesita para dibujarse. Las constantes son las de
            // la pantalla de Mapa: el centro de la ciudad está escrito en un solo lado.
            'mapa' => $deLaOficina ? [
                'centro' => MapaController::CENTRO,
                'zoom' => MapaController::ZOOM,
                // ⚠️ Viaja al navegador porque tiene que viajar: la API de Maps se carga
                // desde el cliente. Lo que la protege es la restricción por dominio.
                'clave' => config('services.google.maps_key'),
            ] : null,
            // Lo usan los dos mapas de esta pantalla —el del detalle de un cadete y el del
            // viaje del alta— y el autocompletado de direcciones, así que cuelga de la
            // puerta de la central y no de la de la cola.
            'deLaOficina' => $deLaOficina,
            /*
             * El pedido que se acaba de cargar, para poder imprimir su comprobante.
             *
             * ⚠️ Sale de la sesión y no de las props de siempre: el botón aparece **sólo**
             * después de cargar uno, que es lo que hace el panel viejo con su
             * `@if(session('pedido'))`. Vive acá y no en las props compartidas porque es de
             * esta pantalla: ninguna otra imprime un cupón.
             */
            'ultimoPedido' => $request->session()->get('ultimoPedido'),
            'refresco' => self::REFRESCO,
            // El selector de la barra de arriba. Ver `tiempoDeDemora()`.
            'tiempoDeDemora' => self::tiempoDeDemora($usuario),
        ]);
    }

    /**
     * El tiempo de demora que la barra de arriba deja cambiar.
     *
     * Es una fila de `configuraciones` que la oficina toca varias veces por día —cuando
     * hay demora, cuando se normaliza—, así que el sistema viejo la puso en la barra en
     * lugar de hacer abrir una pantalla.
     *
     * ⚠️ **VIVE ACÁ Y NO EN LAS PROPS COMPARTIDAS, Y POR ESO SE VE SÓLO EN ESTA PANTALLA.**
     * Es lo que hace el panel viejo, aunque el selector esté dibujado en la barra: la barra
     * lo envuelve en un `@isset($tiempo_de_demora)` y la única pantalla que define esa
     * variable es `PanelController`, la portada. En el resto del panel el `isset` da false
     * y el selector no existe. Cuando esto se compartía, el selector salía en las catorce
     * pantallas y además costaba una consulta en cada una.
     *
     * ⚠️ Y sólo para quien puede cambiarlo: un comercio no la hace. Es una fila buscada por
     * una columna UNIQUE; si alguna vez pesa, lo que corresponde es cachearla.
     *
     * @return array{id: int, valor: string|null, opciones: list<string>}|null
     */
    private static function tiempoDeDemora(User $usuario): ?array
    {
        if (! $usuario->tieneRol([Rol::ADMIN, Rol::EMPLEADO])) {
            return null;
        }

        $configuracion = Configuracion::query()
            ->where('nombre', 'TIEMPO_DE_DEMORA')
            ->first(['id', 'valor']);

        if ($configuracion === null) {
            return null;
        }

        return [
            'id' => $configuracion->id,
            'valor' => $configuracion->valor,
            'opciones' => Configuracion::TIEMPOS_DE_DEMORA,
        ];
    }

    /**
     * La lista de espera: los pedidos que esperan un cadete.
     *
     * El orden no es cosmético y está en `Pedido::scopeEsperandoCadete()`: los resueltos
     * —cancelado, rechazado— arriba y por fecha descendente, y abajo los que esperan, el más
     * viejo primero, que es el que hay que atender.
     *
     * ⚠️ **Sin paginar, como el panel viejo.** Es una cola de trabajo que la oficina vacía
     * en el día: hoy son 36 filas. Si alguna vez fueran cientos, el problema sería la cola,
     * no la pantalla.
     *
     * ⚠️ Lo que viaja es lo que se ve en la fila. El teléfono, el responsable y el detalle
     * salen sólo cuando alguien abre el modal, por `PedidoController@detalle`: es la misma
     * decisión que la cola de cadetes y el mismo motivo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function espera(User $user): array
    {
        return Pedido::query()
            // ⚠️ **No cambia ninguna respuesta y va igual.** `alcanzablesPor` sólo filtra
            // para el rol `restringido`, y a un comercio esta columna no se le manda: medido
            // contra la base de desarrollo, admin y empleado reciben exactamente los mismos
            // pedidos con el scope y sin él. Es lo mismo que hace el `/pedidos` del panel
            // viejo, que filtra por `cliente_id` sólo en la rama de `restringido`.
            //
            // Va igual porque en este sistema los listados de pedidos filtran EN LA CONSULTA
            // y no con la policy —una policy responde por una fila—, y porque el día que
            // alguien le muestre esta columna a un comercio, la decisión ya está tomada acá.
            // Ver SEC-7 y `PedidoController@index`. Hay un test que fija la igualdad.
            ->alcanzablesPor($user)
            ->esperandoCadete()
            // Sin esto el listado hace dos consultas por pedido. El modelo viejo lo
            // resolvía con `$with` en TODA consulta, que es el otro extremo.
            ->with(['cliente:id,nombre_mostrado', 'user:id,name,iniciales,color'])
            ->get()
            ->values()
            ->map(fn (Pedido $pedido): array => [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                // ⚠️ Los últimos tres dígitos, como el panel viejo: en una columna angosta
                // el número entero no entra y lo que la oficina se dice en voz alta son los
                // tres últimos. El completo va en el `title`.
                'numero_corto' => $pedido->numero === null
                    ? null
                    : mb_substr((string) $pedido->numero, -3),
                'cliente' => $pedido->nombreDelCliente(),
                'direccion' => $pedido->direccion,
                'destino' => $pedido->destino,
                'estado' => $pedido->estado,
                'plataforma' => $pedido->plataforma_origen,
                // Quién lo cargó, en dos letras y con su color: es cómo cada persona de la
                // oficina ve cuáles son sus pedidos sin leer nada. Ver `User::distintivo()`.
                'distintivo' => $pedido->user?->distintivo(),
            ])
            ->all();
    }

    /**
     * Los cadetes que el alta y el modal ofrecen: la cola arriba, y abajo el resto.
     *
     * El orden no es decorativo: de la cola sale el 97,1% de los pedidos web, así que lo
     * primero que se ve es lo que casi siempre se elige. Abajo va el padrón, por móvil,
     * esté el cadete activo o no: se le asigna a alguien fuera de la cola cuando avisa por
     * teléfono o pasa por el mostrador, y antes había que buscarlo para que apareciera.
     *
     * ⚠️ **Los bloqueados no están, y es la única exclusión.** Ver `scopeOfrecibles`. El
     * selector del panel viejo hacía lo contrario y se contradecía solo: al cargar la
     * pantalla tenía los 134 cadetes no bloqueados y cinco segundos después
     * `recargarCadetesActivos()` lo reemplazaba por `Cadete::all()` —220, con los 86 que
     * tienen el usuario bloqueado adentro—. O sea que el selector se ensanchaba al primer
     * refresco y recién entonces dejaba elegir a alguien bloqueado.
     *
     * ⚠️ **También se filtra la cola, y no es por prolijidad:** bloquear no saca de la cola
     * —`IngresoALaCola` sólo niega la ENTRADA—, así que un cadete bloqueado mientras
     * esperaba sigue ahí. Se sigue mostrando en la cola de la pantalla, que es el estado
     * real, pero no se ofrece para asignarle un pedido.
     *
     * ⚠️ **Lista explícita de campos, y por eso esto se puede mandar en cada refresco.** De
     * los 143 ofrecibles viajan cinco valores cada uno: unos 10 KB, contra los 186 KB que
     * manda el panel viejo cada cinco segundos con los documentos completos de los 220. La
     * pantalla está abierta todo el día en la oficina.
     *
     * @return array<int, array{id: int, movil: int, nombre: string, en_la_app: bool, en_la_cola: bool}>
     */
    protected function paraAsignar(): array
    {
        $campos = ['id', 'numero_movil', 'nombres', 'apellidos', 'estado', 'orden_cola'];

        $enLaCola = Cadete::query()->enLaCola()->ofrecibles()->get($campos);

        // El resto: todos los demás ofrecibles, por móvil, que es por donde se los nombra.
        $afuera = Cadete::query()
            ->ofrecibles()
            ->whereNotIn('id', $enLaCola->modelKeys())
            ->orderBy('numero_movil')
            ->get($campos);

        return $enLaCola
            ->map(fn (Cadete $cadete): array => self::comoSeOfrece($cadete, true))
            ->concat($afuera->map(fn (Cadete $cadete): array => self::comoSeOfrece($cadete, false)))
            ->values()
            ->all();
    }

    /**
     * Un cadete como lo necesita el selector, y nada más que eso.
     *
     * @return array{id: int, movil: int, nombre: string, en_la_app: bool, en_la_cola: bool}
     */
    protected static function comoSeOfrece(Cadete $cadete, bool $enLaCola): array
    {
        return [
            'id' => $cadete->id,
            'movil' => $cadete->numero_movil,
            'nombre' => trim($cadete->nombres.' '.$cadete->apellidos),
            'en_la_app' => $cadete->estaEnLaApp(),
            'en_la_cola' => $enLaCola,
        ];
    }

    /**
     * La cola como está, en orden de llegada.
     *
     * ⚠️ Lista explícita de campos y **sin el nombre**. De la cola, esta columna muestra
     * móvil y vehículo, que es lo que mostraba el panel viejo; el nombre y el resto salen
     * sólo cuando alguien abre el detalle, y por eso van por otro lado. La pantalla queda
     * abierta en la oficina todo el día, a la vista de quien pase.
     *
     * @return array<int, array{id: int, movil: int, vehiculo: string, vehiculo_entero: string, en_la_app: bool}>
     */
    protected function cola(): array
    {
        return Cadete::query()
            ->enLaCola()
            ->get(['id', 'numero_movil', 'tipo_vehiculo', 'estado', 'orden_cola'])
            ->values()
            ->map(fn (Cadete $cadete): array => [
                'id' => $cadete->id,
                'movil' => $cadete->numero_movil,
                'vehiculo' => Cadete::VEHICULOS_CORTOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo,
                'vehiculo_entero' => Cadete::VEHICULOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo,
                'en_la_app' => $cadete->estaEnLaApp(),
            ])
            ->all();
    }
}
