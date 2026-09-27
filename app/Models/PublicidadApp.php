<?php

namespace App\Models;

use App\Models\Concerns\LeeLoQueEscribeLaBase;
use Database\Factories\PublicidadAppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Una publicidad del carrusel de la app de clientes. Son 21, y sólo 4 visibles.
 *
 * ⚠️ La app las pide en `get-setup`, y el modelo viejo no tenía `$hidden`, así que
 * **el base64 entero viajaba en el JSON**. Con la imagen en su propia tabla eso ya
 * no pasa por descuido: hay que pedirla. La API sigue devolviendo el data URI para
 * las apps publicadas, pero armándolo desde el archivo.
 *
 * ⚠️ El modelo viejo declaraba `orden` en `$fillable` y esa columna no existe en
 * ningún documento: la asignación masiva lo descartaba en silencio. No se porta.
 *
 * @property int $id
 * @property string $url
 * @property bool $visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read PublicidadAppImagen|null $imagen
 */
#[Fillable(['url', 'visible'])]
class PublicidadApp extends Model
{
    /** @use HasFactory<PublicidadAppFactory> */
    use HasFactory, LeeLoQueEscribeLaBase, SoftDeletes;

    protected $table = 'publicidades_app';

    /**
     * `visible` tiene `DEFAULT TRUE`: sin esto vuelve en NULL donde la tabla dice
     * `true`. Ver el trait.
     *
     * @return list<string>
     */
    protected function loQueEscribeLaBase(): array
    {
        return ['visible'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
        ];
    }

    /** @return HasOne<PublicidadAppImagen, $this> */
    public function imagen(): HasOne
    {
        return $this->hasOne(PublicidadAppImagen::class, 'publicidad_id');
    }

    /**
     * Las que la app tiene que mostrar.
     *
     * @param  Builder<PublicidadApp>  $query
     * @return Builder<PublicidadApp>
     */
    public function scopeVisibles(Builder $query): Builder
    {
        return $query->where('visible', true);
    }
}
