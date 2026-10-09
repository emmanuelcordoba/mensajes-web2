<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\BloquearCadeteRequest;
use App\Http\Requests\Panel\GuardarCadeteRequest;
use App\Models\Cadete;
use App\Models\MotivoBloqueo;
use App\Services\AltaDeCadete;
use App\Services\BajaDeCadete;
use App\Services\ColaDeCadetes;
use App\Services\NotificacionesAlTelefono;
use App\Support\Momento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los cadetes.
 *
 * Es la pantalla de Cadetes del menú lateral viejo, con sus mismas acciones: editar,
 * ponerlo en la cola o sacarlo, bloquearlo, y darlo de baja. Son 1.768, 220 activos.
 *
 * ⚠️ UN CADETE NO ES UNA FILA: `cadetes.user_id` es NOT NULL, así que todo cadete tiene la
 * cuenta con la que entra a la app, y el alta escribe las dos. Eso vive en
 * `App\Services\AltaDeCadete` —en una transacción— y no acá, que es también de donde lo
 * toma el panel de /admin: una regla, dos pantallas.
 *
 * ⚠️ La baja vive en `App\Services\BajaDeCadete` por lo mismo, y lo interesante de ella no
 * es lo que se lleva sino lo que deja: **los pedidos no se tocan**. Son pedidos de otros
 * que el cadete entregó, con su valor y su garantía.
 *
 * ⚠️ Lo que viaja al frontend es una lista explícita de campos y no el modelo. `cadetes`
 * tiene la ubicación en vivo, el `fcm_token` con el que se le manda una notificación al
 * teléfono de esa persona, y los cinco montos de cobranza.
 *
 * ## Lo que esta pantalla NO edita, y es a propósito
 *
 * El estado se cambia desde el listado y no desde el formulario, como en el panel viejo:
 * ponerlo en la cola es una acción, no un campo. Y la modalidad de cobranza no se toca acá
 * —cambiarla a mitad de una cobranza mueve plata entre `monto_deuda` y `cobranza_saldo`—:
 * eso es de las pantallas de cobranzas, que todavía no están. Ver PANEL-8.
 */
