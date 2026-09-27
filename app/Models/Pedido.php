<?php

namespace App\Models;

use App\Models\Concerns\LeeLoQueEscribeLaBase;
use Database\Factories\PedidoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Un pedido: el centro del sistema. Son 959.973 en producción.
 *
 * ## El número ya no se calcula
 *
 * Sale de `pedidos_numero_seq` por DEFAULT. El sistema viejo lo resolvía con un
 * contador en la base y, antes de eso, leyendo el máximo de casi un millón de
 * documentos (PERF-1). La condición de carrera de ese camino es la causa de
 * DATA-1: **11.295 números repetidos entre 23.524 pedidos**. Una secuencia es
 * atómica y no puede producir duplicados.
 *
 * ⚠️ Pero la columna **no es UNIQUE**, a diferencia de `clientes.numero`, porque
 * los 23.524 duplicados históricos se migran tal cual. O sea que nada impide un
 * repetido nuevo si alguien escribe el número a mano: no hay que hacerlo.
 *
 * ## Dos formas de nombrar al destinatario, y las dos son correctas
 *
 * `cliente_id` es NULL en el 57% de los pedidos y `nombre_cliente` tiene el
 * nombre suelto: los pedidos cargados desde el panel no vinculan un cliente. No
 * es un error a normalizar, es cómo trabaja la oficina. Vincular lo que se puede
 * es lo que hacen las pantallas de DATA-8.
 *
 * ⚠️ **A propósito no hay `$with`.** El modelo viejo traía `user` y `cliente` en
 * toda consulta, así que un listado de mil pedidos pagaba dos relaciones que casi
 * nunca se usaban. Cada consulta dice qué necesita.
 *
 * @property int $id
 * @property int|null $numero
 * @property string|null $direccion
 * @property string|null $destino
 * @property string|null $detalle
 * @property string|null $responsable
 * @property string|null $telefono
 * @property string|null $valor
 * @property string|null $garantia
 * @property string|null $tipo_paquete
 * @property string|null $peso_paquete
 * @property string|null $valor_declarado
 * @property string $plataforma_origen
 * @property string $estado
 * @property bool $gastronomia
 * @property bool $retorno_origen
 * @property int|null $cliente_id
 * @property string|null $nombre_cliente
 * @property int|null $cadete_id
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Cliente|null $cliente
 * @property-read Cadete|null $cadete
 * @property-read User|null $user
 */
#[Fillable([
    'direccion',
    'destino',
    'detalle',
    'responsable',
    'telefono',
    'valor',
    'garantia',
    'tipo_paquete',
    'peso_paquete',
    'valor_declarado',
    'plataforma_origen',
    'estado',
    'gastronomia',
    'retorno_origen',
    'cliente_id',
    'nombre_cliente',
    'cadete_id',
    'user_id',
])]
class Pedido extends Model
{
    /** @use HasFactory<PedidoFactory> */
    use HasFactory, LeeLoQueEscribeLaBase, SoftDeletes;

    /**
     * `numero` sale de `pedidos_numero_seq`, y los dos booleanos tienen
     * `DEFAULT FALSE`: sin esto vuelven en NULL donde la tabla dice `false`. Ver el
     * trait. `numero` además es el número que imprime el comprobante.
     *
     * @return list<string>
     */
    protected function loQueEscribeLaBase(): array
    {
        return ['numero', 'gastronomia', 'retorno_origen'];
    }

    public const ESTADO_SIN_ASIGNAR = 'Sin asignar';

    public const ESTADO_COTIZADO = 'Cotizado';

    public const ESTADO_ACEPTADO = 'Aceptado';

    public const ESTADO_ASIGNADO = 'Asignado';

    public const ESTADO_EN_CURSO = 'En curso';

    public const ESTADO_RECHAZADO = 'Rechazado';

    public const ESTADO_CANCELADO = 'Cancelado';

    public const ESTADO_FINALIZADO = 'Finalizado';

    /** Los ocho, en el orden del CHECK de la columna. */
    public const ESTADOS = [
        self::ESTADO_SIN_ASIGNAR,
        self::ESTADO_COTIZADO,
        self::ESTADO_ACEPTADO,
        self::ESTADO_ASIGNADO,
        self::ESTADO_EN_CURSO,
        self::ESTADO_RECHAZADO,
        self::ESTADO_CANCELADO,
        self::ESTADO_FINALIZADO,
    ];

    /**
     * Los estados en que el cliente todavía puede cancelar desde la app: los que
     * no tienen cadete. Ver API-3, que es lo que pasaba cuando no se comprobaba:
     * la app aceptaba y cancelaba un pedido en cualquier estado.
     *
     * ⚠️ No es la misma lista que la cola del panel —ver `scopeEsperandoCadete`—,
     * y conviene no unificarlas: significan cosas distintas.
     */
    public const ESTADOS_QUE_EL_CLIENTE_PUEDE_CANCELAR = [
        self::ESTADO_SIN_ASIGNAR,
        self::ESTADO_COTIZADO,
        self::ESTADO_ACEPTADO,
        self::ESTADO_RECHAZADO,
        self::ESTADO_CANCELADO,
    ];

    /** Los que ya no esperan a nadie, y en la cola del panel van arriba. */
    private const ESTADOS_RESUELTOS = [
        self::ESTADO_CANCELADO,
        self::ESTADO_RECHAZADO,
    ];

