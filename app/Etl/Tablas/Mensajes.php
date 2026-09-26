<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * mensajes. Los 8.543 mensajes entre el panel y los cadetes. Va después de
 * cadetes y users, que referencia con NOT NULL las dos.
 *
 * `user_id` es quien escribió: puede ser alguien del panel o el propio usuario
 * del cadete. La dirección se deduce comparando ese id con el del cadete, que es
 * lo que hace la vista hoy.
 *
 * 20 documentos no tienen `leido_web`/`leido_app`; la columna es NOT NULL
 * DEFAULT FALSE y ausente entra como `false`.
 */
class Mensajes extends Migrador
{
    /** @var array<string, array<string, int>> */
    private array $mapas = [];

    public function coleccion(): string
    {
        return 'mensajes';
    }

    public function tabla(): string
    {
        return 'mensajes';
    }

    public function campos(): array
    {
        return ['cadete_id', 'user_id', 'mensaje', 'leido_web', 'leido_app',
            'created_at', 'updated_at', 'deleted_at'];
    }

    public function fila(array $documento): ?array
    {
        return [
            'cadete_id' => $this->referencia($documento, 'cadete_id', 'cadetes'),
            'user_id' => $this->referencia($documento, 'user_id', 'users'),
            'mensaje' => is_string($documento['mensaje'] ?? null) ? $documento['mensaje'] : null,
            'leido_web' => (bool) ($documento['leido_web'] ?? false),
            'leido_app' => (bool) ($documento['leido_app'] ?? false),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
            'deleted_at' => Origen::fecha($documento['deleted_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $documento
     */
    private function referencia(array $documento, string $campo, string $coleccion): int
    {
        $legacy = Origen::id($documento[$campo] ?? null);
        $this->mapas[$coleccion] ??= MapaDeIds::mapa($coleccion);

        return ($legacy === null ? null : ($this->mapas[$coleccion][$legacy] ?? null))
            ?? throw new RuntimeException(
                "Un mensaje apunta a `{$campo}` = «".($legacy ?? 'ninguno').'», que no está en '
                ."migracion_ids. La columna es NOT NULL. ¿Se cargó {$coleccion} antes?"
            );
    }
}
