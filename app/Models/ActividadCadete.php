<?php

namespace App\Models;

use Database\Factories\ActividadCadeteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un tramo de tiempo que el cadete estuvo conectado en la app. Son 450.456.
 *
 * `fin` en NULL significa **abierto**: el cadete todavía está en la app. La
 * `duracion` va en segundos, como la calculaba el sistema viejo.
 *
 * ⚠️ Que un registro quede abierto para siempre es el bug de PANEL-17: el fin se
 * escribía sólo en `cambiarEstado()` y sólo desde `activo-app`, así que con un
 * pedido en curso, al cerrar sesión o al sacarlo la central de la cola, el tramo
 * no se cerraba nunca. Acá el cierre lo hace `Cadete`, al guardar, en cualquier
 * camino que lo saque de la app.
 *
 * ⚠️ El modelo viejo declaraba `tiempo` en `$fillable` y la columna se llama
 * `duracion`: el campo no existía, así que la asignación masiva lo descartaba en
 * silencio. Acá el nombre es uno solo.
 *
 * @property int $id
 * @property int $cadete_id
 * @property Carbon $inicio
 * @property Carbon|null $fin
 * @property int|null $duracion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cadete $cadete
 */
#[Fillable(['cadete_id', 'inicio', 'fin', 'duracion'])]
class ActividadCadete extends Model
{
    /** @use HasFactory<ActividadCadeteFactory> */
    use HasFactory;

    protected $table = 'actividades_cadetes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'duracion' => 'integer',
        ];
    }

    /** @return BelongsTo<Cadete, $this> */
    public function cadete(): BelongsTo
    {
        return $this->belongsTo(Cadete::class);
    }

    public function estaAbierta(): bool
    {
        return $this->fin === null;
    }

    /**
     * Cierra el tramo ahora, y guarda cuánto duró.
     *
     * ⚠️ `diffInSeconds` va desde `inicio` hacia el final, en ese orden: al revés
     * da el mismo número sólo porque el valor absoluto lo tapa. Se deja explícito
     * porque un tramo con duración negativa sería invisible en un INTEGER.
     */
    public function cerrar(?Carbon $cuando = null): void
    {
        $fin = $cuando ?? Carbon::now();

        $this->fin = $fin;
        $this->duracion = (int) $this->inicio->diffInSeconds($fin, absolute: true);
        $this->save();
    }

    /**
     * Los tramos sin cerrar, el más reciente primero.
     *
     * @param  Builder<ActividadCadete>  $query
     * @return Builder<ActividadCadete>
     */
    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->whereNull('fin')->orderByDesc('inicio');
    }
}
