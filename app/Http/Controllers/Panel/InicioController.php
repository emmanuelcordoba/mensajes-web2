<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Cadete;
use App\Services\ColaDeCadetes;
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
 * ⚠️ **Se está portando por partes y ahora está hecha la primera.** Las otras dos columnas
 * dicen en pantalla que faltan, en vez de no estar: así la pantalla es la de verdad en cada
 * paso y se puede mirar lo que ya anda sin inventar una pantalla intermedia.
 *
 * ⚠️ **Quién ve qué, igual que el sistema viejo.** La primera y la tercera columna son de
 * oficina —`@if(tieneRol(['admin','empleado']))` en `panel.blade.php:15` y `:273`—, y la
 * del medio la ve también un comercio: es la única razón por la que un `restringido` entra
 * a esta pantalla. Acá eso no se pregunta por rol sino por la policy, que es donde ya vive.
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

    public function __invoke(): Response
    {
        $laVeLaOficina = Gate::allows('viewAny', Cadete::class);

        return Inertia::render('inicio/index', [
            // null y no un arreglo vacío: «no te corresponde» y «no hay nadie» son dos
            // cosas distintas, y la pantalla las dibuja distinto.
            'cola' => $laVeLaOficina ? $this->cola() : null,
            'porQueNoLimpiar' => $laVeLaOficina ? ColaDeCadetes::porQueNoLimpiar() : null,
            // Lo que el mapa del detalle necesita para dibujarse. Las constantes son las de
            // la pantalla de Mapa: el centro de la ciudad está escrito en un solo lado.
            'mapa' => $laVeLaOficina ? [
                'centro' => MapaController::CENTRO,
                'zoom' => MapaController::ZOOM,
                // ⚠️ Viaja al navegador porque tiene que viajar: la API de Maps se carga
                // desde el cliente. Lo que la protege es la restricción por dominio.
                'clave' => config('services.google.maps_key'),
            ] : null,
            'refresco' => self::REFRESCO,
        ]);
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