class CadeteController extends Controller
{
    /** Cuántos cadetes contesta el buscador: lo que cabe en una lista sin scroll. */
    public const CUANTOS_AL_BUSCAR = 20;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Cadete::class);

        $buscado = trim($request->string('buscar')->value());

        $cadetes = Cadete::query()
            // `bloqueado` es la clave foránea del motivo: sin ella en el select, la
            // relación anidada no carga. Y sin las dos, la política preguntaría por fila.
            ->with(['user:id,email,bloqueado', 'user.motivoBloqueo:codigo,descripcion'])
            // Para poder decir qué se lleva la baja antes de apretarla. Son dos
            // subconsultas en la consulta de la página, no dos por fila.
            ->withCount(['pedidos', 'mensajes'])
            ->when($buscado !== '', fn (Builder $q) => $q->where(
                function (Builder $w) use ($buscado): void {
                    $w->where('apellidos', 'ilike', '%'.$buscado.'%')
                        ->orWhere('nombres', 'ilike', '%'.$buscado.'%');

                    // ⚠️ Por móvil SÓLO si lo que se escribió es un número. El listado
                    // viejo hacía `orWhere('numero_movil', intval($buscar))` suelto, y
                    // `intval` sobre un texto da 0: buscar «juan» traía además al móvil 0,
                    // si existiera. Es el mismo descuido que en la búsqueda de comercios.
                    if (ctype_digit($buscado)) {
                        $w->orWhere('numero_movil', (int) $buscado);
                    }
                },
            ))
            // ⚠️ Ascendente. El listado viejo ordenaba `nombre_completo` **descendente**,
            // que es lo contrario de lo que hacen las otras pantallas y lo contrario de lo
            // que una lista de personas necesita. Y `nombre_completo` allá era un campo
            // guardado; acá es un atributo calculado, así que se ordena por las columnas.
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Cadete $cadete): array => [
                'id' => $cadete->id,
                'numero_movil' => $cadete->numero_movil,
                'nombre' => $cadete->nombre_completo,
                'email' => $cadete->user?->email,
                'telefono' => $cadete->telefono,
                'vehiculo' => Cadete::VEHICULOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo,
                'estado' => Cadete::ETIQUETAS_DE_ESTADO[$cadete->estado] ?? $cadete->estado,
                // La misma pregunta que hace el modelo al alternar el estado, así que el
                // botón dice lo que va a pasar y no «cambiar estado».
                'trabajando' => $cadete->estaTrabajando(),
                'bloqueo' => $cadete->user?->motivoBloqueo?->descripcion,
                'pedidos' => $cadete->pedidos_count,
                'mensajes' => $cadete->mensajes_count,
                // Las mismas preguntas que hace el controlador antes de escribir, y el
                // motivo cuando la respuesta es que no.
                'cambiar_estado' => self::decision('cambiarEstado', $cadete),
                'bloquear' => self::decision('bloquear', $cadete),
                'baja' => self::decision('delete', $cadete),
            ]);

        return Inertia::render('cadetes/index', [
            'cadetes' => $cadetes,
            'filtros' => ['buscar' => $buscado],
            'motivos' => self::motivos(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Cadete::class);

        return Inertia::render('cadetes/create', [
            'vehiculos' => Cadete::VEHICULOS,
            // Sólo para precargar el campo: no hay secuencia y no debe haberla, porque en
            // la práctica se recicla el número del cadete que se va. Ver PANEL-5.
            'siguiente_movil' => Cadete::siguienteNumeroMovil(),
        ]);
    }

    public function store(GuardarCadeteRequest $request): RedirectResponse
    {
        $this->authorize('create', Cadete::class);

        // ⚠️ `validated()` y no `all()`: lo que llega es exactamente lo que el validador
        // nombró. El servicio arma cada una de las dos filas con una lista explícita, y el
        // rol de la cuenta lo pone él y no el formulario. Ver SEC-4.
        $cadete = (new AltaDeCadete)->alta($request->validated());

        return to_route('panel.cadetes.index')
            ->with('success', 'Cadete creado con el móvil N° '.$cadete->numero_movil.'.');
    }

    public function edit(Cadete $cadete): Response
    {
        $this->authorize('update', $cadete);

        return Inertia::render('cadetes/edit', [
            'vehiculos' => Cadete::VEHICULOS,
            'cadete' => [
                'id' => $cadete->id,
                'numero_movil' => $cadete->numero_movil,
                'nombre_completo' => $cadete->nombre_completo,
                'nombres' => $cadete->nombres,
                'apellidos' => $cadete->apellidos,
                'direccion' => $cadete->direccion,
                'telefono' => $cadete->telefono,
                'fecha_nacimiento' => $cadete->fecha_nacimiento->toDateString(),
                'dni' => $cadete->dni,
                'tipo_vehiculo' => $cadete->tipo_vehiculo,
                'observaciones' => $cadete->observaciones,
                'tiene_monto_semanal_personal' => $cadete->tiene_monto_semanal_personal,
                'estado' => Cadete::ETIQUETAS_DE_ESTADO[$cadete->estado] ?? $cadete->estado,
                'modalidad_cobranza' => $cadete->modalidad_cobranza,
                // Null cuando la cuenta está dada de baja: el formulario entonces no
                // ofrece email ni contraseña, porque no habría dónde escribirlos.
                'cuenta' => $cadete->user?->email,
            ],
        ]);
    }

    public function update(GuardarCadeteRequest $request, Cadete $cadete): RedirectResponse
    {
        $this->authorize('update', $cadete);

        // Dos filas, como en el alta: una transacción para que un email que choca contra el
        // índice no deje los datos del cadete escritos y la cuenta sin tocar.
        DB::transaction(function () use ($request, $cadete): void {
            // ⚠️ Asignación explícita y no `fill($request->all())`. En el sistema viejo la
            // edición llenaba el Cadete y el User con todo lo que viniera en el formulario,
            // y como `rol_id` y `password` están en el `fillable` de User alcanzaba con
            // agregar `rol_id` al cuerpo para convertir la cuenta de un cadete en
            // administrador. Ver SEC-4. Acá además `fillable` incluye los cinco montos de
            // cobranza, el `fcm_token` y la ubicación en vivo.
            $cadete->numero_movil = $request->integer('numero_movil');
            $cadete->apellidos = $request->string('apellidos')->value();
            $cadete->nombres = $request->string('nombres')->value();
            $cadete->direccion = $request->string('direccion')->value();
            $cadete->telefono = $request->string('telefono')->value();
            $cadete->fecha_nacimiento = $request->date('fecha_nacimiento');
            $cadete->tipo_vehiculo = $request->string('tipo_vehiculo')->value();
            $cadete->observaciones = $request->input('observaciones');
            $cadete->tiene_monto_semanal_personal = $request->boolean('tiene_monto_semanal_personal');
            // Vacío es null y no 0: la columna es nullable a propósito.
            $cadete->dni = $request->filled('dni') ? $request->integer('dni') : null;
            $cadete->save();

            $cuenta = $cadete->user;

            if ($cuenta === null) {
                return;
            }

            $cuenta->email = $request->string('email')->value();

            // En blanco significa «no la toques». El cast `hashed` del modelo la encripta.
            if ($request->filled('password')) {
                $cuenta->password = $request->string('password')->value();
            }

            $cuenta->save();
        });

        return to_route('panel.cadetes.index')->with('success', 'Cadete actualizado.');
    }

    public function destroy(Cadete $cadete): RedirectResponse
    {
        // La política niega con el motivo cuando la cobranza está abierta, así que el
        // mensaje llega hasta la pantalla y no es un 403 a secas.
        $this->authorize('delete', $cadete);

        $movil = $cadete->numero_movil;
        $pedidos = $cadete->pedidos()->count();

        $llevo = (new BajaDeCadete)->dar($cadete, request()->user());

        return to_route('panel.cadetes.index')->with('success',
            'Móvil '.$movil.' dado de baja, con sus '.$llevo['mensajes'].' mensajes'
            .($llevo['postulacion'] ? ', su postulación' : '')
            .' y su cuenta. Sus '.$pedidos.' pedidos se conservan en el historial.');
    }

    /**
     * Los cadetes a los que se les puede dar un pedido, buscados contra el servidor.
     *
     * La cola ya está en la pantalla y se refresca sola; esto es para el resto. Son las
     * dos mitades del selector de cadetes del alta y del modal de la lista de espera: la
     * cola arriba, porque es de donde sale el 97% de los pedidos, y abajo quien haga falta.
     *
     * ⚠️ **Sin los bloqueados, y el sistema viejo los ofrecía.** Allá el primer dibujado de
     * la pantalla llena el selector con los no bloqueados —134 hoy— y cinco segundos después
     * `recargarCadetesActivos()` lo reemplaza por `Cadete::all()`, que son 220 e incluye a
     * los 86 que tienen el usuario bloqueado. O sea que el selector se ensancha al primer
     * refresco y se le podía asignar un pedido a alguien bloqueado. Esa es la única parte
     * del comportamiento viejo que no se replica, y es a propósito.
     *
     * ⚠️ **Contra el servidor y no en la página.** El panel viejo manda los documentos
     * completos de los 220 cadetes cada cinco segundos: 186 KB por refresco, en una
     * pantalla que está abierta todo el día. Es el mismo arreglo que ya se hizo en el
     * buscador de Mensajes.
     *
     * @return array<int, array{id: int, movil: int, nombre: string, en_la_app: bool, en_la_cola: bool}>
     */
    public function buscar(Request $request): array
    {
        $this->authorize('viewAny', Cadete::class);

        $buscado = trim($request->string('q')->value());

        // Con el campo vacío no se contesta nada: lo que hay que mirar entonces es la cola,
        // que la pantalla ya tiene. Así tampoco se manda el padrón entero al navegador.
        if ($buscado === '') {
            return [];
        }

        return Cadete::query()
            // La cuenta bloqueada no puede recibir pedidos, y por eso se pregunta por el
            // usuario y no sólo por el cadete.
            ->whereHas('user', fn (Builder $u) => $u->whereNull('bloqueado'))
            ->where(function (Builder $w) use ($buscado): void {
                $w->where('apellidos', 'ilike', '%'.$buscado.'%')
                    ->orWhere('nombres', 'ilike', '%'.$buscado.'%');

                // Por móvil sólo si se escribió un número: `intval('juan')` es 0 y traía
                // al móvil 0. Es el mismo descuido del listado viejo.
                if (ctype_digit($buscado)) {
                    $w->orWhere('numero_movil', (int) $buscado);
                }
            })
            ->orderBy('numero_movil')
            ->limit(self::CUANTOS_AL_BUSCAR)
            ->get(['id', 'numero_movil', 'apellidos', 'nombres', 'estado'])
            ->values()
            ->map(fn (Cadete $cadete): array => [
                'id' => $cadete->id,
                'movil' => $cadete->numero_movil,
                'nombre' => $cadete->nombre_completo,
                'en_la_app' => $cadete->estaEnLaApp(),
                'en_la_cola' => $cadete->estaActivo(),
            ])
            ->all();
    }

    /**
     * Los datos de un cadete, en JSON, para el diálogo de detalle de Inicio.
     *
     * ⚠️ **Es la única acción del panel que contesta JSON y no una pantalla de Inertia**, y
     * es a propósito. Son el nombre, el teléfono y la dirección de una persona: se traen
     * recién cuando alguien abre el detalle y no en cada refresco de una pantalla que está
     * abierta todo el día en una oficina. Es lo mismo que hacía el panel viejo con
     * `ajax/cadetes/getcadete`, y no pasarlo por las props además evita que el diálogo
     * dependa de un parámetro en la URL. Ver el comentario de `inicio/index.tsx`.
     *
     * ⚠️ La ubicación sale **sólo si está en la app y tiene las dos coordenadas**, igual que
     * el panel viejo: un cadete puesto en la cola desde la central no manda posición, y el
     * mapa del diálogo dice «Cadete sin ubicación» en vez de dibujar un marcador inventado.
     * Es la misma trampa de PANEL-18, que en el mapa grande llegó a producción.
     *
     * Los dos motivos van con los datos para que el diálogo no dibuje un botón que iba a
     * fallar: son las mismas funciones que `ColaController` consulta antes de escribir.
     *
     * @return array<string, mixed>
     */
    public function detalle(Cadete $cadete): array
    {
        $this->authorize('viewAny', Cadete::class);

        $enLaApp = $cadete->estaEnLaApp();
        $tieneUbicacion = $cadete->ubicacion_lat !== null && $cadete->ubicacion_lon !== null;

        return [
            'id' => $cadete->id,
            'movil' => $cadete->numero_movil,
            'apellidos' => $cadete->apellidos,
            'nombres' => $cadete->nombres,
            'email' => $cadete->user?->email,
            'direccion' => $cadete->direccion,
            'telefono' => $cadete->telefono,
            'nacimiento' => Momento::fecha($cadete->fecha_nacimiento),
            'vehiculo' => Cadete::VEHICULOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo,
            'estado' => Cadete::ETIQUETAS_DE_ESTADO[$cadete->estado] ?? $cadete->estado,
            'posicion' => $cadete->posicionEnCola(),
            'ubicacion' => $enLaApp && $tieneUbicacion ? [
                'movil' => $cadete->numero_movil,
                'lat' => $cadete->ubicacion_lat,
                'lon' => $cadete->ubicacion_lon,
                'vehiculo' => $cadete->tipo_vehiculo,
                'con_pedido' => $cadete->estado === Cadete::ESTADO_CON_PEDIDO_APP,
            ] : null,
            'por_que_no_al_final' => ColaDeCadetes::porQueNoMandarAlFinal($cadete),
            'por_que_no_sacar' => ColaDeCadetes::porQueNoSacar($cadete),
        ];
    }

    /**
     * Ponerlo en la cola, o sacarlo.
     *
     * ⚠️ El panel viejo no decía nada cuando no hacía nada: si el cadete estaba bloqueado,
     * el controlador redirigía igual y el estado quedaba como estaba. Acá eso lo decide la
     * política, que además dice por qué, y el listado ni dibuja el botón.
     */
    public function cambiarEstado(Cadete $cadete): RedirectResponse
    {
        $this->authorize('cambiarEstado', $cadete);

        $cadete->alternarEstadoDesdeLaCentral();
        $cadete->save();

        return back()->with('success', 'El móvil '.$cadete->numero_movil.' quedó '
            .mb_strtolower(Cadete::ETIQUETAS_DE_ESTADO[$cadete->estado] ?? $cadete->estado).'.');
    }

    /**
     * Bloquear la cuenta del cadete, con su motivo, y avisarle al teléfono.
     *
     * ⚠️ **Y lo saca de la cola.** El bloqueo viejo hace `$cadete->estado = INACTIVO` junto
     * con el `bloqueado` de la cuenta, y acá eso faltaba: `IngresoALaCola` sólo niega la
     * ENTRADA, así que un cadete bloqueado mientras estaba activo se quedaba en la cola con
     * su `orden_cola` y el mostrador podía seguir dándole pedidos. De paso el tramo de
     * actividad abierto se cierra solo —lo hace el modelo al guardar—, que es el agujero de
     * PANEL-17 por el que allá queda abierto para siempre.
     *
     * ⚠️ El desbloqueo NO lo vuelve a poner activo, como en el sistema viejo: a la cola
     * vuelve desde su app, o lo pone la central.
     */
    public function bloquear(BloquearCadeteRequest $request, Cadete $cadete): RedirectResponse
    {
        $this->authorize('bloquear', $cadete);

        $cuenta = $cadete->user;

        if ($cuenta === null) {
            // No se llega: la política niega la acción cuando no hay cuenta. Está para que
            // el tipo sea el que es, no por desconfianza.
            abort(403);
        }

        $motivo = $request->string('motivo')->value();

        $mensaje = $motivo === MotivoBloqueo::OTRO_MOTIVO
            ? $request->string('mensaje')->value()
            : null;

        $cuenta->bloqueado = $motivo;
        $cuenta->bloqueado_mensaje = $mensaje;
        $cuenta->save();

        $cadete->estado = Cadete::ESTADO_INACTIVO;
        $cadete->save();

        NotificacionesAlTelefono::bloqueado($cadete->fcm_token, $motivo, $mensaje);

        return back()->with('success', 'Móvil '.$cadete->numero_movil.' bloqueado.');
    }

    /**
     * Desbloquearlo.
     *
     * ⚠️ Se limpia también el mensaje. El desbloqueo viejo ponía `bloqueado` en null y
     * dejaba `bloqueado_mensaje` escrito, así que el motivo del bloqueo anterior quedaba
     * colgado en la cuenta y reaparecía en el siguiente bloqueo que no lo escribiera.
     */
    public function desbloquear(Cadete $cadete): RedirectResponse
    {
        $this->authorize('bloquear', $cadete);

        $cuenta = $cadete->user;

        if ($cuenta === null) {
            abort(403);
        }

        $cuenta->bloqueado = null;
        $cuenta->bloqueado_mensaje = null;
        $cuenta->save();

        NotificacionesAlTelefono::desbloqueado($cadete->fcm_token);

        return back()->with('success', 'Móvil '.$cadete->numero_movil.' desbloqueado.');
    }

    /**
     * Si se puede, y si no, por qué no.
     *
     * Es la forma de que el listado pregunte **exactamente** lo que el controlador aplica.
     * `Gate::inspect` devuelve la `Response` de la política, con su mensaje; para un método
     * que devuelve bool el mensaje es null y no hay nada que mostrar.
     *
     * @return array{permitido: bool, motivo: string|null}
     */
    protected static function decision(string $accion, Cadete $cadete): array
    {
        $respuesta = Gate::inspect($accion, $cadete);

        return [
            'permitido' => $respuesta->allowed(),
            'motivo' => $respuesta->allowed() ? null : $respuesta->message(),
        ];
    }

    /**
     * Los motivos de bloqueo, que son un catálogo cerrado de tres filas.
     *
     * @return array<int, array{codigo: string, descripcion: string, pide_mensaje: bool}>
     */
    protected static function motivos(): array
    {
        return MotivoBloqueo::query()
            ->orderBy('codigo')
            ->get(['codigo', 'descripcion'])
            ->map(fn (MotivoBloqueo $motivo): array => [
                'codigo' => $motivo->codigo,
                'descripcion' => $motivo->descripcion,
                'pide_mensaje' => $motivo->codigo === MotivoBloqueo::OTRO_MOTIVO,
            ])
            ->all();
    }
}
