<?php

namespace App\Models;

use Database\Factories\LogEstadoPedidoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cada cambio de estado de un pedido. Es la tabla más grande del sistema:
 * **3.637.456 filas**.
 *
 * ⚠️ **El vocabulario de `plataforma_origen` NO es el de `pedidos`.** Acá son
 * cuatro —`web`, `api`, `cliente-app`, `cadete-app`— y allá tres —`web`, `app`,
 * `api`—: el log distingue de cuál de las dos apps vino y el pedido no. Los dos
 * CHECK son distintos a propósito, y por eso las constantes viven en cada modelo.
 *
 * ⚠️ El modelo viejo tenía `$with = ['user','cadete']`, sobre 3,6 millones de
 * filas. No se copia.
 *
 * @property int $id
 * @property int $pedido_id
 * @property int|null $user_id
 * @property int|null $cadete_id
 * @property string $estado
 * @property string|null $mensaje
 * @property string $plataforma_origen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pedido $pedido
 * @property-read User|null $user
 * @property-read Cadete|null $cadete
 */
#[Fillable(['pedido_id', 'user_id', 'cadete_id', 'estado', 'mensaje', 'plataforma_origen'])]
class LogEstadoPedido extends Model
{
    /** @use HasFactory<LogEstadoPedidoFactory> */
    use HasFactory;

    public const WEB = 'web';

    public const API = 'api';

    public const CLIENTE_APP = 'cliente-app';

    public const CADETE_APP = 'cadete-app';

    /** Los cuatro, en el orden del CHECK de la columna. */
    public const PLATAFORMAS = [
        self::WEB,
        self::API,
        self::CLIENTE_APP,
        self::CADETE_APP,
    ];

    protected $table = 'logs_estados_pedidos';

    /** @return BelongsTo<Pedido, $this> */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * Quién lo provocó. NULLABLE porque un cambio automático no tiene usuario.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * El cadete al que refiere. NULLABLE: un pedido creado sin cadete se registra
     * igual.
     *
     * @return BelongsTo<Cadete, $this>
     */
    public function cadete(): BelongsTo
    {
        return $this->belongsTo(Cadete::class);
    }

    /**
     * El texto que el panel muestra para un cambio de estado.
     *
     * Devuelve null para un estado que no conoce, en vez de inventar una frase.
     */
    public static function mensajePara(string $estado, ?int $numeroMovil): ?string
    {
        // Cuando el cadete ya fue desasociado del pedido —al rechazarlo— no hay
        // móvil que nombrar, y el sistema viejo escribía «desconocido».
        $movil = $numeroMovil === null ? 'desconocido' : (string) $numeroMovil;

        return match ($estado) {
            Pedido::ESTADO_SIN_ASIGNAR => 'El pedido fue creado sin cadete.',
            Pedido::ESTADO_COTIZADO => 'El pedido fue cotizado.',
            Pedido::ESTADO_ACEPTADO => 'El pedido fue aceptado por el cliente.',
            Pedido::ESTADO_ASIGNADO => "El pedido fue asignado al móvil {$movil}.",
            Pedido::ESTADO_EN_CURSO => "El pedido está en curso, fue aceptado por el móvil {$movil}.",
            Pedido::ESTADO_RECHAZADO => "El pedido fue rechazado por el móvil {$movil}.",
            Pedido::ESTADO_CANCELADO => 'El pedido fue cancelado automáticamente.',
            Pedido::ESTADO_FINALIZADO => "El pedido fue finalizado por el móvil {$movil}.",
            default => null,
        };
    }

    /**
     * Registra el estado actual del pedido, si cambió respecto del último log.
     *
     * Devuelve el log escrito, o null si el estado era el mismo que el anterior:
     * el sistema viejo no registra dos veces el mismo estado seguido.
     *
     * `$cadete` sólo hace falta pasarlo cuando ya fue desasociado del pedido, como
     * al rechazarlo.
     *
     * ⚠️ El `user_id` lo recibe, no lo saca de `Auth`. El método viejo hacía
     * `associate(Auth::user())`, lo que ataba el registro a que hubiera una sesión:
     * desde un comando o una tarea programada quedaba en null sin que nadie lo
     * decidiera.
     *
     * ⚠️ Esto **no es atómico**: entre leer el último log y escribir el nuevo, otro
     * proceso puede escribir uno igual. El sistema viejo tiene el mismo hueco y no
     * se agrava acá; cerrarlo pide una restricción o un bloqueo, y es una decisión
     * aparte.
     */
    public static function registrar(
        Pedido $pedido,
        string $plataformaOrigen,
        ?Cadete $cadete = null,
        ?int $userId = null,
    ): ?self {
        $cadete ??= $pedido->cadete_id === null ? null : $pedido->cadete()->first();

        $ultimo = self::query()
            ->where('pedido_id', $pedido->id)
            ->orderByDesc('created_at')
            ->first();

        if ($ultimo !== null && $ultimo->estado === $pedido->estado) {
            return null;
        }

        return self::query()->create([
            'pedido_id' => $pedido->id,
            'user_id' => $userId,
            'cadete_id' => $cadete?->id,
            'estado' => $pedido->estado,
            'mensaje' => self::mensajePara($pedido->estado, $cadete?->numero_movil),
            'plataforma_origen' => $plataformaOrigen,
        ]);
    }
}
