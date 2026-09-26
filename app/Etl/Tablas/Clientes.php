<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * clientes. Va después de users, que la referencia por `user_id`.
 *
 * ⚠️ `nombre` se vacía a propósito en los clientes de la app. Es la decisión 2
 * del esquema, y es el punto del rediseño que cerró DATA-8: en el sistema viejo
 * la app mantenía a mano `nombre = nombres.' '.apellidos`, o sea guardaba un
 * derivado, y eso pisó el nombre de 51 comercios. En el esquema nuevo el panel
 * escribe `nombre`, la app escribe el par, ninguno pisa al otro, y lo que lee
 * todo el sistema es `nombre_mostrado`, una columna generada.
 *
 * Entonces: si el `nombre` guardado es el de la persona —ignorando mayúsculas y
 * espacios de más, la misma comparación que hace `Cliente::ponerNombresDePersona()`
 * en el sistema viejo—, migra como NULL y `nombre_mostrado` lo deriva del par.
 * Si alguien le puso un nombre propio desde el panel, migra tal cual. Son 7.329
 * y 3.728 sobre la copia del 2026-09-23.
 *
 * ⚠️ `nombre_mostrado` NO se escribe: es GENERATED ALWAYS y PostgreSQL la
 * rechaza en un INSERT.
 *
 * ⚠️ `direccion` y `telefono` son NOT NULL, y en el origen hay 4 y 368 vacíos.
 * Vacío no es NULL, así que entran igual: se recortan pero no se convierten en
 * NULL, que es lo que sí se hace con las columnas que aceptan ausencia.
 */
class Clientes extends Migrador
{
    /** @var array<string, int>|null */
    private ?array $users = null;

    public function coleccion(): string
    {
        return 'clientes';
    }

    public function tabla(): string
    {
        return 'clientes';
    }

    public function anotaIds(): bool
    {
        return true;
    }

    public function campos(): array
    {
        return [
            'numero', 'nombre', 'nombres', 'apellidos', 'apellido', 'nombre_empleado',
            'direccion', 'telefono', 'plataforma', 'fcm_token', 'user_id',
            'created_at', 'updated_at', 'deleted_at',
        ];
    }

    public function fila(array $documento): ?array
    {
        $this->users ??= MapaDeIds::mapa('users');

        // `apellido`, en singular, son dos registros de un error viejo. Se
        // pliegan en `apellidos` y el campo desaparece.
        $nombres = self::texto($documento['nombres'] ?? null);
        $apellidos = self::texto($documento['apellidos'] ?? null) ?? self::texto($documento['apellido'] ?? null);

        return [
            'numero' => (int) ($documento['numero'] ?? 0),
            'nombre' => self::nombrePropio($documento['nombre'] ?? null, $nombres, $apellidos),
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'nombre_empleado' => self::texto($documento['nombre_empleado'] ?? null),
            // NOT NULL: vacío entra como vacío, no como NULL.
            'direccion' => self::aplastar((string) ($documento['direccion'] ?? '')),
            'telefono' => self::aplastar((string) ($documento['telefono'] ?? '')),
            'plataforma' => self::texto($documento['plataforma'] ?? null),
            'fcm_token' => self::texto($documento['fcm_token'] ?? null),
            'user_id' => $this->user($documento),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
            'deleted_at' => Origen::fecha($documento['deleted_at'] ?? null),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $veces = [];

        foreach ($this->origen->documentos($this->coleccion(), [], ['numero']) as $documento) {
            $numero = (int) ($documento['numero'] ?? 0);
            $veces[$numero] = ($veces[$numero] ?? 0) + 1;
        }

        $repetidos = array_filter($veces, static fn (int $n): bool => $n > 1);

        if ($repetidos === []) {
            return [];
        }

        return [sprintf(
            '%d números de cliente quedan repetidos al castearlos a INTEGER, entre %d clientes. '
            .'clientes.numero es UNIQUE: se resuelven con la pantalla «Clientes con número '
            .'repetido» antes del ETL (DATA-4).',
            count($repetidos),
            array_sum($repetidos),
        )];
    }

    /**
     * El `nombre` que hay que guardar: NULL cuando el guardado es el de la
     * persona, porque entonces lo deriva `nombre_mostrado`.
     */
    private static function nombrePropio(mixed $nombre, ?string $nombres, ?string $apellidos): ?string
    {
        $nombre = self::texto($nombre);
        $persona = self::texto(trim(($nombres ?? '').' '.($apellidos ?? '')));

        if ($nombre === null || $persona === null) {
            return $nombre;
        }

        return mb_strtolower($nombre) === mb_strtolower($persona) ? null : $nombre;
    }

    /**
     * @param  array<string, mixed>  $documento
     */
    private function user(array $documento): ?int
    {
        $legacy = Origen::id($documento['user_id'] ?? null);

        if ($legacy === null) {
            return null;
        }

        return $this->users[$legacy] ?? throw new RuntimeException(
            "El cliente apunta al usuario «{$legacy}», que no está en migracion_ids. "
            .'¿Se cargó users antes?'
        );
    }

    /**
     * Sin espacios de más, ni alrededor ni adentro: es lo que hace
     * `Cliente::sinEspaciosDeMas()` en el sistema viejo, y lo que la columna
     * generada `nombre_mostrado` va a hacer igual. Que entren ya aplastados
     * mantiene la comparación de nombres honesta.
     */
    private static function aplastar(string $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $valor));
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = self::aplastar($valor);

        return $texto === '' ? null : $texto;
    }
}
