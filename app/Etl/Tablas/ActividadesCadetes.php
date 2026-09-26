<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * actividades_cadetes. 450.456 filas. La colección se llama `actividad_cadetes`
 * en el sistema viejo.
 *
 * ⚠️ 113.068 filas no tienen `fin` ni `duracion`, y **no están abiertas**: el
 * sistema viejo sólo escribía el fin en uno de los caminos por los que un cadete
 * sale de la app (PANEL-17, arreglado el 2026-09-17). Se decidió no cerrarlas
 * con una fecha inventada, así que se copian tal cual.
 *
 * Consecuencia para el sistema nuevo, y por eso queda escrito acá: «la actividad
 * abierta de un cadete» es la última sin `fin` MIENTRAS el cadete está en la
 * app, no cualquier fila sin `fin`.
 *
 * `duracion` está en segundos, como la calcula `Cadete.php` con
 * `diffInSeconds()`. La mayor es de 13.706.159 segundos —158 días—, que entra en
 * un INTEGER pero es otra cara del mismo problema de PANEL-17.
 */
class ActividadesCadetes extends Migrador
{
    /** @var array<string, int>|null */
    private ?array $cadetes = null;

    public function coleccion(): string
    {
        return 'actividad_cadetes';
    }

    public function tabla(): string
    {
        return 'actividades_cadetes';
    }

    public function campos(): array
    {
        return ['cadete_id', 'inicio', 'fin', 'duracion', 'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        $this->cadetes ??= MapaDeIds::mapa('cadetes');

        $legacy = Origen::id($documento['cadete_id'] ?? null);

        $cadete = ($legacy === null ? null : ($this->cadetes[$legacy] ?? null))
            ?? throw new RuntimeException(
                'Una actividad apunta al cadete «'.($legacy ?? 'ninguno').'», que no está en '
                .'migracion_ids. La columna es NOT NULL. DATA-6 borró las 205 que apuntaban a '
                .'cadetes inexistentes.'
            );

        return [
            'cadete_id' => $cadete,
            'inicio' => Origen::fecha($documento['inicio'] ?? null),
            'fin' => Origen::fecha($documento['fin'] ?? null),
            'duracion' => isset($documento['duracion']) ? (int) $documento['duracion'] : null,
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }
}
