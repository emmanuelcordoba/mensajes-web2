<?php

namespace App\Console\Commands;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\NudoCircular;
use App\Etl\Origen;
use App\Etl\Secuencias;
use App\Etl\Tablas\ActividadesCadetes;
use App\Etl\Tablas\Cadetes;
use App\Etl\Tablas\Clientes;
use App\Etl\Tablas\CobranzaSaldoMovimientos;
use App\Etl\Tablas\Configuraciones;
use App\Etl\Tablas\ErrorLogs;
use App\Etl\Tablas\HorariosAtencion;
use App\Etl\Tablas\LogsEstadosPedidos;
use App\Etl\Tablas\Mensajes;
use App\Etl\Tablas\MontosSemanales;
use App\Etl\Tablas\Pedidos;
use App\Etl\Tablas\Postulaciones;
use App\Etl\Tablas\PublicidadesApp;
use App\Etl\Tablas\Roles;
use App\Etl\Tablas\Users;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * El ETL: lee la base vieja en MongoDB y llena PostgreSQL. Ver ETL-1.
 *
 * ⚠️ Contra una copia restaurada, NUNCA contra producción. Ver config/etl.php.
 *
 * Informa por defecto; escribe con --aplicar. Se puede correr las veces que
 * haga falta sobre una copia: --reiniciar vacía el destino antes de empezar,
 * que es lo que convierte el ensayo del corte en algo repetible.
 *
 * Se borra con el corte, junto con app/Etl y el servicio `mongo` de
 * compose.yaml.
 */
class EtlMigrar extends Command
{
    protected $signature = 'etl:migrar
                            {--aplicar : Escribe. Sin esta opción sólo informa}
                            {--reiniciar : Vacía las tablas de destino antes de cargar}
                            {--base= : La base de MongoDB. Por defecto, config(etl.base)}';

    protected $description = 'Migra los datos de MongoDB a PostgreSQL. Sobre una copia, nunca contra producción.';

    /**
     * El orden lo dictan las claves foráneas.
     *
     * @var array<int, class-string<Migrador>>
     */
    private const TABLAS = [
        Roles::class,
        Users::class,
        Clientes::class,
        Cadetes::class,
        Postulaciones::class,
        Pedidos::class,
        CobranzaSaldoMovimientos::class,
        Mensajes::class,
        MontosSemanales::class,
        PublicidadesApp::class,
        Configuraciones::class,
        HorariosAtencion::class,
        ActividadesCadetes::class,
        LogsEstadosPedidos::class,
        ErrorLogs::class,
    ];

    private Origen $origen;

    public function handle(): int
    {
        $base = $this->option('base') ?: config('etl.base');
        $origen = $this->origen = new Origen($base);

        $this->newLine();
        $this->line('  Origen  : '.config('etl.origen').'/'.$base);
        $this->line('  Destino : '.config('database.connections.'.config('database.default').'.database'));
        $this->line('  Modo    : '.($this->option('aplicar') ? 'APLICAR' : 'sólo informe'));
        $this->newLine();

        try {
            $colecciones = $origen->colecciones();
        } catch (Throwable $e) {
            $this->error('No se pudo leer el origen: '.$e->getMessage());
            $this->line('  ¿Está levantado? `sail --profile etl up -d mongo`');

            return self::FAILURE;
        }

        /** @var array<int, Migrador> $migradores */
        $migradores = array_map(static fn (string $clase): Migrador => new $clase($origen), self::TABLAS);

        foreach ($migradores as $migrador) {
            if (! in_array($migrador->coleccion(), $colecciones, true)) {
                $this->error("La colección «{$migrador->coleccion()}» no está en «{$base}».");

                return self::FAILURE;
            }
        }

        if (! $this->option('aplicar')) {
            return $this->informar($migradores);
        }

        if (! $this->revisar($migradores)) {
            return self::FAILURE;
        }

        if (! $this->destinoVacio($migradores)) {
            return self::FAILURE;
        }

        return $this->cargar($migradores);
    }

    /**
     * Lo que cada migrador ve mal en el origen, antes de escribir nada.
     *
     * @param  array<int, Migrador>  $migradores
     * @return array<string, array<int, string>>
     */
    private function problemas(array $migradores): array
    {
        $problemas = [];

        foreach ($migradores as $m) {
            $suyos = $m->problemas();

            if ($suyos !== []) {
                $problemas[$m->tabla()] = $suyos;
            }
        }

        return $problemas;
    }

    /**
     * @param  array<string, array<int, string>>  $problemas
     */
    private function listar(array $problemas): void
    {
        foreach ($problemas as $tabla => $lineas) {
            $this->newLine();
            $this->line("  <fg=yellow>{$tabla}</>");

            foreach ($lineas as $linea) {
                $this->line('    · '.$linea);
            }
        }
    }

