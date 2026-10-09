<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\EnviarMensajeRequest;
use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\User;
use App\Services\ConversacionConElCadete;
use App\Support\Momento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El chat entre la central y los cadetes. Son 8.543 mensajes en 444 conversaciones.
 *
 * Es la pantalla de Chat del menú lateral viejo —`MensajeController@index`, que sí existe
 * y sí funciona—. Lo que PANEL-19 nombraba era otro método, `chat()`, que no tenía ruta ni
 * vista y se borró del sistema viejo el 2026-09-17: esa entrada dice, con todas las letras,
 * que «la conversación del panel es `index()`».
 *
 * ## Quién escribió cada mensaje
 *
 * ⚠️ Se compara `mensajes.user_id` con `cadetes.user_id`: si coinciden, lo escribió el
 * cadete. Son dos columnas, no dos relaciones. La vista vieja preguntaba
 * `$mensaje->cadete->user->id == $mensaje->user->id`, que además de traer tres relaciones
 * por fila **revienta con 500 si la cuenta del cadete está dada de baja** —`User` usa
 * bajas lógicas y ahí `cadete->user` es null—. Contra los datos: de los 8.543 mensajes,
 * 5.440 los escribió el cadete y 3.103 la central; ninguno queda sin clasificar.
 *
 * ## Las consultas
 *
 * ⚠️ Tres consultas fijas, no una más por conversación. El `index` viejo hacía
 * `Mensaje::...->groupBy('cadete_id')->get()` y después, **por cada conversación**, otra
 * consulta contando los no leídos. Con 444 conversaciones eso es 445 consultas por carga,
 * y la pantalla se recarga sola cada cinco segundos.
 *
 * ⚠️ Y no trae `Cadete::all()`. El `<select>` viejo listaba los 1.768 cadetes para elegir
 * a quién escribirle; acá se buscan contra el servidor, como los comercios en cuentas.
 */
class MensajeController extends Controller
{
    /** Cuántos mensajes de la conversación se muestran sin pedirla entera. */
    public const ULTIMOS = 100;

    /** Cada cuánto la pantalla se vuelve a pedir sola, en milisegundos. Como el viejo. */
    public const REFRESCO = 5_000;

    public function __construct(protected ConversacionConElCadete $conversacion) {}

    public function index(Request $request, ?Cadete $cadete = null): Response
    {
        $this->authorize('viewAny', Mensaje::class);

        // ⚠️ Abrir una conversación la marca como leída en la web, como en el panel viejo.
        // Los dos «leído» son una regla con dos lados y viven juntos en el servicio, con el
        // de la app: ver `ConversacionConElCadete`.
        if ($cadete !== null) {
            $this->conversacion->losLeyoLaCentral($cadete);
        }

        return Inertia::render('mensajes/index', [
            'conversaciones' => $this->conversaciones(),
            'abierta' => $cadete === null
                ? null
                : $this->conversacion($cadete, $request->boolean('completa')),
            'refresco' => self::REFRESCO,
        ]);
    }

    public function enviar(EnviarMensajeRequest $request, Cadete $cadete): RedirectResponse
    {
        $this->authorize('create', Mensaje::class);

        $cuenta = $request->user();

        // La ruta está detrás de `auth` y la política ya corrió, así que acá hay cuenta; el
        // tipo de `$request->user()` no lo sabe. Antes esto era `$request->user()?->id`
        // sobre una columna NOT NULL, o sea un null que la base iba a rechazar igual.
        abort_unless($cuenta instanceof User, 403);

        $this->conversacion->escribeLaCentral(
            $cadete,
            $cuenta,
            $request->string('mensaje')->value(),
        );

        // ⚠️ Acá NO se manda la notificación al teléfono que mandaba el sistema viejo: la
        // app no está portada y no hay nada en este sistema que hable con Firebase. El
        // mensaje se guarda igual, que es lo que la app lee al abrir el chat.
        return back();
    }

