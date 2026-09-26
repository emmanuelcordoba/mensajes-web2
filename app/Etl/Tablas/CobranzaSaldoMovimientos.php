<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * cobranza_saldo_movimientos. Los 13.412 movimientos de la cobranza por saldo.
 * Va después de cadetes, users y pedidos.
 *
 * ⚠️ Son registros de plata y no tienen borrado lógico, ni en el sistema viejo
 * ni acá: no se borran nunca. Por eso el borrado de cadetes no los toca (SEC-6).
 *
 * `user_id` —quién originó el movimiento— es nullable por los históricos: no
 * estaba en `$fillable` y la asignación masiva lo descartaba en silencio
 * (PANEL-13). Arreglado el sistema viejo, **585 de los 13.412 ya lo traen**; los
 * anteriores quedan sin atribuir para siempre.
 *
 * `pedido_id` es una columna nueva —el pedido que originó el descuento— y nace
 * NULL en todos los migrados: el sistema viejo no guarda el dato, y por eso no
 * había forma de ver que un pedido se cobrara dos veces (COB-1, 438 cobros
 * dobles). El índice único parcial que la acompaña sólo mira las filas que la
 * tienen, así que los migrados no lo activan.
 */
class CobranzaSaldoMovimientos extends Migrador
{
    /** @var array<string, array<string, int>> */
    private array $mapas = [];

    public function coleccion(): string
    {
        return 'cobranza_saldo_movimientos';
    }

    public function tabla(): string
    {
        return 'cobranza_saldo_movimientos';
    }

    public function campos(): array
    {
        return ['cadete_id', 'monto', 'monto_positivo', 'saldo_parcial', 'tipo', 'user_id',
            'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        return [
            'cadete_id' => $this->cadete($documento),
            'monto' => self::numero($documento['monto'] ?? null) ?? 0.0,
            'monto_positivo' => (bool) ($documento['monto_positivo'] ?? false),
            'saldo_parcial' => self::numero($documento['saldo_parcial'] ?? null),
            'tipo' => trim((string) ($documento['tipo'] ?? '')),
            'user_id' => $this->user($documento),
            'pedido_id' => null,
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $documento
     */
    private function cadete(array $documento): int
    {
        $legacy = Origen::id($documento['cadete_id'] ?? null);
        $this->mapas['cadetes'] ??= MapaDeIds::mapa('cadetes');

        return ($legacy === null ? null : ($this->mapas['cadetes'][$legacy] ?? null))
            ?? throw new RuntimeException(
                'Un movimiento de cobranza apunta al cadete «'.($legacy ?? 'ninguno').'», que no '
                .'está en migracion_ids. La columna es NOT NULL.'
            );
    }

    /**
     * Que falte es lo normal en los históricos; que esté y no resuelva, no.
     *
     * @param  array<string, mixed>  $documento
     */
    private function user(array $documento): ?int
    {
        $valor = $documento['user_id'] ?? null;

        if ($valor === null || $valor === '') {
            return null;
        }

        $legacy = Origen::id($valor);
        $this->mapas['users'] ??= MapaDeIds::mapa('users');

        return ($legacy === null ? null : ($this->mapas['users'][$legacy] ?? null))
            ?? throw new RuntimeException(
                'Un movimiento de cobranza apunta al usuario «'.($legacy ?? 'ilegible').'», que no '
                .'está en migracion_ids.'
            );
    }

    private static function numero(mixed $valor): ?float
    {
        return is_int($valor) || is_float($valor) ? (float) $valor : null;
    }
}
