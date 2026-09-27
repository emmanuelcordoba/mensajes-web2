<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Por qué puede estar bloqueada una cuenta. Son tres, y los siembra una migración.
 *
 * En el sistema viejo eran constantes de `User` y un texto libre en la columna. Acá
 * son una tabla con clave foránea, así que **un motivo inventado no entra**: es lo
 * que PANEL-12 dejó a la vista, donde el bloqueo automático comparaba contra un
 * campo que no existía.
 *
 * ⚠️ La clave primaria es el código, no un id: es un catálogo cerrado y el código
 * es lo que guarda `users.bloqueado`. No hay timestamps por lo mismo.
 *
 * @property string $codigo
 * @property string $descripcion
 * @property-read Collection<int, User> $users
 */
class MotivoBloqueo extends Model
{
    public const FALTA_DE_PAGO = 'falta-de-pago';

    public const RECLAMO_PEDIDO = 'reclamo-pedido';

    public const OTRO_MOTIVO = 'otro-motivo';

    protected $table = 'motivos_bloqueo';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'bloqueado', 'codigo');
    }
}
