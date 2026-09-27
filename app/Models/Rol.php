<?php

namespace App\Models;

use Database\Factories\RolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un rol del panel. Son nueve.
 *
 * ⚠️ **La colección vieja se llama `roles`, no `rols`.** El pluralizador de Eloquent
 * sobre `Rol` da `rols`, que existe en MongoDB con seis documentos y está muerta:
 * eso ya hizo cargar datos equivocados al ETL una vez. Acá la tabla es `roles` y no
 * hay ambigüedad. Ver DATA-3.
 *
 * ⚠️ Hay **tres roles repetidos** en producción, con cero usuarios cada uno, y la
 * columna es UNIQUE: se borran antes de migrar. Ver DATA-6.
 *
 * @property int $id
 * @property string $rol
 * @property string $display_rol
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 */
#[Fillable(['rol', 'display_rol'])]
class Rol extends Model
{
    /** @use HasFactory<RolFactory> */
    use HasFactory;

    /**
     * ⚠️ Declarado a mano, y no es opcional: el pluralizador de Eloquent sobre `Rol`
     * da `rols`. Sin esta línea las consultas van a una tabla que no existe, y en
     * MongoDB iban a una que **sí** existía y estaba muerta. Ver el docblock de
     * arriba.
     */
    protected $table = 'roles';

    public const ADMIN = 'admin';

    public const EMPLEADO = 'empleado';

    public const RESTRINGIDO = 'restringido';

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