    public const PLATAFORMA_WEB = 'web';

    public const PLATAFORMA_APP = 'app';

    public const PLATAFORMA_API = 'api';

    /**
     * ⚠️ `valor`, `garantia` y `valor_declarado` NO se castean.
     *
     * Son `NUMERIC(12,2)` y llegan como string. Castearlos a `float` convertiría
     * montos de dinero en binario de punto flotante, que es exactamente donde
     * aparecen los centavos perdidos. Se operan como decimal.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gastronomia' => 'boolean',
            'retorno_origen' => 'boolean',
        ];
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * El cadete que lo lleva. NULLABLE: un pedido sin asignar no tiene ninguno.
     *
     * @return BelongsTo<Cadete, $this>
     */
    public function cadete(): BelongsTo
    {
        return $this->belongsTo(Cadete::class);
    }

    /**
     * Quién lo cargó: un empleado desde el panel, o el cliente desde la app.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * El destinatario, venga del cliente vinculado o del nombre suelto.
     *
     * ⚠️ El `?->` lleva una supresión del análisis porque larastan da la relación como
     * no nula y **se equivoca**: `cliente_id` es NULL en 522.517 de los 916.876
     * pedidos. Sacarlo para que el análisis quede contento rompería el 57%.
     *
     * Vale igual para un cliente dado de baja: `belongsTo` no trae los borrados,
     * así que la relación da null y se cae al nombre suelto, que es lo correcto.
     */
    public function nombreDelCliente(): ?string
    {
        $cliente = $this->cliente;

        // @phpstan-ignore nullsafe.neverNull
        return $cliente?->nombre_mostrado ?? $this->nombre_cliente;
    }

    /**
     * Lo que se le descuenta del saldo al cadete por finalizar este pedido: un
     * porcentaje del valor del pedido.
     *
     * ⚠️ Se calcula con bcmath sobre las cadenas, no con floats. Acá es donde la
     * decisión de no castear `valor` rinde: el sistema viejo hacía
     * `$this->valor * ($porcentaje/100)` en punto flotante, sobre la deuda de una
     * persona. Devuelve una cadena con dos decimales, lista para la columna.
     *
     * @return numeric-string
     */
    public function montoDeCobranzaSaldo(): string
    {
        $porcentaje = Configuracion::numeroCrudo('COBRANZA_SALDO_PORCENTAJE');

        return bcmul(self::comoMonto($this->valor), bcdiv($porcentaje, '100', 8), 2);
    }

    /**
     * Un monto que salió de una columna NUMERIC, listo para bcmath.
     *
     * La columna es nullable, y un pedido sin valor vale 0 para estas cuentas.
     *
     * @return numeric-string
     */
    public static function comoMonto(?string $valor): string
    {
        return is_numeric($valor) ? $valor : '0';
    }

    /** Si todavía no hay cadete, el cliente puede cancelarlo desde la app. */
    public function loPuedeCancelarElCliente(): bool
    {
        return in_array($this->estado, self::ESTADOS_QUE_EL_CLIENTE_PUEDE_CANCELAR, true);
    }

    /**
     * Sólo los pedidos que ese usuario puede ver.
     *
     * Es la misma regla que `User::alcanzaPedido()` pero en la consulta, porque un
     * listado no puede preguntar fila por fila. **Las dos tienen que responder lo
     * mismo**, y hay una prueba que las compara pedido por pedido: si se separan,
     * vuelve SEC-7 por el lado del listado.
     *
     * Un `restringido` sin comercio asignado no ve ninguno, no todos.
     *
     * @param  Builder<Pedido>  $query
     * @return Builder<Pedido>
     */
    public function scopeAlcanzablesPor(Builder $query, User $user): Builder
    {
        if (! $user->tieneRol(Rol::RESTRINGIDO)) {
            return $query;
        }

        if ($user->cliente_restringido_id === null) {
            return $query->whereRaw('false');
        }

        return $query->where('cliente_id', $user->cliente_restringido_id);
    }

    /**
     * La cola del panel: los pedidos que esperan un cadete.
     *
     * El orden no es cosmético. Los que ya se resolvieron —cancelado, rechazado—
     * van primero y por fecha descendente; los que esperan van después y por fecha
     * ascendente, o sea **el más viejo primero**, que es el que hay que atender.
     * El sistema viejo lo armaba con dos consultas y un `push` en PHP.
     *
     * ⚠️ `Cotizado` queda afuera, igual que en el sistema viejo: un pedido
     * cotizado espera que el cliente acepte el precio, no que aparezca un cadete.
     *
     * @param  Builder<Pedido>  $query
     * @return Builder<Pedido>
     */
    public function scopeEsperandoCadete(Builder $query): Builder
    {
        return $query
            ->whereIn('estado', [
                self::ESTADO_CANCELADO,
                self::ESTADO_RECHAZADO,
                self::ESTADO_SIN_ASIGNAR,
                self::ESTADO_ACEPTADO,
            ])
            ->orderByRaw(
                'CASE WHEN estado IN (?, ?) THEN 0 ELSE 1 END',
                self::ESTADOS_RESUELTOS,
            )
            ->orderByRaw(
                'CASE WHEN estado IN (?, ?) THEN updated_at END DESC NULLS LAST',
                self::ESTADOS_RESUELTOS,
            )
            ->orderBy('updated_at');
    }
}
