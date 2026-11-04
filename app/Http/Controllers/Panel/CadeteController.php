<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\BloquearCadeteRequest;
use App\Http\Requests\Panel\GuardarCadeteRequest;
use App\Models\Cadete;
use App\Models\MotivoBloqueo;
use App\Services\AltaDeCadete;
use App\Services\BajaDeCadete;
use App\Services\BorradoDefinitivoDeCadete;
use App\Services\ColaDeCadetes;
use App\Services\NotificacionesAlTelefono;
use App\Support\Momento;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as RespuestaHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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
            // El botón de dados de baja es de administradores. Lo decide la política y no
            // un `if` de rol en la pantalla, como en `pedidos/index`.
            'puede_ver_dados_de_baja' => Gate::allows('verDadosDeBaja', Cadete::class),
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
     * Los cadetes dados de baja, y lo único que se puede hacer con ellos.
     *
     * Es el botón «Dados de baja» del listado viejo, que allá es de administradores por la
     * ruta y por un `@if` en la vista. Mismo criterio que `pedidos/eliminados`: una
     * pantalla aparte y no un filtro del listado, porque las dos listas se usan distinto
     * —una es el trabajo del día y la otra es administrativa— y porque las acciones de cada
     * fila no son las mismas.
     *
     * ⚠️ **Acá no se restaura, y es la diferencia con los pedidos eliminados.** Lo único
     * que se ofrece es el borrado definitivo: sacar la fila. Ver
     * `BorradoDefinitivoDeCadete`, que explica qué se lleva y qué deja.
     *
     * ⚠️ El usuario se carga con `withTrashed()`: la baja de un cadete da de baja su
     * cuenta, así que la relación normal devolvería null para todas las filas y la columna
     * diría «sin cuenta» justo en el caso normal. Es la misma advertencia que dejó escrita
     * la pantalla vieja.
     *
     * Se ordena por fecha de baja descendente: lo último que se dio de baja es lo que
     * alguien está buscando cuando entra acá.
     */
    public function dadosDeBaja(): Response
    {
        $this->authorize('verDadosDeBaja', Cadete::class);

        $cadetes = Cadete::onlyTrashed()
            ->with(['user' => fn ($consulta) => $consulta->withTrashed()->select('id', 'email', 'deleted_at')])
            ->orderByDesc('deleted_at')
            ->paginate(10)
            ->through(fn (Cadete $cadete): array => [
                'id' => $cadete->id,
                'numero_movil' => $cadete->numero_movil,
                'nombre' => $cadete->nombre_completo,
                'baja' => Momento::fecha($cadete->deleted_at),
                // ⚠️ Tres estados y no dos. Lo normal es que la cuenta esté dada de baja
                // con el cadete; que siga activa es un estado raro que conviene ver antes
                // de apretar, porque es la señal de que algo más la está usando.
                'cuenta' => $cadete->user === null
                    ? null
                    : ['email' => $cadete->user->email, 'de_baja' => $cadete->user->trashed()],
                // Lo que arrastra el borrado, para que la confirmación lo diga. Son cinco
                // subconsultas por fila y la página trae diez: es una pantalla que se abre
                // a propósito, no una que se refresca sola.
                'arrastra' => BorradoDefinitivoDeCadete::queArrastra($cadete),
                'borrado' => self::decision('forceDelete', $cadete),
            ]);

        return Inertia::render('cadetes/dados-de-baja', ['cadetes' => $cadetes]);
    }

    /**
     * Sacar del padrón, de verdad, a un cadete dado de baja.
     *
     * ⚠️ **PIDE ESCRIBIR EL NÚMERO DE MÓVIL, y no es un adorno.** No se deshace, y es lo
     * único del panel que saca filas. En el sistema viejo la confirmación es una pantalla
     * entera de Corrección de datos que mide el impacto y pide lo mismo; acá las
     * correcciones no existen —son previas a la migración— así que la confirmación vive en
     * el diálogo del listado, con los mismos números y la misma pregunta.
     *
     * ⚠️ La ruta lleva `withTrashed()` o el binding no encuentra nada: por definición el
     * cadete está dado de baja.
     */
    public function borradoDefinitivo(Request $request, Cadete $cadete): RedirectResponse
    {
        // La política niega con el motivo cuando la cuenta está en uso, así que el mensaje
        // llega hasta la pantalla y no es un 403 a secas.
        $this->authorize('forceDelete', $cadete);

        $movil = $cadete->numero_movil;

        if (trim($request->string('confirmacion')->value()) !== (string) $movil) {
            return back()->with('danger', 'Para confirmar hay que escribir el número de móvil '.$movil.'.');
        }

        try {
            $hecho = (new BorradoDefinitivoDeCadete)->ejecutar($cadete, $request->user());
        } catch (RuntimeException $e) {
            // La política ya preguntó lo mismo; esto es la red por si algo cambió entre la
            // pantalla y el botón.
            return back()->with('danger', 'Móvil '.$movil.': no se borró. '.$e->getMessage());
        }

        return to_route('panel.cadetes.dados-de-baja')->with('success',
            'Móvil '.$movil.' borrado definitivamente. Quedaron sin cadete '.$hecho['pedidos_sin_cadete']
            .' pedidos y '.$hecho['logs_sin_cadete'].' registros de su historial; se borraron '
            .$hecho['mensajes'].' mensajes, '.$hecho['actividades'].' tramos de actividad, '
            .$hecho['cobranza'].' movimientos de cobranza'
            .($hecho['postulacion'] > 0 ? ', su postulación' : '')
            .($hecho['cuenta'] ? ' y su cuenta.' : '.'));
    }

    /**
     * El listado para imprimir: el padrón en papel, con el teléfono de cada uno.
     *
     * Es `pdf/cadetes` del sistema viejo, y **éste sí es un PDF de verdad** —a diferencia
     * del cupón del pedido, que pese al nombre de la ruta allá es una página que se imprime
     * sola—. Se arma con Dompdf, igual que allá, y sale con las mismas columnas y el mismo
     * orden: por número de móvil.
     *
     * ⚠️ **Son todos los del padrón y no lo que esté filtrado en la pantalla.** El listado
     * viejo tampoco pasa el buscador: lo que se imprime y se cuelga en la oficina es la
     * lista entera, hoy 234 personas.
     *
     * ⚠️ **El CSS está escrito en la vista, y allá viene de un CDN.** La vista vieja trae
     * Bootstrap 4 de jsdelivr con `enable_remote`, así que sin internet el listado sale sin
     * estilos —y de paso cada impresión es una petición a un tercero desde el servidor—.
     * Acá están las reglas que usaba, con las medidas que Bootstrap les daba, y por eso
     * `setIsRemoteEnabled(false)`: la vista no pide nada de afuera.
     */
    public function imprimir(): RespuestaHttp
    {
        $this->authorize('viewAny', Cadete::class);

        $cadetes = Cadete::query()
            ->orderBy('numero_movil')
            ->get(['numero_movil', 'apellidos', 'nombres', 'telefono', 'tipo_vehiculo']);

        // Sin archivo de configuración: son tres opciones y se leen mejor acá. `enable_php`
        // y `enable_remote` quedan en false, que es lo que corresponde cuando la vista es
        // nuestra y no trae nada de afuera.
        $opciones = new Options;
        $opciones->setChroot(base_path());
        $opciones->setDefaultPaperSize('a4');
        $opciones->setDpi(96);

        $dompdf = new Dompdf($opciones);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml(view('listado-de-cadetes', ['cadetes' => $cadetes])->render());
        $dompdf->render();

        // El mismo nombre que allá, con la fecha y la hora adentro: se imprime seguido y
        // así dos archivos guardados no se pisan.
        //
        // ⚠️ Sin barras ni dos puntos, que allá sí tiene: Windows no los acepta en un
        // nombre de archivo, y lo que hace el navegador con eso es cosa suya.
        $cuando = str_replace(['/', ':'], '-', (string) Momento::fechaYHora(now()));

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            // `inline`: se abre en otra pestaña y se imprime desde ahí, como allá.
            'Content-Disposition' => 'inline; filename="Listado de Cadetes - '.$cuando.'.pdf"',
        ]);
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
