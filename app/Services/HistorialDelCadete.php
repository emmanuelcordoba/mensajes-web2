<?php

namespace App\Services;

use App\Models\ActividadCadete;
use App\Models\Cadete;
use App\Models\Pedido;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Lo que el cadete hizo: hoy, los últimos cinco días y los últimos seis meses.
 *
 * Es la pantalla «Historial» de la app. Cada fila trae tres números —tiempo activo, cantidad
 * de pedidos y dinero— y la app los muestra tal cual.
 *
 * ⚠️ **Cuenta por `created_at` del pedido y no por cuándo se finalizó**, igual que el
 * sistema viejo y que la pantalla de estadísticas del panel. Un pedido se carga y se
 * entrega el mismo día, así que la diferencia aparece sólo en los que cruzan la medianoche.
 * Cambiarlo movería plata de un día al otro en una pantalla que el cadete usa para
 * controlar lo que cobró, y no es algo que convenga cambiar de callado.
 *
 * ⚠️ **Las cuentas las hace la base.** El sistema viejo hacía `$cadete->pedidos` y filtraba
 * la colección en PHP: para el cadete con más viajes son 11.966 pedidos finalizados
 * traídos enteros a memoria, en cada entrada a la pantalla y en cada tirón para refrescar,
 * para informar doce números. Acá son cuatro `GROUP BY`.
 *
 * ⚠️ **Un tramo de actividad sin cerrar vale cero, y eso es a propósito.** `duracion` se
 * escribe al cerrar, así que mientras está abierto es NULL. Hay **113.065 tramos abiertos
 * para siempre** por el bug de PANEL-17 —el fin se escribía sólo en un camino—, y
 * calcularlos por diferencia contra «ahora» le sumaría años a meses que ya cerraron. La
 * única excepción es el tramo que sigue abierto y **empezó hoy**: ése es el cadete que está
 * trabajando ahora mismo, y sin él la fila de hoy le muestra 0m mientras anda en la calle.
 * Empezó hoy, así que lo que suma no puede pasar de un día.
 */
class HistorialDelCadete
{
    /** Cuántos días para atrás, sin contar hoy, que va aparte. */
    public const DIAS = 5;

    /** Y cuántos meses, contando el actual. */
    public const MESES = 6;

    /**
     * El historial completo, con la forma que la app declara en `RespuestaHistorial`.
     *
     * @return array{hoy: array<string, mixed>, ultimos_dias: list<array<string, mixed>>, ultimos_meses: list<array<string, mixed>>}
     */
    public function para(Cadete $cadete): array
    {
        $hoy = CarbonImmutable::today();

        // Hoy primero y después los cinco de atrás: la app dibuja hoy aparte, arriba.
        $dias = [];

        for ($i = 0; $i <= self::DIAS; $i++) {
            $dias[] = $hoy->subDays($i);
        }

        $desdeElDia = $hoy->subDays(self::DIAS);

        $pedidosPorDia = $this->pedidos($cadete, $desdeElDia, porMes: false);
        $segundosPorDia = $this->segundos($cadete, $desdeElDia, porMes: false);

        // ⚠️ El sistema viejo arranca los meses desde el mes del ÚLTIMO de los cinco días,
        // porque reusaba la misma variable y ya le había restado cinco. Del 1 al 5 de cada
        // mes, entonces, la lista de meses empieza en el anterior y el mes en curso no
        // aparece: el cadete mira el historial un día 3 y no ve nada de lo que hizo ese mes.
        $meses = [];

        for ($i = 0; $i < self::MESES; $i++) {
            $meses[] = $hoy->startOfMonth()->subMonths($i);
        }

        $desdeElMes = $hoy->startOfMonth()->subMonths(self::MESES - 1);

        $pedidosPorMes = $this->pedidos($cadete, $desdeElMes, porMes: true);
        $segundosPorMes = $this->segundos($cadete, $desdeElMes, porMes: true);

        $abiertoHoy = $this->segundosDelTramoAbiertoDeHoy($cadete, $hoy);

        return [
            'hoy' => $this->fila(
                $hoy,
                $pedidosPorDia->get($hoy->toDateString()),
                $segundosPorDia->get($hoy->toDateString(), 0) + $abiertoHoy,
            ),
            'ultimos_dias' => array_map(
                fn (CarbonImmutable $dia): array => $this->fila(
                    $dia,
                    $pedidosPorDia->get($dia->toDateString()),
                    $segundosPorDia->get($dia->toDateString(), 0),
                ),
                array_slice($dias, 1),
            ),
            'ultimos_meses' => array_map(
                fn (CarbonImmutable $mes): array => $this->fila(
                    $mes,
                    $pedidosPorMes->get($mes->format('Y-m')),
                    $segundosPorMes->get($mes->format('Y-m'), 0) + ($mes->isSameMonth($hoy) ? $abiertoHoy : 0),
                ) + ['mes' => $mes->month],
                $meses,
            ),
        ];
    }

