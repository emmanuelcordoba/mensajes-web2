<?php

namespace App\Services;

use App\Jobs\CancelarPedidoSinConfirmar;
use App\Models\Pedido;
use Illuminate\Database\Eloquent\Collection as ColeccionDeModelos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Qué está pasando en la cola, para la pantalla de /admin.
 *
 * Existe por lo que pasó el día del corte. `queue:work` corría colgado de una consola, se
 * cayó con `Lost connection` y **se llevó puestos dos trabajos sin ejecutarlos y sin
 * anotarlos en `failed_jobs`**. Un pedido quedó Asignado más de una hora, sin su reloj de
 * cinco minutos, y nadie se enteró: la cola no se queja, y no había ninguna pantalla donde
 * mirar. Lo encontramos consultando la base a mano.
 *
 * Por eso el número que importa acá no es cuántos trabajos hay —una cola con trabajos está
 * trabajando—, es **cuánto lleva esperando el más viejo que ya venció**. Una cola sana mide
 * segundos; la del corte medía una hora.
 *
 * ⚠️ Lee las tablas y no modelos: `jobs` y `failed_jobs` son de Laravel y no tienen modelo
 * propio acá. El nombre del trabajo sale de `payload->displayName`, que es el único campo
 * del payload pensado para leerse.
 */
class EstadoDeLaCola
{
    /**
     * Desde cuándo vale la pena avisar de un pedido sin reloj.
     *
     * ⚠️ Sin esta ventana la pantalla no serviría: el ETL trajo ~206 pedidos en Asignado
     * que se asignaron en el sistema VIEJO hace semanas y que nunca tuvieron un trabajo en
     * esta cola. Marcarlos a todos es el mismo ruido que no marcar nada.
     */
    public const HORAS_DE_VENTANA = 24;

    /**
     * Los cuatro números de arriba.
     *
     * @return array{pendientes: int, enCurso: int, fallados: int, segundosDelMasViejo: int|null}
     */
    public function resumen(): array
    {
        return [
            'pendientes' => DB::table('jobs')->whereNull('reserved_at')->count(),
            'enCurso' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'fallados' => DB::table('failed_jobs')->count(),
            'segundosDelMasViejo' => $this->segundosDelMasViejo(),
        ];
    }

    /**
     * Cuánto lleva esperando el trabajo vencido más viejo, o null si no hay ninguno.
     *
     * ⚠️ Mira `available_at` y no `created_at`: un trabajo diferido —el reloj de los cinco
     * minutos lo es— está esperando a propósito hasta que vence, y contarlo como atraso
     * daría una alarma encendida todo el tiempo. Lo que delata un worker caído es un
     * trabajo que **ya venció** y sigue sin que nadie lo tome.
     */
    public function segundosDelMasViejo(): ?int
    {
        $ahora = now()->getTimestamp();

        $vencimiento = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $ahora)
            ->min('available_at');

