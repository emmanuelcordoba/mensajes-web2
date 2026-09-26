<?php

namespace App\Console\Commands;

use App\Etl\Deposito;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sube las imágenes al disco definitivo y comprueba que estén todas.
 *
 * Va con `etl:migrar`, y se corre DOS veces con propósitos distintos:
 *
 * 1. **Días antes del corte**, después de una carga de ensayo: sube los 4.509
 *    archivos. Tarda lo que tarde; no importa, porque no está en la ventana.
 * 2. **Dentro de la ventana**, después de la carga definitiva: sube sólo lo que
 *    cambió —68 archivos y 31 MB con una semana de anticipación— y **verifica**.
 *
 * ⚠️ Con `--verificar` solo no sube nada: comprueba. Es lo que tiene que correr
 * al final del corte, y lo que tiene que frenarlo si falta algo. Sale con código
 * 1 si falta un archivo, para que un script lo note.
 *
 * Se borra con el corte, junto con app/Etl.
 */
class EtlArchivos extends Command
{
    protected $signature = 'etl:archivos
                            {--aplicar : Sube de verdad. Sin esta opción sólo informa}
                            {--verificar : Sólo comprueba que estén todas, sin subir}
                            {--origen= : El disco de donde salen. Por defecto, config(etl.disco_carga)}
                            {--destino= : El disco al que van. Por defecto, config(etl.disco)}';

    protected $description = 'Sube las imágenes del ETL al disco definitivo y comprueba que estén todas.';

    public function handle(): int
    {
        $origen = $this->origen();
        $destino = (string) ($this->option('destino') ?? config('etl.disco'));

        $deposito = new Deposito($origen, $destino);

        $this->newLine();
        $this->line("  Origen  : <fg=cyan>{$origen}</>");
        $this->line("  Destino : <fg=cyan>{$destino}</>");

        if ($this->option('verificar')) {
            return $this->verificar($deposito);
        }

        return $this->subir($deposito);
    }

    private function subir(Deposito $deposito): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $this->line('  Modo    : '.($aplicar ? '<fg=yellow>SUBIR</>' : '<fg=green>INFORME</>'));
        $this->newLine();

        if ($deposito->mismoDisco()) {
            $this->components->error(
                "El disco de origen y el de destino son el mismo («{$this->origen()}»). "
                .'Configurar ETL_DISCO al bucket; ETL_DISCO_CARGA se queda en «local».'
            );

            return self::FAILURE;
        }

        $reclamadas = $deposito->cuantas();

        if ($reclamadas === 0) {
            $this->components->warn('No hay ninguna imagen en la base. ¿Se corrió etl:migrar?');

            return self::SUCCESS;
        }

        $barra = $this->output->createProgressBar($reclamadas);
        $barra->start();

        try {
            $cuenta = $deposito->subir($aplicar, static fn () => $barra->advance());
        } catch (Throwable $e) {
            $barra->clear();
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $barra->finish();
        $this->newLine(2);

        $this->table(
            ['', 'Archivos', 'Peso'],
            [
                [$aplicar ? 'Subidos' : 'Por subir', number_format($cuenta['copiados'], 0, ',', '.'),
                    $this->enMb($cuenta['bytes'])],
                ['Ya estaban', number_format($cuenta['salteados'], 0, ',', '.'), '—'],
                ['Total que reclama la base', number_format($reclamadas, 0, ',', '.'), ''],
            ],
        );

        if (! $aplicar) {
            $this->components->info('Informe nada más. Con --aplicar sube.');

            return self::SUCCESS;
        }

        // Subir y no comprobar sería quedarse con la mitad del trabajo: lo que
        // importa no es cuántos se mandaron, es que no falte ninguno.
        return $this->verificar($deposito);
    }

    private function verificar(Deposito $deposito): int
    {
        $this->newLine();

        $faltan = $deposito->faltantes();
        $sobran = $deposito->sobrantes();

        $this->table(
            ['', 'Cuántos'],
            [
                ['Imágenes que reclama la base', number_format($deposito->cuantas(), 0, ',', '.')],
                ['<fg=red>Faltan en el destino</>', number_format(count($faltan), 0, ',', '.')],
                ['Sobran en el destino', number_format(count($sobran), 0, ',', '.')],
            ],
        );

        if ($sobran !== []) {
            $this->components->warn(
                count($sobran).' archivo(s) en el destino que ninguna fila reclama. '
                .'NO se borran: pueden ser de una corrida anterior, o una imagen que el origen '
                .'dejó de tener. Los primeros: '.implode(', ', array_slice($sobran, 0, 3))
            );
        }

        if ($faltan !== []) {
            // Una fila cuya imagen no está es exactamente lo que describe DATA-9:
            // una ruta que apunta a la nada. El corte no puede darse por bueno así.
            $this->components->error(
                count($faltan).' imagen(es) que la base reclama y el destino no tiene. '
                .'Los primeros: '.implode(', ', array_slice($faltan, 0, 3))
            );

            return self::FAILURE;
        }

        $this->components->info('Están las '.number_format($deposito->cuantas(), 0, ',', '.').'.');

        return self::SUCCESS;
    }

    private function origen(): string
    {
        return (string) ($this->option('origen') ?? config('etl.disco_carga'));
    }

    private function enMb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.').' MB';
    }
}
