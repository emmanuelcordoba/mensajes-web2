<?php

namespace App\Models;

use Database\Factories\CadeteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Un cadete. Son 1.768 en producción, y la tabla con más restricciones del
 * esquema, porque es la que tenía más datos sucios.
 *
 * ## Lo que el esquema ahora garantiza, y antes no
 *
 * `numero_movil` y `dni` son UNIQUE **incluyendo a los dados de baja** (decidido el
 * 2026-09-12), `dni` es INTEGER, `estado` y `tipo_vehiculo` tienen CHECK, y
 * `modalidad_cobranza` es NOT NULL con DEFAULT 'Semanal'. En el sistema viejo el
 * mismo DNI convivía como `"30111222"` y `30111222` —105 grupos repetidos al
 * castear, DATA-4— y había cadetes sin modalidad.
 *
 * ⚠️ Por eso **`guardarDni()` no existe acá**. En MongoDB había que escribir el DNI
 * con un `update()` directo, porque Eloquent no veía `"30111222"` → `30111222` como
 * un cambio y `save()` no escribía nada (PANEL-15). Sobre una columna INTEGER el
 * problema no puede existir.
 *
 * ⚠️ `fecha_nacimiento` es `DATE NOT NULL` **sin CHECK de rango**, así que la base
 * acepta el año 190. Hay 32 cadetes con fechas imposibles y son 41 personas
 * contando las postulaciones (DATA-10): se corrigen antes de migrar, no acá.
 *
 * ## Lo que falta, porque los modelos no existen todavía
 *
 * Faltan las relaciones con `ActividadCadete`, `Mensaje`, `LogEstadoPedido`,
 * `MovimientoCobranzaSaldo` y `Postulacion`, y con ellas:
 *
 * - `cerrarActividadSiSaleDeLaApp()` y el `saving` que lo dispara (PANEL-17).
 * - `cambiarEstado()`, que abre y cierra actividad.
 * - `cobrarSaldoPedido()`.
 *
 * @property int $id
 * @property int $numero_movil
 * @property string $apellidos
 * @property string $nombres
 * @property string $direccion
 * @property string $telefono
 * @property Carbon $fecha_nacimiento
 * @property int|null $dni
 * @property string|null $observaciones
 * @property string $estado
 * @property string $tipo_vehiculo
 * @property float|null $ubicacion_lat
 * @property float|null $ubicacion_lon
 * @property string|null $fcm_token
 * @property int|null $orden_cola
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_vencimiento
 * @property string|null $monto_semanal
 * @property string|null $monto_deuda
 * @property string|null $monto_pagado_efectivo
 * @property string|null $monto_pagado_tickets
 * @property string|null $cobranza_saldo
 * @property bool $tiene_monto_semanal_personal
 * @property string $modalidad_cobranza
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $nombre_completo
 * @property-read User $user
 * @property-read Collection<int, Pedido> $pedidos
 */
#[Fillable([
    'numero_movil',
    'apellidos',
    'nombres',
    'direccion',
    'telefono',
    'fecha_nacimiento',
    'dni',
    'observaciones',
    'estado',
    'tipo_vehiculo',
    'ubicacion_lat',
    'ubicacion_lon',
    'fcm_token',
    'orden_cola',
    'fecha_inicio',
    'fecha_vencimiento',
    'monto_semanal',
    'monto_deuda',
    'monto_pagado_efectivo',
    'monto_pagado_tickets',
    'cobranza_saldo',
    'tiene_monto_semanal_personal',
    'modalidad_cobranza',
    'user_id',
])]
class Cadete extends Model
{
    /** @use HasFactory<CadeteFactory> */
    use HasFactory, SoftDeletes;

    public const ESTADO_ACTIVO_APP = 'activo-app';

    public const ESTADO_ACTIVO_WEB = 'activo-web';

    public const ESTADO_INACTIVO = 'inactivo';

    public const ESTADO_CON_PEDIDO_APP = 'con-pedido-app';

    public const ESTADO_POSTULADO = 'postulado';

    /** Los cinco, en el orden del CHECK de la columna. */
    public const ESTADOS = [
        self::ESTADO_ACTIVO_APP,
        self::ESTADO_ACTIVO_WEB,
        self::ESTADO_INACTIVO,
        self::ESTADO_CON_PEDIDO_APP,
        self::ESTADO_POSTULADO,
    ];

    /** Los estados en que el cadete está trabajando desde la app. */
    public const ESTADOS_EN_LA_APP = [
        self::ESTADO_ACTIVO_APP,
        self::ESTADO_CON_PEDIDO_APP,
    ];

    /** Los estados en que está en la cola y puede recibir un pedido. */
    public const ESTADOS_ACTIVOS = [
        self::ESTADO_ACTIVO_APP,
        self::ESTADO_ACTIVO_WEB,
    ];

    public const COBRANZA_SEMANAL = 'Semanal';

