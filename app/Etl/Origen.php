<?php

namespace App\Etl;

use Generator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\ReadPreference;

/**
 * El lado de lectura del ETL: la base vieja, en MongoDB.
 *
 * Usa la extensión ext-mongodb directamente, sin la librería mongodb/mongodb.
 * Del lado del origen el ETL sólo lee, así que los helpers de la librería no
 * aportan nada, y no depender de ella evita el piso de versión y un paquete
 * más que borrar en el corte.
 *
 * ⚠️ NUNCA contra producción. Lee un backup restaurado en un MongoDB moderno
 * —el servicio `mongo` de compose.yaml—, tanto porque el driver actual ya no
 * habla con la 3.6.3 de producción como porque el servidor viejo está
 * comprometido (SEC-18). Esta clase no escribe nada, pero la regla es del
 * procedimiento, no del código.
 */
class Origen
{
    private Manager $manager;

    public function __construct(
        private string $base,
        ?string $uri = null,
    ) {
        $this->manager = new Manager($uri ?? config('etl.origen'));
    }

    /**
     * Los documentos de una colección, de a lotes, sin traerlos todos a memoria.
     *
     * `pedidos` tiene 959.973 documentos: iterar el cursor es la única forma
     * razonable. El batchSize es el del servidor, no un límite del recorrido.
     *
     * @param  array<string, mixed>  $filtro
     * @param  array<int, string>  $campos  Vacío trae el documento entero.
     * @return Generator<int, array<string, mixed>>
     */
    public function documentos(string $coleccion, array $filtro = [], array $campos = []): Generator
    {
        $opciones = ['batchSize' => 1000];

        if ($campos !== []) {
            $opciones['projection'] = array_fill_keys($campos, 1);
        }

        $cursor = $this->manager->executeQuery(
            "{$this->base}.{$coleccion}",
            new Query($filtro, $opciones),
            ['readPreference' => new ReadPreference(ReadPreference::PRIMARY)],
        );

        foreach ($cursor as $documento) {
            yield json_decode(json_encode($documento), true);
        }
    }

    /**
     * Cuántos documentos tiene una colección. Es la cifra contra la que el ETL
     * verifica lo que cargó.
     *
     * @param  array<string, mixed>  $filtro
     */
    public function contar(string $coleccion, array $filtro = []): int
    {
        $cursor = $this->manager->executeCommand(
            $this->base,
            new Command(['count' => $coleccion, 'query' => (object) $filtro]),
        );

        return (int) $cursor->toArray()[0]->n;
    }

    /**
     * @return array<int, string>
     */
    public function colecciones(): array
    {
        $cursor = $this->manager->executeCommand(
            $this->base,
            new Command(['listCollections' => 1, 'nameOnly' => true]),
        );

        $nombres = array_map(static fn ($c): string => $c->name, $cursor->toArray());
        sort($nombres);

        return $nombres;
    }

    /**
     * El ObjectId de un documento, como los 24 caracteres que guarda
     * migracion_ids.
     */
    public static function id(mixed $valor): ?string
    {
        if ($valor instanceof ObjectId) {
            return (string) $valor;
        }

        // json_decode deja los ObjectId como ['$oid' => '...'].
        if (is_array($valor) && isset($valor['$oid'])) {
            return $valor['$oid'];
        }

        // Las claves foráneas del sistema viejo son strings, no ObjectId
        // (DATA-3): un pedido guarda cliente_id: "64f2...".
        if (is_string($valor) && preg_match('/^[0-9a-f]{24}$/i', $valor) === 1) {
            return strtolower($valor);
        }

        return null;
    }

    /**
     * Una fecha de MongoDB como la espera PostgreSQL, conservando los
     * milisegundos: el esquema usa TIMESTAMPTZ a precisión de microsegundos
     * justamente para no perderlos.
     */
    public static function fecha(mixed $valor): ?string
    {
        if ($valor instanceof UTCDateTime) {
            $ms = (int) ((string) $valor);
        } elseif (is_array($valor) && isset($valor['$date']['$numberLong'])) {
            $ms = (int) $valor['$date']['$numberLong'];
        } elseif (is_array($valor) && isset($valor['$date']) && is_int($valor['$date'])) {
            $ms = $valor['$date'];
        } else {
            return null;
        }

        return sprintf('%s.%03d+00', gmdate('Y-m-d H:i:s', intdiv($ms, 1000)), $ms % 1000);
    }
}
