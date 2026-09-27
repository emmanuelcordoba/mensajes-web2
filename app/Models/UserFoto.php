<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La foto de perfil de un usuario. Son 1.588, y guardan **la ruta de un archivo, no
 * sus bytes** (PERF-2).
 *
 * Una por usuario: `user_id` es UNIQUE. En el sistema viejo era una columna con 411
 * MB de base64 adentro, que viajaba en cada pedido que el cliente miraba.
 *
 * ⚠️ De los 1.615 usuarios con algo en `foto`, 27 tenían
 * `assets/images/avatar.png` —el avatar por omisión de la aplicación vieja— y ésos
 * **no tienen fila acá**: el sistema nuevo no arrastra el placeholder de nadie.
 *
 * ⚠️ `ruta_archivo` es opaca. El `CHECK` valida la forma de una ruta relativa
 * segura, no de dónde salió.
 *
 * @property int $id
 * @property int $user_id
 * @property string $ruta_archivo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'ruta_archivo'])]
class UserFoto extends Model
{
    protected $table = 'user_fotos';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
