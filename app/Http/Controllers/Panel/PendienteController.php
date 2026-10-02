<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Support\Navegacion;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Una pantalla del panel que todavía no está hecha.
 *
 * El menú está completo desde el principio a propósito: así se ve el panel entero y se
 * discute lo que falta mirándolo, en lugar de ir descubriendo entradas una por una. Lo
 * que no está hecho lo dice la pantalla, no el menú.
 *
 * ⚠️ Cada pantalla pendiente tiene su ruta propia y con su nombre definitivo
 * —`panel.clientes.index`, no una ruta genérica con un parámetro—. Cuando la pantalla
 * exista, lo único que cambia es a qué controlador apunta esa línea: los enlaces del
 * menú, los tests y cualquier cosa que ya use `route()` siguen sirviendo. Una ruta
 * genérica tipo `panel/{seccion}` habría ahorrado quince líneas y después habría que
 * cambiarlas todas.
 *
 * El rol lo sigue controlando el middleware de cada grupo, no esto: una pantalla que no
 * muestra nada todavía igual está detrás de la puerta que le corresponde, así que el
 * día que muestre algo no hay que acordarse de cerrarla.
 */
class PendienteController extends Controller
{
    public function __invoke(): Response
    {
        // Cómo se llama la pantalla lo dice el menú, que es donde ya está escrito.
        $ubicacion = Navegacion::ubicar((string) Route::currentRouteName());

        return Inertia::render('panel/pendiente', [
            'titulo' => $ubicacion['titulo'] ?? 'Esta pantalla',
            'dentroDe' => $ubicacion['dentroDe'] ?? null,
        ]);
    }
}