    /**
     * Una fila del historial: la fecha y los tres números.
     *
     * @param  object{cantidad: int, recaudacion: string}|null  $pedidos
     * @return array<string, mixed>
     */
    protected function fila(CarbonImmutable $fecha, ?object $pedidos, int $segundos): array
    {
        return [
            // Con el huso puesto: la app la pasa por el pipe `date` de Angular, que la
            // convierte a la hora del teléfono. Sin huso, un día es ambiguo.
            'fecha' => $fecha->toIso8601String(),
            // Texto, como todo el dinero del proyecto, y sin los dos ceros de atrás: la app
            // lo imprime tal cual —`$ {{ dia.recaudacion }}`—. Ver `Pedido::montoParaMostrar`.
            'recaudacion' => Pedido::montoParaMostrar(
                $pedidos === null ? '0' : (string) $pedidos->recaudacion,
            ) ?? '0',
            'cant_pedidos' => $pedidos === null ? 0 : (int) $pedidos->cantidad,
            'tiempo_activo' => self::tiempoActivo($segundos),
        ];
    }

    /**
     * Cuántos pedidos finalizó y cuánto juntó, por día o por mes.
     *
     * ⚠️ Los dos `SELECT` están escritos enteros y no armados con una variable a propósito:
     * `selectRaw` pide un `literal-string`, que es lo que impide que algo que vino de afuera
     * termine adentro de un SQL sin que nadie lo note.
     *
     * @return Collection<string, object{cantidad: int, recaudacion: string}>
     */
    protected function pedidos(Cadete $cadete, CarbonImmutable $desde, bool $porMes): Collection
    {
        // ⚠️ `date()` y `to_char()` cortan el día y el mes en la zona de la aplicación porque
        // la conexión sale con `set time zone` —ver `config/database.php`—. Es lo mismo que
        // hace la pantalla de estadísticas del panel, y lo sujeta un test con un pedido de
        // las 23:30.
        $select = $porMes
            ? "to_char(created_at, 'YYYY-MM') as clave, count(*) as cantidad, coalesce(sum(valor), 0) as recaudacion"
            : 'date(created_at) as clave, count(*) as cantidad, coalesce(sum(valor), 0) as recaudacion';

        /** @var Collection<string, object{cantidad: int, recaudacion: string}> */
        return Pedido::query()
            ->where('cadete_id', $cadete->id)
            ->where('estado', Pedido::ESTADO_FINALIZADO)
            ->where('created_at', '>=', $desde)
            ->selectRaw($select)
            ->groupBy('clave')
            ->get()
            ->keyBy('clave');
    }

    /**
     * Cuántos segundos estuvo conectado, por día o por mes.
     *
     * Agrupa por `inicio`: un tramo que cruza la medianoche cuenta entero en el día en que
     * empezó, como en el sistema viejo.
     *
     * @return Collection<string, int>
     */
    protected function segundos(Cadete $cadete, CarbonImmutable $desde, bool $porMes): Collection
    {
        $select = $porMes
            ? "to_char(inicio, 'YYYY-MM') as clave, coalesce(sum(duracion), 0) as segundos"
            : 'date(inicio) as clave, coalesce(sum(duracion), 0) as segundos';

        /** @var Collection<string, int> */
        return ActividadCadete::query()
            ->where('cadete_id', $cadete->id)
            ->where('inicio', '>=', $desde)
            ->selectRaw($select)
            ->groupBy('clave')
            ->pluck('segundos', 'clave')
            ->map(fn (mixed $segundos): int => (int) $segundos);
    }

    /**
     * Lo que lleva conectado el cadete que está en la app ahora, si entró hoy.
     *
     * Puede haber más de un tramo abierto —los 113.065 de PANEL-17—, pero sólo se miran los
     * que empezaron hoy, que es como mucho el de esta sesión.
     */
    protected function segundosDelTramoAbiertoDeHoy(Cadete $cadete, CarbonImmutable $hoy): int
    {
        $ahora = CarbonImmutable::now();

        return (int) ActividadCadete::query()
            ->where('cadete_id', $cadete->id)
            ->whereNull('fin')
            ->where('inicio', '>=', $hoy)
            ->get()
            ->sum(fn (ActividadCadete $tramo): int => (int) $tramo->inicio->diffInSeconds($ahora, absolute: true));
    }

    /**
     * Los segundos repartidos en días, horas, minutos y segundos.
     *
     * ⚠️ **La forma es la de un `DateInterval` serializado, porque es lo que la app declara**
     * en `TiempoActivo` y lee `tiempo_activo.d`, `.h` y `.i`. Allá salía de
     * `CarbonInterval::createFromFormat('s', $n)->cascade()`, o sea el objeto de PHP tal
     * como lo escribía `json_encode`. Eso hoy no se puede copiar: PHP 8.2 **borró** siete de
     * esas propiedades —`weekday`, `special_type` y compañía—, así que el objeto ya no
     * serializa igual. Se arman a mano las que la app declara, y las siete que no existen
     * más no se inventan: nunca las leyó.
     *
     * ⚠️ **No cascadea más allá de los días.** `cascade()` convierte 30 días en un mes, y la
     * app no muestra `m`: un cadete con 31 días de actividad en un mes vería «1d» en lugar
     * de «31d». Acá los días se acumulan y listo.
     *
     * ⚠️ `days` sale `false` y no un número, que es como lo declara la app: en un
     * `DateInterval` armado a mano —no salido de un `diff`— esa propiedad es `false`.
     *
     * @return array<string, mixed>
     */
    public static function tiempoActivo(int $segundos): array
    {
        return [
            'y' => 0,
            'm' => 0,
            'd' => intdiv($segundos, 86_400),
            'h' => intdiv($segundos % 86_400, 3_600),
            'i' => intdiv($segundos % 3_600, 60),
            's' => $segundos % 60,
            'f' => 0.0,
            'invert' => 0,
            'days' => false,
        ];
    }
}