        return $vencimiento === null ? null : max(0, $ahora - (int) $vencimiento);
    }

    /**
     * Los trabajos que están en la cola.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendientes(int $limite = 50): Collection
    {
        return DB::table('jobs')
            ->orderBy('available_at')
            ->limit($limite)
            ->get()
            ->map(fn (object $fila): array => [
                'id' => (int) $fila->id,
                'trabajo' => $this->nombre($fila->payload),
                'cola' => (string) $fila->queue,
                'intentos' => (int) $fila->attempts,
                'enCurso' => $fila->reserved_at !== null,
                'encolado' => Carbon::createFromTimestamp((int) $fila->created_at),
                'vence' => Carbon::createFromTimestamp((int) $fila->available_at),
                'pedidoId' => $this->pedidoDe($fila->payload),
            ]);
    }

    /**
     * Los que fallaron, el último primero.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fallados(int $limite = 50): Collection
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limite)
            ->get()
            ->map(fn (object $fila): array => [
                'uuid' => (string) $fila->uuid,
                'trabajo' => $this->nombre($fila->payload),
                'cola' => (string) $fila->queue,
                'fallo' => Carbon::parse($fila->failed_at),
                'motivo' => $this->primeraLinea((string) $fila->exception),
            ]);
    }

    /**
     * Pedidos Asignados que ya pasaron los cinco minutos y no tienen quién los libere.
     *
     * Es el agujero por el que se fue el pedido 959141 el día del corte: el trabajo que lo
     * iba a cancelar se perdió, y el pedido se quedó tomado por un cadete que nunca lo
     * confirmó. Desde el panel no se ve distinto de uno recién asignado.
     *
     * ⚠️ Todo pedido en Asignado debería tener reloj: `AsignacionDeCadete::darleElPedido()`
     * deja el pedido en Asignado **sólo** cuando el cadete está en la app, y al que no lo
     * está lo pasa derecho a Finalizado. Así que acá no hay falsos positivos por cadetes de
     * mostrador; el único ruido posible es el de los pedidos viejos, y de eso se ocupa
     * `HORAS_DE_VENTANA`.
     *
     * @return ColeccionDeModelos<int, Pedido>
     */
    public function pedidosSinReloj(): ColeccionDeModelos
    {
        return Pedido::query()
            ->where('estado', Pedido::ESTADO_ASIGNADO)
            ->where('updated_at', '<', now()->subMinutes(AsignacionDeCadete::MINUTOS_PARA_CONFIRMAR))
            ->where('updated_at', '>', now()->subHours(self::HORAS_DE_VENTANA))
            ->whereNotIn('id', $this->pedidosConReloj())
            ->orderBy('updated_at')
            ->get(['id', 'numero', 'cadete_id', 'estado', 'updated_at']);
    }

    /** Lo encola de nuevo. Es `queue:retry`, sin entrar por consola. */
    public function reintentar(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
    }

    /** Lo saca de la lista de fallados sin reintentarlo. Es `queue:forget`. */
    public function olvidar(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
    }

    /**
     * Los ids de pedido que tienen un reloj esperando en la cola.
     *
     * @return array<int, int>
     */
    private function pedidosConReloj(): array
    {
        return DB::table('jobs')
            ->select('payload')
            ->get()
            ->filter(fn (object $fila): bool => $this->nombre($fila->payload) === CancelarPedidoSinConfirmar::class)
            ->map(fn (object $fila): ?int => $this->pedidoDe($fila->payload))
            ->filter()
            ->values()
            ->all();
    }

    private function nombre(string $payload): string
    {
        $datos = json_decode($payload, true);

        return is_array($datos) ? (string) ($datos['displayName'] ?? '—') : '—';
    }

    /**
     * El id del pedido que lleva adentro un trabajo, o null si no lleva ninguno.
     *
     * ⚠️ Anclado al campo `class` del `ModelIdentifier`, **no a la palabra «Pedido» sola**.
     * El nombre del propio trabajo la contiene —`CancelarPedidoSinConfirmar`— y una
     * expresión que arrancara ahí puede leer el id de otra cosa y devolver un número que
     * parece un pedido y no lo es. Me pasó el 2026-11-08 mirando la cola del corte a mano,
     * y el número equivocado estuvo a punto de justificar una decisión.
     */
    private function pedidoDe(string $payload): ?int
    {
        $datos = json_decode($payload, true);
        $comando = is_array($datos) ? ($datos['data']['command'] ?? '') : '';

        $encontrado = preg_match(
            '/s:5:"class";s:\d+:"App\\\\Models\\\\Pedido";s:2:"id";i:(\d+);/',
            (string) $comando,
            $partes,
        );

        return $encontrado === 1 ? (int) $partes[1] : null;
    }

    /** La primera línea del `exception`, que es la que dice qué pasó. */
    private function primeraLinea(string $excepcion): string
    {
        return trim(strtok($excepcion, "\n") ?: $excepcion);
    }
}
