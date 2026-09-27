<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $iniciales
 * @property string|null $color
 * @property int|null $codigo_de_verificacion
 * @property Carbon|null $codigo_generado_at
 * @property string|null $bloqueado
 * @property string|null $bloqueado_mensaje
 * @property int|null $rol_id
 * @property int|null $cliente_restringido_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $color_contraste
 * @property-read Rol|null $rol
 * @property-read MotivoBloqueo|null $motivoBloqueo
 * @property-read UserFoto|null $foto
 * @property-read Cliente|null $cliente
 * @property-read Cliente|null $clienteRestringido
 * @property-read Cadete|null $cadete
 * @property-read Collection<int, Pedido> $pedidos
 * @property-read Collection<int, Mensaje> $mensajes
 */
#[Fillable([
    'name',
    'email',
    'password',
    'iniciales',
    'color',
    'codigo_de_verificacion',
    'codigo_generado_at',
    'bloqueado',
    'bloqueado_mensaje',
    'rol_id',
    'cliente_restringido_id',
])]
// ⚠️ `codigo_de_verificacion` está oculto porque viajaba en todo JSON que incluyera
// un usuario —el pedido que ve el cadete trae a quien lo cargó—, y con ese código se
// le cambia la contraseña. Ver SEC-10.
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
    'codigo_de_verificacion',
])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'codigo_de_verificacion' => 'integer',
            'codigo_generado_at' => 'datetime',
        ];
    }

    /**
     * Store the email lowercase and trimmed, which the database requires.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /** @return BelongsTo<Rol, $this> */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    /**
     * Por qué está bloqueada la cuenta, si lo está.
     *
     * En el sistema viejo el motivo era texto libre y las constantes vivían en este
     * modelo. Acá es una clave foránea a un catálogo cerrado, así que un motivo
     * inventado no entra: es lo que PANEL-12 dejó a la vista.
     *
     * @return BelongsTo<MotivoBloqueo, $this>
     */
    public function motivoBloqueo(): BelongsTo
    {
        return $this->belongsTo(MotivoBloqueo::class, 'bloqueado', 'codigo');
    }

    /** @return HasOne<UserFoto, $this> */
    public function foto(): HasOne
    {
        return $this->hasOne(UserFoto::class);
    }

    /**
     * El cliente de la app que cuelga de este usuario.
     *
     * ⚠️ No confundir con `clienteRestringido()`: acá la clave foránea vive en
     * `clientes` y el usuario es el dueño. Allá es al revés.
     *
     * @return HasOne<Cliente, $this>
     */
    public function cliente(): HasOne
    {
        return $this->hasOne(Cliente::class);
    }

    /**
     * El comercio cuyos pedidos ve una cuenta de rol `restringido`.
     *
     * El usuario apunta a un cliente que ya existe, para poder mirar sus pedidos
     * desde el panel sin ser su dueño. Antes este vínculo no era un dato sino un
     * `switch` sobre el nombre del usuario, con el número de cliente escrito en el
     * código. Ver PANEL-3.
     *
     * @return BelongsTo<Cliente, $this>
     */
    public function clienteRestringido(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_restringido_id');
    }

    /** @return HasOne<Cadete, $this> */
    public function cadete(): HasOne
    {
        return $this->hasOne(Cadete::class);
    }

    /** @return HasMany<Pedido, $this> */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /** @return HasMany<Mensaje, $this> */
    public function mensajes(): HasMany
    {
        return $this->hasMany(Mensaje::class);
    }

    public function estaBloqueado(): bool
    {
        return $this->bloqueado !== null;
    }

    /**
     * Si tiene alguno de esos roles.
     *
     * ⚠️ Un usuario **sin rol** devuelve false en vez de reventar. El método viejo
     * hacía `$this->rol->rol == $rol` sobre una relación que puede faltar: hay 20
     * usuarios activos sin `rol_id`, y la columna es nullable a propósito.
     *
     * @param  string|list<string>  $roles
     */
    public function tieneRol(string|array $roles): bool
    {
        $rol = $this->rol?->rol;

        if ($rol === null) {
            return false;
        }

        return in_array($rol, is_array($roles) ? $roles : [$roles], true);
    }

    /**
     * Si puede ver o tocar este pedido desde el panel.
     *
     * Admin y empleado alcanzan todos. Un `restringido` sólo los de su comercio, y
     * **sin comercio asignado, ninguno**: sin cliente no hay pertenencia que
     * comprobar.
     *
     * El middleware de rol no alcanza para las rutas que un comercio comparte con la
     * administración: sin esto, un comercio veía, imprimía y borraba los pedidos de
     * cualquier otro mandando el id. Ver SEC-7.
     */
    public function alcanzaPedido(Pedido $pedido): bool
    {
        if (! $this->tieneRol(Rol::RESTRINGIDO)) {
            return true;
        }

        return $this->cliente_restringido_id !== null
            && $pedido->cliente_id === $this->cliente_restringido_id;
    }

    /**
     * Si puede editar la cuenta de otro usuario desde el panel.
     *
     * La cuenta de un administrador sólo la toca otro administrador: si un empleado
     * pudiera cambiarle la contraseña o el email, todo lo que el panel reserva a
     * admin quedaría a un paso de él. Ver SEC-9.
     */
    public function puedeAdministrarA(self $otro): bool
    {
        return $this->tieneRol(Rol::ADMIN) || ! $otro->tieneRol(Rol::ADMIN);
    }

    /**
     * Si el código que llegó coincide con el guardado.
     *
     * ⚠️ Vive acá y no en cada controlador porque son dos los que comparan este campo
     * y **los dos lo hacían mal**, cada uno a su manera: con `==` entre el entero
     * guardado y lo que llega del request. En PHP eso da `123456 == '123456abc'` →
     * true, y sobre todo `null == 0` → true, o sea que un usuario **sin código
     * pendiente aceptaba el número 0**. El validador no lo frenaba: el entero 0 pasa
     * `required`. Ver SEC-3.
     *
     * `hash_equals` compara en tiempo constante. Contra un código de seis dígitos el
     * canal temporal es marginal, pero es el idiom correcto y no cuesta nada.
     *
     * @param  mixed  $codigo  Lo que llegó del request, sin sanear.
     */
    public function codigoDeVerificacionCoincide(mixed $codigo): bool
    {
        // Sin código pendiente no coincide nada. Es la mitad importante.
        if ($this->codigo_de_verificacion === null) {
            return false;
        }

        // El request puede traer un arreglo: {"code": ["a"]} pasa `required`.
        if (! is_scalar($codigo)) {
            return false;
        }

        return hash_equals((string) $this->codigo_de_verificacion, (string) $codigo);
    }

    /**
     * Negro o blanco, el que se lea mejor sobre `color`.
     *
     * Lo usa el avatar de la topbar del panel, que son 32 cuentas. Devuelve null si
     * la cuenta no tiene color, que es el caso de los 9.206 usuarios de las apps.
     *
     * @return Attribute<string|null, never>
     */
    protected function colorContraste(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            if ($this->color === null || ! preg_match('/^#[0-9a-fA-F]{6}$/', $this->color)) {
                return null;
            }

            // Luminancia relativa, con la aproximación de gamma 2.2 que ya usaba el
            // sistema viejo.
            $luminancia = 0.2126 * (hexdec(mb_substr($this->color, 1, 2)) / 255) ** 2.2
                + 0.7152 * (hexdec(mb_substr($this->color, 3, 2)) / 255) ** 2.2
                + 0.0722 * (hexdec(mb_substr($this->color, 5, 2)) / 255) ** 2.2;

            return ($luminancia + 0.05) / 0.05 > 5 ? '#000000' : '#FFFFFF';
        });
    }

    /**
     * Los usuarios con ese email, ignorando mayúsculas y espacios.
     *
     * Los datos viejos no están normalizados, y en 56 casos hay dos cuentas activas
     * que sólo difieren en eso. Ver DATA-7.
     *
     * ⚠️ El CHECK de la columna ya garantiza que lo nuevo entre normalizado, así que
     * esto es para mirar lo que ya está: después de la limpieza, un `where` común
     * alcanza.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeConEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('lower(btrim(email)) = ?', [mb_strtolower(trim($email))]);
    }
}
