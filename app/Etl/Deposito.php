<?php

namespace App\Etl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copia las imágenes del disco local al disco definitivo, y comprueba que estén.
 *
 * Existe por una restricción del corte: hay pocas horas, y los 2,2 GB no entran
 * en esa ventana si se suben ahí. La salida es subirlos ANTES —lo que el nombre
 * derivado del ObjectId hizo posible, ver `Archivos`— y dejar para la ventana
 * sólo lo que cambió desde entonces: medido sobre el origen, **68 archivos y 31
 * MB con una semana de anticipación**, contra 4.509 y 2,2 GB.
 *
 * Por eso subir tiene que ser **reejecutable y barato de repetir**: lo que ya
 * está con el mismo tamaño no se vuelve a mandar.
 *
 * ## Por qué esto y no `aws s3 sync`
 *
 * Porque no sube a un bucket: sube **al disco que la aplicación va a leer**, sea
 * cual sea. El proveedor se elige en `config/filesystems.php` y acá no se nombra
 * ninguno. Y la comprobación vale más por el mismo motivo: que un objeto exista
 * en un bucket no prueba que la aplicación lo encuentre —prefijo, credencial,
 * región—; que `Storage::disk(…)->exists()` lo vea, sí.
 *
 * ⚠️ **No borra nada, nunca.** Un `sync --delete` con el origen incompleto
 * borraría archivos de filas que siguen existiendo, y estas imágenes incluyen
 * documentación de identidad que no está en ningún otro respaldo (DATA-9). Lo
 * que sobra se informa y lo resuelve una persona.
 */
class Deposito
{
    /** De a cuántas filas se leen las rutas, para no traer 4.509 de una vez. */
    private const LOTE = 500;

    /**
     * Las tres tablas y su columna. La lista no se deduce de `Archivos`: son las
     * tablas las que dicen qué archivos tienen dueño.
     */
    private const TABLAS = [
        'user_fotos',
        'postulacion_documentos',
        'publicidad_app_imagenes',
    ];

    public function __construct(
        private readonly string $origen,
        private readonly string $destino,
    ) {}

    /**
     * Copia lo que falta. Devuelve la cuenta de lo que hizo.
     *
     * `$aplicar` en false recorre y mide sin escribir, que es lo que permite
     * saber cuánto va a tardar la ventana antes de estar en la ventana.
     *
     * @return array{copiados: int, salteados: int, bytes: int}
     */
    public function subir(bool $aplicar, ?callable $avance = null): array
    {
        // Comprobar no necesita origen, y en el ensayo los dos discos son
        // `local`; subir de un disco a sí mismo, en cambio, no significa nada.
        if ($this->origen === $this->destino) {
            throw new RuntimeException(
                "El disco de origen y el de destino son el mismo («{$this->origen}»). "
                .'Configurar ETL_DISCO al bucket; ETL_DISCO_CARGA se queda en «local».'
            );
        }

        $copiados = 0;
        $salteados = 0;
        $bytes = 0;

        foreach ($this->rutas() as $ruta) {
            $peso = $this->pesoEnOrigen($ruta);

            if ($this->yaEsta($ruta, $peso)) {
                $salteados++;
                $avance && $avance($ruta, false);

                continue;
            }

            if ($aplicar) {
                $this->copiar($ruta);
            }

            $copiados++;
            $bytes += $peso;
            $avance && $avance($ruta, true);
        }

        return ['copiados' => $copiados, 'salteados' => $salteados, 'bytes' => $bytes];
    }

    /**
     * Las rutas que la base reclama y el destino no tiene.
     *
     * Es la comprobación que tiene que frenar el corte: una fila cuya imagen no
     * está es lo mismo que describe DATA-9 —una ruta que apunta a la nada—, y no
     * tiene sentido reproducirlo en el sistema nuevo.
     *
     * @return array<int, string>
     */
    public function faltantes(): array
    {
        $faltan = [];

        foreach ($this->rutas() as $ruta) {
            if (! Storage::disk($this->destino)->exists($ruta)) {
                $faltan[] = $ruta;
            }
        }

        return $faltan;
    }

    /**
     * Lo que está en el destino y ninguna fila reclama.
     *
     * No se borra: se informa. Son las corridas anteriores del ensayo, o —lo que
     * importa— una imagen que el origen dejó de tener.
     *
     * @return array<int, string>
     */
    public function sobrantes(): array
    {
        $reclamadas = [];

        foreach ($this->rutas() as $ruta) {
            $reclamadas[$ruta] = true;
        }

        $sobran = [];

        foreach (Archivos::DIRECTORIOS as $directorio) {
            foreach (Storage::disk($this->destino)->allFiles($directorio) as $ruta) {
                if (! isset($reclamadas[$ruta])) {
                    $sobran[] = $ruta;
                }
            }
        }

        return $sobran;
    }

    /**
     * Si los dos discos son el mismo, subir no significa nada.
     *
     * Lo pregunta el comando antes de arrancar la barra de progreso; `subir()`
     * lo vuelve a comprobar, porque es su invariante y no la de quien lo llama.
     */
    public function mismoDisco(): bool
    {
        return $this->origen === $this->destino;
    }

    /** Cuántas imágenes reclama la base. */
    public function cuantas(): int
    {
        $total = 0;

        foreach (self::TABLAS as $tabla) {
            $total += DB::table($tabla)->count();
        }

        return $total;
    }

    /**
     * Cada `ruta_archivo` de las tres tablas, de a lotes.
     *
     * @return iterable<int, string>
     */
    private function rutas(): iterable
    {
        foreach (self::TABLAS as $tabla) {
            $ultimo = 0;

            while (true) {
                $filas = DB::table($tabla)
                    ->where('id', '>', $ultimo)
                    ->orderBy('id')
                    ->limit(self::LOTE)
                    ->get(['id', 'ruta_archivo']);

                if ($filas->isEmpty()) {
                    break;
                }

                foreach ($filas as $fila) {
                    yield (string) $fila->ruta_archivo;
                }

                $ultimo = (int) $filas->last()->id;
            }
        }
    }

    /**
     * Se considera que ya está si existe y pesa lo mismo.
     *
     * Comparar el tamaño y no un hash es deliberado: un hash obligaría a leer de
     * vuelta los 2,2 GB del destino, y estos archivos no se modifican —cuando una
     * imagen cambia, cambia su nombre o su extensión—.
     */
    private function yaEsta(string $ruta, int $peso): bool
    {
        $destino = Storage::disk($this->destino);

        return $destino->exists($ruta) && $destino->size($ruta) === $peso;
    }

    private function pesoEnOrigen(string $ruta): int
    {
        $origen = Storage::disk($this->origen);

        if (! $origen->exists($ruta)) {
            throw new RuntimeException(
                "Falta «{$ruta}» en el disco de origen «{$this->origen}». "
                .'Correr la carga antes de subir.'
            );
        }

        return $origen->size($ruta);
    }

    /**
     * Copia un archivo de un disco al otro por streaming.
     *
     * `readStream` y no `get`: son 2,2 GB y el más grande pesa varios MB. Traer
     * el contenido a una variable es pedir problemas por nada.
     */
    private function copiar(string $ruta): void
    {
        $puntero = Storage::disk($this->origen)->readStream($ruta);

        if ($puntero === null) {
            throw new RuntimeException("No se pudo leer «{$ruta}» del disco «{$this->origen}».");
        }

        try {
            Storage::disk($this->destino)->put($ruta, $puntero);
        } finally {
            fclose($puntero);
        }
    }
}