    /**
     * Los cadetes a los que se les puede escribir, buscados contra el servidor.
     *
     * Es su propia ruta porque la pide el buscador de «nueva conversación». No se mandan
     * los 1.768 en la página, que es lo que hacía el `<select>` viejo.
     */
    public function cadetes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Mensaje::class);

        $buscado = trim($request->string('q')->value());

        $encontrados = Cadete::query()
            ->when($buscado !== '', fn (Builder $q) => $q->where(
                function (Builder $w) use ($buscado): void {
                    $w->where('apellidos', 'ilike', '%'.$buscado.'%')
                        ->orWhere('nombres', 'ilike', '%'.$buscado.'%');

                    if (ctype_digit($buscado)) {
                        $w->orWhere('numero_movil', (int) $buscado);
                    }
                },
            ))
            ->orderBy('numero_movil')
            ->limit(20)
            ->get(['id', 'numero_movil', 'apellidos', 'nombres'])
            ->map(fn (Cadete $cadete): array => [
                'id' => $cadete->id,
                'nombre' => 'Móvil '.$cadete->numero_movil.' · '.$cadete->nombre_completo,
            ])
            ->all();

        return response()->json($encontrados);
    }

    /**
     * La lista de conversaciones: el último mensaje de cada una y cuántos faltan leer.
     *
     * ⚠️ Dos consultas, no una por conversación. `DISTINCT ON` es de PostgreSQL y es
     * exactamente lo que hace falta: el último mensaje por `cadete_id`. Los no leídos
     * salen de un `GROUP BY` que usa el índice parcial `idx_mensajes_no_leidos_web`.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function conversaciones(): array
    {
        /** @var Collection<int, int> $sinLeer */
        $sinLeer = Mensaje::query()
            ->sinLeerEnLaWeb()
            ->selectRaw('cadete_id, count(*) AS cuantos')
            ->groupBy('cadete_id')
            ->pluck('cuantos', 'cadete_id');

        return Mensaje::query()
            ->whereIn('id', function ($consulta): void {
                $consulta->selectRaw('DISTINCT ON (cadete_id) id')
                    ->from('mensajes')
                    ->whereNull('deleted_at')
                    ->orderBy('cadete_id')
                    ->orderByDesc('created_at');
            })
            // ⚠️ Sólo las de cadetes vigentes, que es lo que hacía el `->has('cadete')`
            // del listado viejo. Y no es un caso raro: 407 de las 444 conversaciones son
            // de cadetes dados de baja —`Cadete` usa bajas lógicas—, así que sin esto la
            // pantalla responde 500 al primer intento.
            //
            // ⚠️ El refresco del panel viejo NO lo filtraba: `Ajax\MensajeController::
            // getMensajes()` arma `$ultimos_mensajes` sin `has('cadete')`, y su JavaScript
            // hace `mensaje.cadete.numero_movil`. O sea que cada cinco segundos esa lista
            // se rompía con un TypeError que el `catch` mandaba a la consola. Acá las dos
            // lecturas son la misma función.
            ->whereHas('cadete')
            ->with('cadete:id,numero_movil,apellidos,nombres,user_id')
            ->orderByDesc('created_at')
            ->get(['id', 'cadete_id', 'user_id', 'mensaje', 'created_at'])
            // El cadete está, por el `whereHas`; el filtro es para que un cambio en esa
            // consulta no se convierta otra vez en un 500.
            ->filter(fn (Mensaje $mensaje): bool => $mensaje->cadete !== null)
            ->map(fn (Mensaje $mensaje): array => [
                'cadete_id' => $mensaje->cadete_id,
                'movil' => $mensaje->cadete->numero_movil,
                'nombre' => $mensaje->cadete->nombre_completo,
                'mensaje' => $mensaje->mensaje,
                'cuando' => Momento::hace($mensaje->created_at),
                'del_cadete' => $mensaje->user_id === $mensaje->cadete->user_id,
                'sin_leer' => (int) ($sinLeer[$mensaje->cadete_id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * La conversación abierta.
     *
     * ⚠️ Los últimos 100, no todos. El panel viejo traía la conversación entera en cada
     * carga y en cada refresco —la más larga tiene 351 mensajes—. Quien necesite el
     * historial completo lo pide, y entonces sí viene entero.
     *
     * @return array<string, mixed>
     */
    protected function conversacion(Cadete $cadete, bool $completa): array
    {
        $total = Mensaje::query()->where('cadete_id', $cadete->id)->count();

        $mensajes = Mensaje::query()
            ->where('cadete_id', $cadete->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->when(! $completa, fn (Builder $q) => $q->limit(self::ULTIMOS))
            ->get(['id', 'user_id', 'mensaje', 'created_at'])
            // Se piden del más nuevo al más viejo para quedarse con los últimos, y se dan
            // vuelta para leerlos como una conversación.
            ->reverse()
            ->values()
            ->map(fn (Mensaje $mensaje): array => [
                'id' => $mensaje->id,
                'mensaje' => $mensaje->mensaje,
                'cuando' => Momento::fechaYHora($mensaje->created_at),
                'del_cadete' => $mensaje->user_id === $cadete->user_id,
            ])
            ->all();

        return [
            'cadete' => [
                'id' => $cadete->id,
                'movil' => $cadete->numero_movil,
                'nombre' => $cadete->nombre_completo,
            ],
            'mensajes' => $mensajes,
            'total' => $total,
            'completa' => $completa,
            'anteriores' => $completa ? 0 : max(0, $total - count($mensajes)),
        ];
    }
}
