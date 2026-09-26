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
     * Lo que el esquema va a rechazar, mirado en el origen ANTES de escribir.
     *
     * El diseño pide que el ETL «frene y liste, no saltee en silencio». Dejar
     * que lo haga PostgreSQL cumple la primera mitad pero no la segunda: sobre
     * 9.238 usuarios, un UNIQUE roto sale como un SQLSTATE con el INSERT de mil
     * filas adentro, que no le dice a nadie qué hay que corregir. Esto lo mira
     * antes, y con el vocabulario del problema.
     *
     * Cada elemento es una línea del informe. Vacío es que no hay nada.
     *
     * @return array<int, string>
     */
    public function problemas(): array
    {
        return [];
    }

    /**
     * Migra la colección entera y devuelve cuántas filas escribió.
     *
     * Todo en una transacción, para que la tabla quede entera o no quede: sin
     * esto, fallar en el pedido 500.000 deja medio millón de filas cargadas y
     * sus entradas en `migracion_ids`, y volver a correr duplica.
     *
     * ⚠️ No se puso por velocidad, y conviene decirlo porque parece que debería
     * ayudar. Se midió: 419 s contra 445 s, y después la MISMA carga dio 308 s
     * y 405 s en corridas distintas. La varianza entre corridas es de un 30%,
     * así que esa comparación no mide nada. Lo único medido con confianza es
     * que leer el origen tarda 18,6 s y traducir 13,4 s sobre los 959.973
     * pedidos: el resto del tiempo son las escrituras, y ahí manda el trabajo
     * de mantener 7 índices y validar 3 claves foráneas por fila.
     */
    public function ejecutar(): int
    {
        return DB::transaction(fn (): int => $this->cargar());
    }

    private function cargar(): int
    {
        $escritas = 0;
        $documentos = [];

        foreach ($this->origen->documentos($this->coleccion(), $this->filtro(), $this->campos()) as $documento) {
            $documentos[] = $documento;

            if (count($documentos) >= static::LOTE) {
                $escritas += $this->procesar($documentos);
                $documentos = [];
            }
        }

        return $escritas + $this->procesar($documentos);
    }

    /**
     * Traduce un lote entero y lo escribe.
     *
     * Se junta el lote de DOCUMENTOS antes de traducirlos, y no se traduce de a
     * uno, para que `prepararLote()` pueda resolver de una sola vez lo que el
     * lote necesita. Ver ese método.
     *
     * @param  array<int, array<string, mixed>>  $documentos
     */
    private function procesar(array $documentos): int
    {
        if ($documentos === []) {
            return 0;
        }

        $this->prepararLote($documentos);

        $lote = [];
        $legacy = [];

        foreach ($documentos as $documento) {
            foreach ($this->filas($documento) as $fila) {
                $lote[] = $fila;
                $legacy[] = Origen::id($documento['_id'] ?? null);
            }
        }

        return $this->escribir($lote, $legacy);
    }

    /**
     * Las filas que salen de un documento. Casi siempre una, o ninguna.
     *
     * `postulacion_documentos` es la excepción: cada postulación tiene hasta
     * cuatro imágenes y cada una es una fila. Por eso el punto de extensión es
     * éste y no `fila()`.
     *
     * ⚠️ Un migrador que devuelva más de una fila por documento NO puede
     * `anotaIds()`: la clave de `migracion_ids` es (tabla, legacy_id) y habría
     * cuatro filas reclamando el mismo. Ninguna de las tres tablas de imágenes
     * lo necesita, porque nadie las referencia.
     *
     * @param  array<string, mixed>  $documento
     * @return array<int, array<string, mixed>>
     */
    public function filas(array $documento): array
    {
        $fila = $this->fila($documento);

        return $fila === null ? [] : [$fila];
    }

    /**
     * Se llama con cada lote de documentos ANTES de traducirlos.
     *
     * Existe por una tabla: `logs_estados_pedidos` tiene 3.637.456 filas y
     * apunta a `pedidos`, que tiene 959.973 entradas en `migracion_ids`.
     * Sostener ese mapa en memoria es lo que el diseño evita, y resolver de a
     * una fila serían 3,6 millones de consultas. Resolver por lote son mil ids
     * en un `IN`, una consulta cada mil filas, y la memoria queda plana.
     *
     * Las tablas cuyos padres entran holgados en memoria —roles, users,
     * clientes, cadetes— no lo necesitan y no lo implementan.
     *
     * @param  array<int, array<string, mixed>>  $documentos
     */
    protected function prepararLote(array $documentos): void {}

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
