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
 * La colección se llama `rols` en el sistema viejo, por el pluralizador de
 * Eloquent.
 */
class Roles extends Migrador
{
    public function coleccion(): string
    {
        return 'rols';
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
