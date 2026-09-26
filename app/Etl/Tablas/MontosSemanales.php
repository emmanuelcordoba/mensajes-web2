<?php

namespace App\Etl\Tablas;

use App\Etl\Migrador;
use App\Etl\Origen;

/**
 * montos_semanales. Dos filas: el monto de cobranza semanal por tipo de
 * vehículo. Es una tabla de parámetros, no de datos.
 */
class MontosSemanales extends Migrador
{
    public function coleccion(): string
    {
        return 'montos_semanales';
    }

    public function tabla(): string
    {
        return 'montos_semanales';
    }

    public function campos(): array
    {
        return ['tipo_vehiculo', 'monto', 'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        return [
            'tipo_vehiculo' => trim((string) ($documento['tipo_vehiculo'] ?? '')),
            'monto' => is_int($documento['monto'] ?? null) || is_float($documento['monto'] ?? null)
                ? (float) $documento['monto']
                : 0.0,
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $veces = [];

        foreach ($this->origen->documentos($this->coleccion(), [], ['tipo_vehiculo']) as $documento) {
            $tipo = trim((string) ($documento['tipo_vehiculo'] ?? ''));
            $veces[$tipo] = ($veces[$tipo] ?? 0) + 1;
        }

        $repetidos = array_keys(array_filter($veces, static fn (int $n): bool => $n > 1));

        return $repetidos === [] ? [] : [sprintf(
            'montos_semanales.tipo_vehiculo es UNIQUE y está repetido: %s.',
            implode(', ', $repetidos),
        )];
    }
}
