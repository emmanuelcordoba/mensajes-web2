<?php

namespace App\Etl\Tablas;

use App\Etl\Migrador;
use App\Etl\Origen;

/**
 * roles. Va primera porque users la referencia.
 *
 * ⚠️ DATA-6 dejó roles duplicados en producción —dos «empleado»— y hay que
 * resolverlos ANTES del ETL: acá `rol` es UNIQUE y la carga falla. Que falle es
 * lo correcto; el ETL no elige cuál de los dos sobrevive.
 *
 * ⚠️ La colección es `roles`, NO `rols`. `app/Rol.php` del sistema viejo
 * declara `protected $collection = 'roles'`, así que el pluralizador de
 * Eloquent no manda acá. `rols` existe —6 documentos— pero está muerta: es el
 * residuo de una versión anterior del modelo, y DATA-3 la manda borrar.
 */
class Roles extends Migrador
{
    public function coleccion(): string
    {
        return 'roles';
    }

    public function tabla(): string
    {
        return 'roles';
    }

    public function anotaIds(): bool
    {
        return true;
    }

    public function campos(): array
    {
        return ['rol', 'display_rol', 'created_at', 'updated_at'];
    }

    /**
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $veces = [];

        foreach ($this->origen->documentos($this->coleccion(), [], ['rol']) as $documento) {
            $rol = (string) ($documento['rol'] ?? '');
            $veces[$rol] = ($veces[$rol] ?? 0) + 1;
        }

        $repetidos = array_keys(array_filter($veces, static fn (int $n): bool => $n > 1));

        if ($repetidos === []) {
            return [];
        }

        return [sprintf(
            'roles.rol es UNIQUE y en el origen están repetidos: %s. Son un seeder corrido dos '
            .'veces y los duplicados no tienen usuarios, así que se borran por script (DATA-6).',
            implode(', ', $repetidos),
        )];
    }

    public function fila(array $documento): ?array
    {
        return [
            'rol' => $documento['rol'],
            'display_rol' => $documento['display_rol'] ?? $documento['rol'],
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }
}