    public const COBRANZA_SALDO = 'Saldo';

    public const VEHICULO_BICICLETA = 'B';

    public const VEHICULO_MOTOCICLETA = 'M';

    /** Sólo hay dos, confirmado contra los 1.689 documentos del origen. */
    public const VEHICULOS = [
        self::VEHICULO_BICICLETA => 'Bicicleta',
        self::VEHICULO_MOTOCICLETA => 'Motocicleta',
    ];

    /**
     * ⚠️ Los montos —`monto_semanal`, `monto_deuda`, `cobranza_saldo` y los dos
     * pagados— NO se castean. Son `NUMERIC(12,2)` y llegan como string: castearlos
     * a `float` es donde se pierden los centavos de una deuda.
     *
     * `orden_cola` es un timestamp Unix en segundos, no una posición. Es BIGINT
     * porque un INTEGER desborda en enero de 2038.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_inicio' => 'date',
            'fecha_vencimiento' => 'date',
            'ubicacion_lat' => 'float',
            'ubicacion_lon' => 'float',
            'orden_cola' => 'integer',
            'tiene_monto_semanal_personal' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Pedido, $this> */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /**
     * Apellidos primero, que es como lo muestra el panel.
     *
     * @return Attribute<string, never>
     */
    protected function nombreCompleto(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("{$this->apellidos} {$this->nombres}"),
        );
    }

    public function estaEnLaApp(): bool
    {
        return in_array($this->estado, self::ESTADOS_EN_LA_APP, true);
    }

    public function estaActivo(): bool
    {
        return in_array($this->estado, self::ESTADOS_ACTIVOS, true);
    }

    public function cobraPorSaldo(): bool
    {
        return $this->modalidad_cobranza === self::COBRANZA_SALDO;
    }

    /**
     * Si ESTE cadete tiene un pedido en curso.
     *
     * ⚠️ El método viejo consultaba `Auth::user()->cadete`, o sea que preguntaba
     * por el cadete de la sesión y no por `$this`: llamarlo sobre otro cadete
     * respondía por el que estaba conectado. Acá pregunta por sí mismo.
     */
    public function tienePedidoEnCurso(): bool
    {
        return $this->pedidos()->where('estado', Pedido::ESTADO_EN_CURSO)->exists();
    }

    /**
     * Su lugar en la cola, empezando en 1, o null si no está en la cola.
     *
     * ⚠️ El método viejo devolvía `false` para el primero de la cola, porque
     * `Collection::search()` devuelve el índice 0 y el código hacía
     * `$posicion ? $posicion+1 : $posicion`. O sea que el cadete que iba primero
     * veía lo mismo que uno que no estaba en la cola.
     */
    public function posicionEnCola(): ?int
    {
        // La columna es nullable, y un cadete activo sin orden_cola no está
        // realmente en la cola: no tiene lugar que informar.
        if (! $this->estaActivo() || $this->orden_cola === null) {
            return null;
        }

        return self::query()->enLaCola()->where('orden_cola', '<=', $this->orden_cola)->count();
    }

    /**
     * Los que están en la cola, en orden de llegada.
     *
     * @param  Builder<Cadete>  $query
     * @return Builder<Cadete>
     */
    public function scopeEnLaCola(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_ACTIVOS)->orderBy('orden_cola');
    }

    /**
     * Sugerencia para el campo «número de móvil» del alta de una postulación.
     *
     * Es una lectura pura: el valor sólo precarga un campo que el administrador
     * reescribe a mano. **No hay secuencia** y no debe haberla —en la práctica se
     * recicla el número del cadete que se va, así que los números vuelven para
     * atrás—. Ver PANEL-5.
     *
     * `withTrashed` porque el número de un cadete dado de baja ya fue emitido, y
     * además es parte del UNIQUE.
     *
     * ⚠️ En el sistema viejo esto traía los 1.768 números y los casteaba en PHP,
     * porque algunos estaban guardados como string y BSON los ordena después de los
     * números. Sobre una columna INTEGER es un `max()` que sale del índice.
     */
    public static function siguienteNumeroMovil(): int
    {
        return (int) self::withTrashed()->max('numero_movil') + 1;
    }

    /**
     * El saldo mínimo con que un cadete puede seguir tomando pedidos, redondeado
     * hacia arriba al siguiente múltiplo de 100.
     *
     * El método viejo tenía antes un `if ($saldo % 100 == 0) return $saldo;`. Es
     * redundante: `ceil()` de un valor que ya es múltiplo de 100 devuelve ese mismo
     * valor. Se saca porque hacía parecer que había dos casos, y hay uno.
     */
    public static function saldoMinimo(): float
    {
        $porcentaje = Configuracion::numero('COBRANZA_SALDO_PORCENTAJE');
        $costoMinimo = Configuracion::numero('COSTO_MINIMO');

        $minimo = $porcentaje * ($costoMinimo / 100);

        return ceil($minimo / 100) * 100;
    }
}
