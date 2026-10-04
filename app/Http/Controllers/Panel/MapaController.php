<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Cadete;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dónde están los cadetes que están trabajando desde la app.
 *
 * Es la pantalla de Mapa del menú lateral viejo. Allá era una `Route::view` con todo el
 * JavaScript adentro de la plantilla y una ruta ajax aparte —`ajax/cadetes/getubicaciones`—
 * que el mapa pedía cada diez segundos; acá es una pantalla con sus props, que se vuelven
 * a pedir solas.
 *
 * ⚠️ **Sólo los que están en la app y tienen posición.** Es PANEL-18: el endpoint viejo
 * filtraba con `whereNotNull('estado', 'ubicacion_lat')`, y el segundo argumento de
 * `whereNotNull` es el booleano, no otra columna. O sea que exigía estado y **no miraba la
 * posición**, y un cadete sin coordenadas llegaba al mapa, que armaba el marcador con
 * `parseFloat(null)`. Está arreglado en el sistema viejo desde el 2026-09-17; acá nace así
 * y hay un test que lo fija.
 *
 * ⚠️ Lo que viaja es el número de móvil, la posición y en qué anda. **No el nombre**, como
 * en el sistema viejo: para ver dónde está un móvil alcanza con su número, y esta pantalla
 * suele quedar abierta en una oficina.
 */
class MapaController extends Controller
{
    /** San Miguel de Tucumán, que es donde trabaja la empresa. */
    public const CENTRO = ['lat' => -26.8083, 'lng' => -65.2176];

    public const ZOOM = 13;

    /** Cada cuánto la pantalla se vuelve a pedir sola, en milisegundos. Como el viejo. */
    public const REFRESCO = 10_000;

    public function __invoke(): Response
    {
        $this->authorize('viewAny', Cadete::class);

        return Inertia::render('mapa/index', [
            'cadetes' => Cadete::query()
                ->whereIn('estado', Cadete::ESTADOS_EN_LA_APP)
                ->whereNotNull('ubicacion_lat')
                ->whereNotNull('ubicacion_lon')
                ->orderBy('numero_movil')
                ->get(['id', 'numero_movil', 'ubicacion_lat', 'ubicacion_lon', 'tipo_vehiculo', 'estado'])
                ->map(fn (Cadete $cadete): array => [
                    'movil' => $cadete->numero_movil,
                    // Son coordenadas, no plata: acá el float es el tipo que corresponde.
                    'lat' => $cadete->ubicacion_lat,
                    'lon' => $cadete->ubicacion_lon,
                    'vehiculo' => $cadete->tipo_vehiculo,
                    'con_pedido' => $cadete->estado === Cadete::ESTADO_CON_PEDIDO_APP,
                ])
                ->all(),
            'centro' => self::CENTRO,
            'zoom' => self::ZOOM,
            'refresco' => self::REFRESCO,
            // ⚠️ La clave viaja al navegador, y tiene que viajar: la API de Maps se carga
            // desde el cliente y la clave va en la URL del script. No es un secreto que se
            // pueda guardar —cualquiera que abra la pantalla la ve—, así que lo que la
            // protege es la restricción por dominio en la consola de Google, no esconderla.
            // Si falta, la pantalla lo dice en vez de dibujar un mapa roto.
            'clave' => config('services.google.maps_key'),
        ]);
    }
}