    /**
     * No se carga sobre datos que el esquema va a rechazar. Se corrigen en el
     * sistema viejo, que es donde vive la gente que sabe cuál de dos cuentas
     * repetidas es la buena.
     *
     * @param  array<int, Migrador>  $migradores
     */
    private function revisar(array $migradores): bool
    {
        $problemas = $this->problemas($migradores);

        if ($problemas === []) {
            return true;
        }

        $this->error('No se carga: el origen tiene datos que el esquema rechaza.');
        $this->listar($problemas);
        $this->newLine();
        $this->line('  Se corrigen en el sistema viejo, antes del ETL. Ver DATA-6 y DATA-7.');

        return false;
    }

    /**
     * @param  array<int, Migrador>  $migradores
     */
    private function informar(array $migradores): int
    {
        $filas = [];
        foreach ($migradores as $m) {
            $filas[] = [$m->coleccion(), $m->tabla(), number_format($m->enOrigen(), 0, ',', '.'), number_format($m->enDestino(), 0, ',', '.')];
        }

        $this->table(['Colección', 'Tabla', 'En origen', 'Ya en destino'], $filas);

        $problemas = $this->problemas($migradores);

        if ($problemas === []) {
            $this->info('El origen no tiene nada que el esquema vaya a rechazar.');
        } else {
            $this->error('El origen tiene datos que el esquema rechaza:');
            $this->listar($problemas);
            $this->newLine();
        }

        $this->info('Modo informe: no se escribió nada. Para cargar: --aplicar');

        return self::SUCCESS;
    }

    /**
     * Cargar sobre datos existentes duplicaría todo. O está vacío, o se vacía a
     * propósito con --reiniciar.
     *
     * @param  array<int, Migrador>  $migradores
     */
    private function destinoVacio(array $migradores): bool
    {
        $conDatos = array_filter($migradores, static fn (Migrador $m): bool => $m->enDestino() > 0);

        if ($conDatos === []) {
            return true;
        }

        $nombres = implode(', ', array_map(static fn (Migrador $m): string => $m->tabla(), $conDatos));

        if (! $this->option('reiniciar')) {
            $this->error("El destino ya tiene datos en: {$nombres}.");
            $this->line('  Para vaciarlo y volver a cargar: --reiniciar');

            return false;
        }

        if (! confirm("Se vacían las tablas: {$nombres}. ¿Seguimos?", false)) {
            $this->warn('No se modificó nada.');

            return false;
        }

        foreach (array_reverse($migradores) as $m) {
            DB::table($m->tabla())->truncate();
        }

        DB::table(MapaDeIds::TABLA)->truncate();

        return true;
    }

    /**
     * @param  array<int, Migrador>  $migradores
     */
    private function cargar(array $migradores): int
    {
        MapaDeIds::crearSiFalta();

        $resultados = [];
        $problemas = 0;

        foreach ($migradores as $m) {
            $enOrigen = $m->enOrigen();
            $comenzo = microtime(true);

            try {
                $escritas = $m->ejecutar();
            } catch (Throwable $e) {
                $this->newLine();
                $this->error("Falló al cargar «{$m->tabla()}»: ".$e->getMessage());
                $this->line('  No se sigue: las tablas que vienen dependen de ésta.');

                return self::FAILURE;
            }

            $coincide = $escritas === $enOrigen;
            $problemas += $coincide ? 0 : 1;

            $resultados[] = [
                $m->tabla(),
                number_format($enOrigen, 0, ',', '.'),
                number_format($escritas, 0, ',', '.'),
                $coincide ? 'sí' : '⚠️ NO',
                sprintf('%.1f s', microtime(true) - $comenzo),
            ];
        }

        $this->table(['Tabla', 'En origen', 'Cargadas', '¿Coincide?', 'Tiempo'], $resultados);

        if ($problemas > 0) {
            $this->error("{$problemas} tabla(s) cargaron menos filas de las que había en origen.");

            return self::FAILURE;
        }

        $this->cerrarNudo();
        $this->moverSecuencias();

        $this->info('Listo. Todas las tablas coinciden con el origen.');

        return self::SUCCESS;
    }

    /**
     * Las dos secuencias de numeración del negocio, al final de todo.
     * Ver App\Etl\Secuencias.
     */
    private function moverSecuencias(): void
    {
        foreach ((new Secuencias)->mover() as $secuencia => $maximo) {
            $this->line(
                "  {$secuencia}: el próximo número será el "
                .number_format($maximo + 1, 0, ',', '.').'.'
            );
        }

        $this->newLine();
    }

    /**
     * El UPDATE que cierra el ciclo users ↔ clientes, una vez que las dos
     * están cargadas. Ver App\Etl\NudoCircular.
     */
    private function cerrarNudo(): void
    {
        if (MapaDeIds::cuantos('users') === 0 || MapaDeIds::cuantos('clientes') === 0) {
            return;
        }

        $cerrados = (new NudoCircular($this->origen))->cerrar();

        $this->line("  Nudo circular: {$cerrados} usuario(s) quedaron con su cliente restringido.");
        $this->newLine();
    }
}
