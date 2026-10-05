<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\AgregarAColaRequest;
use App\Models\Cadete;
use App\Services\ColaDeCadetes;
use App\Services\IngresoALaCola;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La cola de cadetes, y el mostrador por donde se entra a ella.
 *
 * Es la pantalla «Agregar cadete a cola» del menú lateral viejo. Es la que queda abierta
 * en la oficina: a la izquierda la cola como está, a la derecha el formulario donde el
 * cadete escribe su móvil y su contraseña.
 *
 * ⚠️ Lo que se escribe acá NO abre una sesión. La sesión del navegador es la de la
 * oficina; la contraseña del cadete sólo prueba que el que se pone en la cola es él. Por
 * eso tampoco hay nada del cadete en la respuesta más allá de su número.
 *
 * ⚠️ `orden_cola` es un timestamp Unix en segundos, no una posición: la cola se ordena por
 * hora de llegada. La posición se calcula al mostrarla.
 */
class ColaController extends Controller
{
    /** Cada cuánto la pantalla se vuelve a pedir sola, en milisegundos. */
    public const REFRESCO = 30_000;

    public function index(): Response
    {
        $this->authorize('viewAny', Cadete::class);

        return Inertia::render('cola/index', [
            'cola' => $this->cola(),
            'refresco' => self::REFRESCO,
        ]);
    }

    public function agregar(AgregarAColaRequest $request): RedirectResponse
    {
        $this->authorize('viewAny', Cadete::class);

        // El validador ya comprobó que existe y que la contraseña es la suya.
        $cadete = $request->cadete();

        if ($cadete === null) {
            // No se llega: la regla de `numero_movil` falla antes. Está para que el tipo
            // sea el que es.
            abort(422);
        }

        $motivo = IngresoALaCola::porQueNo($cadete);

        if ($motivo !== null) {
            // ⚠️ Vuelve como `danger` y no como un error de campo: no es que lo que
            // escribió esté mal, es que no puede entrar. El panel viejo usaba el mismo
            // canal, con el mismo criterio.
            return back()->with('danger', $motivo);
        }

        (new IngresoALaCola)->agregar($cadete);

        return back()->with('success',
            'Móvil '.$cadete->numero_movil.' agregado a la cola, en el puesto '
            .$cadete->posicionEnCola().'.');
    }

    /**
     * Mandar un cadete al final de la cola.
     *
     * Se dispara desde la columna «Cadetes activos» de Inicio, no desde el mostrador: el
     * que la aprieta es la oficina. Vive acá porque esta clase es la dueña de la cola.
     */
    public function alFinal(Cadete $cadete): RedirectResponse
    {
        $this->authorize('ordenarCola', Cadete::class);

        $motivo = ColaDeCadetes::porQueNoMandarAlFinal($cadete);

        if ($motivo !== null) {
            return back()->with('danger', $motivo);
        }

        (new ColaDeCadetes)->mandarAlFinal($cadete);

        return back()->with('success',
            'El móvil '.$cadete->numero_movil.' pasó al final de la cola.');
    }

    /** Sacar un cadete de la cola: queda inactivo. Es el botón «Quitar» del detalle. */
    public function sacar(Cadete $cadete): RedirectResponse
    {
        $this->authorize('ordenarCola', Cadete::class);

        $motivo = ColaDeCadetes::porQueNoSacar($cadete);

        if ($motivo !== null) {
            return back()->with('danger', $motivo);
        }

        (new ColaDeCadetes)->sacar($cadete);

        return back()->with('success',
            'El móvil '.$cadete->numero_movil.' salió de la cola.');
    }

    /** Vaciar la cola: todos los que están en ella quedan inactivos. */
    public function limpiar(): RedirectResponse
    {
        $this->authorize('ordenarCola', Cadete::class);

        $motivo = ColaDeCadetes::porQueNoLimpiar();

        if ($motivo !== null) {
            return back()->with('danger', $motivo);
        }

        $cuantos = (new ColaDeCadetes)->limpiar();

        return back()->with('success', $cuantos === 1
            ? 'Salió 1 cadete de la cola.'
            : 'Salieron '.$cuantos.' cadetes de la cola.');
    }

    /**
     * La cola como está, en orden de llegada.
     *
     * ⚠️ Lista explícita de campos: de un cadete activo, esta pantalla necesita su número
     * y en qué anda. La ubicación en vivo, el `fcm_token` y los cinco montos de cobranza
     * no tienen nada que hacer en una pantalla que mira cualquiera que pase por el
     * mostrador.
     *
     * @return array<int, array{posicion: int, movil: int, vehiculo: string, en_la_app: bool}>
     */
    protected function cola(): array
    {
        return Cadete::query()
            ->enLaCola()
            ->get(['id', 'numero_movil', 'tipo_vehiculo', 'estado', 'orden_cola'])
            ->values()
            ->map(fn (Cadete $cadete, int $indice): array => [
                'posicion' => $indice + 1,
                'movil' => $cadete->numero_movil,
                'vehiculo' => Cadete::VEHICULOS[$cadete->tipo_vehiculo] ?? $cadete->tipo_vehiculo,
                'en_la_app' => $cadete->estaEnLaApp(),
            ])
            ->all();
    }
}
