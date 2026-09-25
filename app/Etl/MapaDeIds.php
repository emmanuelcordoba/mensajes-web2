<?php

namespace App\Etl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La correspondencia entre el ObjectId viejo y el BIGINT nuevo: la tabla
 * migracion_ids de ESQUEMA.sql.
 *
 * Por qué existe: en MongoDB cada relación guarda el id del padre como string
 * —un pedido dice cliente_id: "64f2…"—. Son 15 relaciones, y sin esto no se
 * pueden rearmar al cargar. El ETL anota acá cada padre a medida que lo migra y
 * después resuelve las claves foráneas de los hijos con un JOIN, en vez de
 * sostener un millón de entradas en memoria.
 *
 * ⚠️ La aplicación NO la lee nunca. Si aparece una consulta de la aplicación
 * contra esta tabla, algo se hizo mal. La crea el ETL, no las migraciones,
 * porque es andamiaje: se borra con DROP TABLE cuando termina la estabilización.
 */
class MapaDeIds
{
    public const TABLA = 'migracion_ids';

    /**
     * La crea el ETL porque no está en las migraciones, a propósito.
     */
    public static function crearSiFalta(): void
    {
        if (Schema::hasTable(self::TABLA)) {
            return;
        }

        DB::statement('CREATE TABLE '.self::TABLA.' (
            tabla     VARCHAR(40) NOT NULL,
            legacy_id CHAR(24)    NOT NULL,
            id        BIGINT      NOT NULL,
            PRIMARY KEY (tabla, legacy_id)
        )');

        DB::statement('CREATE UNIQUE INDEX idx_migracion_ids_destino ON '.self::TABLA.' (tabla, id)');
    }

    /**
     * Anota varios de una, que es como los usa el ETL: un INSERT por fila sobre
     * los 959.973 pedidos no termina nunca.
     *
     * @param  array<string, int>  $pares  legacy_id => id nuevo
     */
    public static function anotar(string $tabla, array $pares): void
    {
        if ($pares === []) {
            return;
        }

        foreach (array_chunk($pares, 1000, true) as $lote) {
            $filas = [];
            foreach ($lote as $legacy => $id) {
                $filas[] = ['tabla' => $tabla, 'legacy_id' => $legacy, 'id' => $id];
            }

            DB::table(self::TABLA)->insert($filas);
        }
    }

    /**
     * El id nuevo de un documento viejo, o null si esa tabla no lo migró.
     */
    public static function id(string $tabla, ?string $legacyId): ?int
    {
        if ($legacyId === null) {
            return null;
        }

        $id = DB::table(self::TABLA)
            ->where('tabla', $tabla)
            ->where('legacy_id', $legacyId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Todo el mapa de una tabla, para resolver las claves foráneas de sus hijos
     * sin una consulta por fila. Sólo para las tablas chicas: roles, clientes,
     * cadetes y users entran holgados; pedidos, con 959.973, no.
     *
     * @return array<string, int>
     */
    public static function mapa(string $tabla): array
    {
        return DB::table(self::TABLA)
            ->where('tabla', $tabla)
            ->pluck('id', 'legacy_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * El mapa de unos pocos documentos, y no el de la tabla entera.
     *
     * Es lo que usan los hijos de `pedidos`: pedir los mil ids que el lote
     * necesita en vez de traerse el millón. Ver Migrador::prepararLote().
     *
     * @param  array<int, string>  $legacyIds
     * @return array<string, int>
     */
    public static function deVarios(string $tabla, array $legacyIds): array
    {
        if ($legacyIds === []) {
            return [];
        }

        return DB::table(self::TABLA)
            ->where('tabla', $tabla)
            ->whereIn('legacy_id', array_values(array_unique($legacyIds)))
            ->pluck('id', 'legacy_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public static function cuantos(string $tabla): int
    {
        return DB::table(self::TABLA)->where('tabla', $tabla)->count();
    }
}
