<?php

namespace App\Etl;

use Illuminate\Support\Facades\DB;

/**
 * Una tabla del ETL. La subclase dice de qué colección sale, a qué tabla va y
 * cómo se traduce un documento en una fila; todo lo demás —los lotes, el mapa
 * de ids, el conteo— lo hace esta clase.
 *
 * Los ids se regeneran (decisión del 2026-09-20): no se conserva el ObjectId.
 * Las tablas que otras referencian anotan la correspondencia en migracion_ids,
 * y sus hijos la usan para resolver las claves foráneas.
 */
abstract class Migrador
{
    /** Cuántas filas se insertan por sentencia. */
    protected const LOTE = 1000;

    private ?string $secuencia = null;

    public function __construct(protected Origen $origen) {}

    /** La colección de MongoDB de la que sale. */
    abstract public function coleccion(): string;

    /** La tabla de PostgreSQL a la que va. */
    abstract public function tabla(): string;

    /**
     * Un documento traducido a fila, o null para saltearlo.
     *
     * @param  array<string, mixed>  $documento
     * @return array<string, mixed>|null
     */
    abstract public function fila(array $documento): ?array;

    /**
     * ¿Otras tablas la referencian? Si sí, se anota en migracion_ids.
     *
     * Las imágenes y los documentos salen del mismo documento que su padre, así
     * que el ETL ya tiene el id nuevo a mano y no necesitan entrada.
     */
    public function anotaIds(): bool
    {
        return false;
    }

    /**
     * Sólo los campos que la traducción necesita, para no traer documentos
     * enteros. Vacío trae todo.
     *
     * @return array<int, string>
     */
    public function campos(): array
    {
        return [];
    }

    /**
     * El filtro sobre el origen. Sirve para las colecciones de las que no se
     * migra todo.
     *
     * @return array<string, mixed>
     */
    public function filtro(): array
    {
        return [];
    }

    /**
     * Migra la colección entera y devuelve cuántas filas escribió.
     */
    public function ejecutar(): int
    {
        $escritas = 0;
        $lote = [];
        $legacy = [];

        foreach ($this->origen->documentos($this->coleccion(), $this->filtro(), $this->campos()) as $documento) {
            $fila = $this->fila($documento);

            if ($fila === null) {
                continue;
            }

            $lote[] = $fila;
            $legacy[] = Origen::id($documento['_id'] ?? null);

            if (count($lote) >= static::LOTE) {
                $escritas += $this->escribir($lote, $legacy);
                $lote = [];
                $legacy = [];
            }
        }

        return $escritas + $this->escribir($lote, $legacy);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lote
     * @param  array<int, string|null>  $legacy
     */
    protected function escribir(array $lote, array $legacy): int
    {
        if ($lote === []) {
            return 0;
        }

        if (! $this->anotaIds()) {
            DB::table($this->tabla())->insert($lote);

            return count($lote);
        }

        // Los ids se piden a la secuencia ANTES de insertar, y se escriben a
        // mano. Es la única forma de saber con certeza qué id le tocó a cada
        // documento: insertGetId es de a uno —impensable sobre 959.973
        // pedidos— y el orden en que un INSERT múltiple devuelve las filas con
        // RETURNING no está garantizado por nada. Acá la correspondencia es
        // exacta y no depende del orden.
        $ids = $this->reservarIds(count($lote));

        $pares = [];
        foreach ($lote as $i => $fila) {
            $lote[$i] = ['id' => $ids[$i]] + $fila;

            if ($legacy[$i] !== null) {
                $pares[$legacy[$i]] = $ids[$i];
            }
        }

        DB::table($this->tabla())->insert($lote);
        MapaDeIds::anotar($this->tabla(), $pares);

        return count($lote);
    }

    /**
     * Reserva ids consecutivos de la secuencia de la tabla. nextval avanza la
     * secuencia, así que escribir el id a mano no la deja desincronizada.
     *
     * @return array<int, int>
     */
    protected function reservarIds(int $cuantos): array
    {
        $this->secuencia ??= DB::selectOne(
            'select pg_get_serial_sequence(?, ?) as nombre',
            [$this->tabla(), 'id'],
        )->nombre;

        return array_map(
            static fn (object $fila): int => (int) $fila->id,
            DB::select('select nextval(?) as id from generate_series(1, ?)', [$this->secuencia, $cuantos]),
        );
    }

    /** Cuántos documentos hay en el origen: la cifra contra la que se verifica. */
    public function enOrigen(): int
    {
        return $this->origen->contar($this->coleccion(), $this->filtro());
    }

    public function enDestino(): int
    {
        return DB::table($this->tabla())->count();
    }
}
