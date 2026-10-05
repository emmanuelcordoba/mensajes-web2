<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Support\Mes;
use App\Support\Momento;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estadísticas: cuántos pedidos se terminaron cada día del mes.
 *
 * Es la pantalla de Estadísticas del menú viejo, que es una sola cosa —una tabla de fecha y
 * cantidad con un selector de mes—. Acá es la misma cuenta, con la tabla dibujada y el mes
 * resumido arriba.
 *
 * ⚠️ **Una consulta y no una por día.** El controlador viejo hace un `count()` adentro de un
 * `while` sobre los días del mes: treinta consultas para dibujar treinta filas. Medido contra
 * los 898.189 pedidos finalizados de la base de desarrollo, con el índice de `created_at`
 * puesto: **166 ms las treinta consultas, 15 ms un `group by`**. Once veces.
 *
 * ⚠️ **Cuenta por `created_at`, que es cuándo se cargó el pedido y no cuándo se terminó.** Es
 * lo que cuenta el sistema viejo y es lo único que `pedidos` permite contar: la tabla no
 * guarda la fecha en que el pedido pasó a Finalizado. Eso está en `logs_estados_pedidos`, que
 * tiene 3.637.456 filas; contar por ahí es otra pantalla y otra decisión, no una mejora al
 * pasar. Lo que esta pantalla dice, entonces, es cuántos de los pedidos cargados ese día
 * terminaron bien.
 *
 * Y el mes —de dónde sale, qué pasa si no es un mes y por qué no se lee con
 * `createFromFormat('Y-m')`— está en `App\Support\Mes`.
 */
class EstadisticasController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('verEstadisticas', Pedido::class);

        // `query()` devuelve lo que venga, y lo que venga puede ser un arreglo: `?mes[]=x`.
        $pedido = $request->query('mes');
        $mes = Mes::pedido(is_string($pedido) ? $pedido : null);

        $porDia = Pedido::query()
            ->where('estado', Pedido::ESTADO_FINALIZADO)
            ->where('created_at', '>=', $mes->primerDia())
            ->where('created_at', '<', $mes->despuesDelUltimoDia())
            // ⚠️ `date()` corta el día en la zona de la aplicación porque la conexión sale
            // con `set time zone 'America/Argentina/Tucuman'` —ver `config/database.php`—. Si
            // eso se cambiara, los pedidos de la noche se irían al día siguiente y nadie se
            // daría cuenta: hay un test que lo sujeta con un pedido de las 23:30.
            ->selectRaw('date(created_at) as dia, count(*) as cantidad')
            ->groupBy('dia')
            ->pluck('cantidad', 'dia');

        $hoy = CarbonImmutable::now()->toDateString();

        $dias = array_map(function (CarbonImmutable $dia) use ($porDia, $hoy): array {
            $fecha = $dia->toDateString();

            return [
                'fecha' => $fecha,
                'dia' => $dia->day,
                // El nombre del día lo arma el servidor, que es el que sabe la zona y el
                // idioma. Ver `App\Support\Momento`.
                'diaSemana' => Momento::diaCorto($dia),
                'cantidad' => (int) ($porDia[$fecha] ?? 0),
                'esHoy' => $fecha === $hoy,
            ];
        }, $mes->dias());

        return Inertia::render('estadisticas/index', [
            'mes' => [
                'valor' => $mes->etiqueta(),
                'nombre' => $mes->nombre(),
                'tope' => Mes::actual()->etiqueta(),
                'anterior' => $mes->anterior()->etiqueta(),
                'siguiente' => $mes->siguiente()?->etiqueta(),
            ],
            'dias' => $dias,
            'resumen' => $this->resumir($dias),
        ]);
    }

    /**
     * Las cuatro cuentas de arriba, sacadas de las filas que ya están: ninguna vuelve a la
     * base.
     *
     * El promedio es sobre los días listados, domingos incluidos. La empresa no trabaja los
     * domingos —los cuatro de septiembre tienen cero pedidos—, así que el promedio queda por
     * debajo de lo que se despacha un día de trabajo. Por eso al lado va cuántos días no
     * tuvieron ninguno: con los dos números se lee lo que pasó, y con uno solo no.
     *
     * @param  list<array{fecha: string, dia: int, diaSemana: string, cantidad: int, esHoy: bool}>  $dias
     * @return array{total: string, promedio: string, dias: int, sinPedidos: int, pico: int, mejor: array{etiqueta: string, cantidad: int}|null}
     */
    private function resumir(array $dias): array
    {
        $cantidades = array_column($dias, 'cantidad');
        $total = array_sum($cantidades);
        $pico = $cantidades === [] ? 0 : max($cantidades);

        $mejor = null;

        if ($pico > 0) {
            $cual = (int) array_search($pico, $cantidades, true);
            $mejor = [
                'etiqueta' => $dias[$cual]['diaSemana'].' '.$dias[$cual]['dia'],
                'cantidad' => $pico,
            ];
        }

        return [
            'total' => number_format($total, 0, ',', '.'),
            'promedio' => $dias === [] ? '0' : number_format($total / count($dias), 1, ',', '.'),
            'dias' => count($dias),
            'sinPedidos' => count(array_filter($cantidades, fn (int $cantidad): bool => $cantidad === 0)),
            // Para el ancho de las barras: el día más alto es el 100 %.
            'pico' => $pico,
            'mejor' => $mejor,
        ];
    }
}
