<?php

namespace App\Etl\Tablas;

use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * logs_estados_pedidos. **La tabla más grande de la migración**: 3.637.456
 * filas, más que los 959.973 pedidos. Va última de las que dependen de pedidos.
 *
 * Este archivo existió mucho tiempo creyendo que la colección estaba vacía: la
 * copia local se había volcado sin ella. Ver DATA-5.
 *
 * ⚠️ `pedido_id` se resuelve POR LOTE, no con el mapa entero. `pedidos` tiene
 * 959.973 entradas en `migracion_ids` y sostenerlas en memoria es justo lo que
 * el diseño evita; resolver de a una fila serían 3,6 millones de consultas. Mil
 * ids en un `IN` cada mil filas es el punto medio. Ver
 * Migrador::prepararLote().
 *
 * `user_id` y `cadete_id` sí usan el mapa completo: 9.238 y 1.768 entradas.
 *
 * ⚠️ El vocabulario de `plataforma_origen` NO es el de `pedidos`. Acá las
 * constantes de LogEstadoPedido son `web`, `api`, `cliente-app` y `cadete-app`;
 * allá son `web`, `app` y `api`. Son dos CHECK distintos a propósito.
 */
class LogsEstadosPedidos extends Migrador
{
    /** @var array<string, int> El mapa del lote que se está traduciendo. */
    private array $pedidos = [];

    /** @var array<string, array<string, int>> */
    private array $mapas = [];

    public function coleccion(): string
    {
        return 'logs_estados_pedidos';
    }

    public function tabla(): string
    {
        return 'logs_estados_pedidos';
    }

    public function campos(): array
    {
        return ['pedido_id', 'user_id', 'cadete_id', 'estado', 'mensaje',
            'plataforma_origen', 'created_at', 'updated_at'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $documentos
     */
    protected function prepararLote(array $documentos): void
    {
        $ids = [];

        foreach ($documentos as $documento) {
            $id = Origen::id($documento['pedido_id'] ?? null);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $this->pedidos = MapaDeIds::deVarios('pedidos', $ids);
    }

    public function fila(array $documento): ?array
    {
        $legacy = Origen::id($documento['pedido_id'] ?? null);

        $pedido = ($legacy === null ? null : ($this->pedidos[$legacy] ?? null))
            ?? throw new RuntimeException(
                'Un log apunta al pedido «'.($legacy ?? 'ninguno').'», que no está en '
                .'migracion_ids. La columna es NOT NULL. ¿Se cargó pedidos antes?'
            );

        return [
            'pedido_id' => $pedido,
            'user_id' => $this->referencia($documento, 'user_id', 'users'),
            'cadete_id' => $this->referencia($documento, 'cadete_id', 'cadetes'),
            'estado' => trim((string) ($documento['estado'] ?? '')),
            'mensaje' => self::texto($documento['mensaje'] ?? null),
            'plataforma_origen' => trim((string) ($documento['plataforma_origen'] ?? '')),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }

    /**
     * Las dos nullable. Que falten es válido —un pedido creado sin cadete se
     * registra igual—, que estén y no resuelvan, no.
     *
     * @param  array<string, mixed>  $documento
     */
    private function referencia(array $documento, string $campo, string $coleccion): ?int
    {
        $valor = $documento[$campo] ?? null;

        if ($valor === null || $valor === '') {
            return null;
        }

        $legacy = Origen::id($valor);
        $this->mapas[$coleccion] ??= MapaDeIds::mapa($coleccion);

        return ($legacy === null ? null : ($this->mapas[$coleccion][$legacy] ?? null))
            ?? throw new RuntimeException(
                "Un log apunta a `{$campo}` = «".($legacy ?? 'ilegible').'», que no está en '
                .'migracion_ids.'
            );
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = trim((string) preg_replace('/\s+/u', ' ', $valor));

        return $texto === '' ? null : $texto;
    }
}
