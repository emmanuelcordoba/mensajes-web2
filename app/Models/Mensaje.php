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
 * @property-read Cadete $cadete
 * @property-read User $user
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
     * Quién lo escribió desde la central.
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
