<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Da de baja los pedidos que quedaron «sin terminar» y hace semanas que no se mueven.
 *
 * Un pedido Asignado o En curso que el cadete nunca cerró queda así para siempre: no hay
 * nada en el sistema que lo cierre. Al cortar del sistema viejo vinieron **382 de seis
 * años**, el más viejo de junio de 2020, y se limpiaron a mano el 16/11/2026. Esto existe
 * para que no se vuelva a juntar.
 *
 * ⚠️ **No es cosmético: un pedido así traba a su cadete.** `Cadete::tienePedidoSinTerminar()`
 * es lo que decide si al entregar vuelve solo a la fila, y mientras arrastre uno de 2023 la
 * respuesta es que sí tiene trabajo. Eran 7 cadetes trabajando el día de la limpieza.
 *
 * ⚠️ **Borrado lógico y no un cambio de estado**, que es lo que el sistema viejo ya hacía
 * con esta basura: de los Cancelados hay 7.420 borrados y 0 vivos. Pasarlos a Cancelado los
 * metería en la lista de espera del panel, que no tiene corte de fecha y pone los resueltos
 * arriba. Ver `Pedido::scopeEsperandoCadete()`.
 *
 * ⚠️ **`updated_at` no se toca**, y por eso el borrado va por consulta y no por modelo: esa
 * fecha es la antigüedad de cada pedido, que es con lo que se eligió el corte. Pisarla
 * borraría el único dato que explica por qué se borró.
 *
 * ⚠️ **No cobra, no avisa y no registra estado.** No hay entrega: es trabajo abandonado.
 * Tocar el saldo acá sería cobrarle a un cadete por un pedido de hace tres años.
 */
class LimpiarPedidosSinTerminar extends Command
{
    protected $signature = 'pedidos:limpiar-sin-terminar
                            {--dias=30 : Cuántos días sin moverse para darlo de baja}
                            {--tope=500 : Si hay más que esto, no toca nada y avisa}
                            {--aplicar : Borra de verdad. Sin esta opción sólo informa}';

    protected $description = 'Da de baja los pedidos Asignado o En curso que hace semanas que no se mueven.';

    /**
     * Cuántos puede haber de más antes de sospechar.
     *
     * El tope no está para ahorrar trabajo: está para que un cambio que rompa la condición
     * —o un día en que algo deje de finalizar pedidos— no se lleve puesto medio sistema en
     * silencio. Prefiere no hacer nada y dejarlo escrito.
     */
    public function handle(): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $tope = max(1, (int) $this->option('tope'));
        $desde = now()->subDays($dias);

        $cuantos = $this->losAbandonados($dias)->count();

        if ($cuantos === 0) {
            $this->info("Ningún pedido sin terminar de más de {$dias} días.");

            return self::SUCCESS;
        }

        if ($cuantos > $tope) {
            $aviso = "Hay {$cuantos} pedidos sin terminar de más de {$dias} días, y el tope es {$tope}."
                .' No se tocó ninguno.';

            // ⚠️ `warning` y no `info`: así queda en Telescope, que es donde se mira lo que
            // no anduvo. Ver `TelescopeServiceProvider::esUnLogQueImporta()`.
            Log::warning('Limpieza de pedidos sin terminar frenada por el tope', [
                'cuantos' => $cuantos,
                'tope' => $tope,
                'dias' => $dias,
            ]);

            $this->error($aviso);

            return self::FAILURE;
        }

        if (! $this->option('aplicar')) {
            $this->info("Se darían de baja {$cuantos} pedidos sin moverse desde {$desde->toDateString()}.");
            $this->line('  Con <fg=cyan>--aplicar</> se hace de verdad.');

            return self::SUCCESS;
        }

        // ⚠️ **`toBase()`, y no es un detalle: sin eso esto se come la fecha.** No alcanza
        // con evitar `SoftDeletes::delete()` —que guarda el modelo entero—: el `update()` de
        // Eloquent también agrega `updated_at` por su cuenta en los updates masivos. Bajando
        // al constructor de consultas se escribe sólo la columna que se pidió, y los filtros
        // —incluido el de no tocar lo ya borrado— siguen puestos. Hay un test que lo fija.
        $borrados = $this->losAbandonados($dias)->toBase()->update(['deleted_at' => now()]);

        Log::info('Pedidos sin terminar dados de baja', [
            'cuantos' => $borrados,
            'dias' => $dias,
            'desde' => $desde->toDateTimeString(),
        ]);

        $this->info("Dados de baja {$borrados} pedidos sin terminar de más de {$dias} días.");

        return self::SUCCESS;
    }

    /**
     * Los pedidos abandonados: sin terminar y sin moverse hace `$dias`.
     *
     * @return Builder<Pedido>
     */
    private function losAbandonados(int $dias): Builder
    {
        return Pedido::query()
            ->whereIn('estado', Pedido::ESTADOS_SIN_TERMINAR)
            ->where('updated_at', '<', now()->subDays($dias));
    }
}
