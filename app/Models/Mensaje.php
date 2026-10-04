<?php

namespace App\Models;

use App\Models\Concerns\LeeLoQueEscribeLaBase;
use Database\Factories\MensajeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Un mensaje entre la central y un cadete. Son 8.543.
 *
 * Los dos «leído» son independientes porque el mismo mensaje se marca en dos
 * lados: `leido_web` cuando lo ve la central en el panel, `leido_app` cuando lo ve
 * el cadete. Ninguno implica el otro.
 *
 * ⚠️ El modelo viejo tenía `$with = ['cadete.user', 'user']`, o sea que listar los
 * mensajes traía tres relaciones por fila —y una de ellas anidada—. No se copia:
 * cada consulta dice qué necesita.
 *
 * @property int $id
 * @property int $cadete_id
 * @property int $user_id
 * @property string|null $mensaje
 * @property bool $leido_web
 * @property bool $leido_app
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Cadete|null $cadete ⚠️ Puede ser null aunque `mensajes.cadete_id` sea
 *                NOT NULL: `Cadete` usa SoftDeletes, así que si el cadete está dado de
 *                baja la relación no lo devuelve. No es un caso raro —**407 de las 444
 *                conversaciones** son de cadetes que ya no están—, y decía `Cadete` a
 *                secas: con eso, el listado del chat respondía 500 al primer intento. Es
 *                lo mismo que pasaba con `Cadete::$user`.
 * @property-read User|null $user ⚠️ Lo mismo, por lo mismo: `User` también usa
 *                SoftDeletes, así que un mensaje escrito por una cuenta dada de baja
 *                tiene autor en la columna y no en la relación.
 */
#[Fillable(['cadete_id', 'user_id', 'mensaje', 'leido_web', 'leido_app'])]
class Mensaje extends Model
{
    /** @use HasFactory<MensajeFactory> */
    use HasFactory, LeeLoQueEscribeLaBase, SoftDeletes;

    /**
     * Los dos «leído» tienen `DEFAULT FALSE`: sin esto un mensaje recién creado los
     * devuelve en NULL, y la app tendría que distinguir null de false para nada. Ver
     * el trait.
     *
     * @return list<string>
     */
    protected function loQueEscribeLaBase(): array
    {
        return ['leido_web', 'leido_app'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leido_web' => 'boolean',
            'leido_app' => 'boolean',
        ];
    }

    /** @return BelongsTo<Cadete, $this> */
    public function cadete(): BelongsTo
    {
        return $this->belongsTo(Cadete::class);
    }

    /**
     * Quién lo escribió: la central, o la cuenta del propio cadete desde la app.
     *
     * ⚠️ Comparar `mensajes.user_id` con `cadetes.user_id` es cómo se sabe de qué lado
     * viene un mensaje, y son dos columnas: no hace falta cargar esta relación ni la del
     * cadete. La vista vieja preguntaba `$mensaje->cadete->user->id == $mensaje->user->id`
     * y por eso reventaba cuando alguna de las dos cuentas estaba dada de baja.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Los que el cadete todavía no vio en la app.
     *
     * @param  Builder<Mensaje>  $query
     * @return Builder<Mensaje>
     */
    public function scopeSinLeerEnLaApp(Builder $query): Builder
    {
        return $query->where('leido_app', false);
    }

    /**
     * Los que la central todavía no vio en el panel.
     *
     * @param  Builder<Mensaje>  $query
     * @return Builder<Mensaje>
     */
    public function scopeSinLeerEnLaWeb(Builder $query): Builder
    {
        return $query->where('leido_web', false);
    }
}
