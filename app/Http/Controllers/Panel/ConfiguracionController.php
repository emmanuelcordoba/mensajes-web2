<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\GuardarConfiguracionRequest;
use App\Models\Configuracion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los parámetros que la oficina edita: costos, demora, garantía.
 *
 * ⚠️ El listado viejo nombraba las seis filas de costo una por una, con un `if` por
 * cada una. Acá se piden por una lista de nombres igual —no todas las filas van en esta
 * pantalla— pero en una sola consulta y en un solo lugar.
 *
 * Las dos que faltan tienen su propia pantalla: `TIEMPO_DE_DEMORA` vive en la barra de
 * arriba, porque se cambia varias veces por día y no vale abrir una pantalla para eso, y
 * `COBRANZA_SALDO_PORCENTAJE` va con la cobranza por saldo, que todavía no está.
 */
class ConfiguracionController extends Controller
{
    /**
     * Las que esta pantalla muestra, en el orden en que se leen al cotizar.
     *
     * @var list<string>
     */
    public const EN_LA_PANTALLA = [
        'COSTO_MINIMO',
        'COSTO_POR_KM',
        'COSTO_POR_KM_5KM',
        'COSTO_RETORNO',
        'COSTO_RETORNO_5KM',
        'COSTO_GARANTIA',
    ];

    public function index(): Response
    {
        $this->authorize('viewAny', Configuracion::class);

        $configuraciones = Configuracion::query()
            ->whereIn('nombre', self::EN_LA_PANTALLA)
            ->get()
            // En el orden de la constante y no el de la base: es el orden en que se leen
            // al cotizar, y leerlas en ese orden es lo que hace entendible la pantalla.
            ->sortBy(function (Configuracion $c): int {
                $posicion = array_search($c->nombre, self::EN_LA_PANTALLA, true);

                // No puede pasar: la consulta filtra por esta misma lista. Si alguna vez
                // pasara, va al final en lugar de al principio, que es lo que haría un
                // `(int)` sobre `false`.
                return $posicion === false ? count(self::EN_LA_PANTALLA) : $posicion;
            })
            ->values()
            ->map(fn (Configuracion $c): array => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'descripcion' => $c->descripcion,
                'valor' => $c->valor,
                'es_numerica' => $c->esNumerica(),
            ])
            ->all();

        return Inertia::render('configuraciones/index', [
            'configuraciones' => $configuraciones,
        ]);
    }

    public function update(
        GuardarConfiguracionRequest $request,
        Configuracion $configuracion,
    ): RedirectResponse {
        $this->authorize('update', $configuracion);

        // ⚠️ Sólo si el formulario la manda. La pantalla de cobranza por saldo guarda el
        // porcentaje desde su propio formulario, que no edita la descripción: sin esta
        // guarda, guardar el porcentaje desde allá le borraba el texto que explica qué es.
        if ($request->has('descripcion')) {
            $configuracion->descripcion = $request->input('descripcion');
        }

        // El valor vacío del tiempo de demora se guarda como null, no como cadena vacía:
        // el CHECK de la columna rechaza la cadena vacía en las numéricas, y para las de
        // texto «sin indicar» es la ausencia de valor.
        $configuracion->valor = $request->filled('valor')
            ? $request->string('valor')->value()
            : null;
        $configuracion->save();

        return back()->with('success', $configuracion->titulo.' actualizado.');
    }
}
