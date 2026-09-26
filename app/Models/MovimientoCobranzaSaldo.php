<?php

namespace App\Models;

use Database\Factories\MovimientoCobranzaSaldoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un movimiento en el saldo de un cadete que cobra por saldo. Son 13.412.
 *
 * El signo **no va en `monto`**: va en `monto_positivo`. `true` es una carga,
 * `false` un descuento. `saldo_parcial` es el saldo ANTES del movimiento, y 21
 * movimientos históricos lo tienen en null.
 *
 * ## Las dos columnas que existen por un bug cada una
 *
 * ⚠️ `user_id` es NULLABLE y **ninguno de los 12.827 movimientos migrados lo
 * tiene**, porque en el sistema viejo `user_id` no estaba en `$fillable` y la
 * asignación masiva lo descartaba en silencio (PANEL-13). Ya está arreglado allá,
 * pero los históricos quedan sin atribuir para siempre. Por eso la columna no puede
 * nacer NOT NULL, y por eso el sistema nuevo tiene que escribirla **siempre**.
 *
 * ⚠️ `pedido_id` es nueva. El sistema viejo no guardaba qué pedido originó un
 * descuento, así que no había forma de impedir ni de ver que un pedido se cobrara
 * dos veces: COB-1 midió **438 cobros dobles, $233.243,64**, reconstruyéndolos por
 * cercanía en el tiempo. Un índice único parcial —`cobranza_mov_un_descuento_por_
 * pedido`— permite ahora **un solo descuento por pedido**. Es parcial porque las
 * cargas de saldo y los movimientos migrados no tienen pedido, y los NULL no chocan
 * entre sí.
 *
 * Ese índice es la defensa real: no hay CHECK que exija `pedido_id` para un
 * descuento, porque los migrados no lo tienen. O sea que **escribirlo es
 * responsabilidad de quien cobra**, y si no lo escribe el cobro doble vuelve a ser
 * posible. Ver `Cadete::cobrarSaldoPedido()` cuando se escriba.
 *
 * @property int $id
 * @property int $cadete_id
 * @property string $monto
 * @property bool $monto_positivo
 * @property string|null $saldo_parcial
 * @property string $tipo
 * @property int|null $user_id
 * @property int|null $pedido_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cadete $cadete
 * @property-read User|null $user
 * @property-read Pedido|null $pedido
 */
#[Fillable([
    'cadete_id',
    'monto',
    'monto_positivo',
    'saldo_parcial',
    'tipo',
    'user_id',
    'pedido_id',
])]
class MovimientoCobranzaSaldo extends Model
{
    /** @use HasFactory<MovimientoCobranzaSaldoFactory> */
    use HasFactory;

    public const CARGA_SALDO = 'Carga de saldo';

    public const PEDIDO_FINALIZADO = 'Pedido finalizado';

    /** Los dos, en el orden del CHECK de la columna. */
    public const TIPOS = [
        self::CARGA_SALDO,
        self::PEDIDO_FINALIZADO,
    ];

    protected $table = 'cobranza_saldo_movimientos';

    /**
     * ⚠️ `monto` y `saldo_parcial` NO se castean: son `NUMERIC(12,2)` y llegan como
     * string. Es la deuda de una persona.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_positivo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Cadete, $this> */
    public function cadete(): BelongsTo
    {
        return $this->belongsTo(Cadete::class);
    }

    /**
     * Quién lo originó. Null en los 12.827 migrados, por PANEL-13.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * El pedido que originó el descuento. Null en las cargas y en los migrados.
     *
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function esDescuento(): bool
    {
        return ! $this->monto_positivo;
    }

    /**
     * Los movimientos de un cadete, el más reciente primero. Hay un índice
     * `(cadete_id, created_at DESC)` para esto.
     *
     * @param  Builder<MovimientoCobranzaSaldo>  $query
     * @return Builder<MovimientoCobranzaSaldo>
     */
    public function scopeMasRecientesPrimero(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
