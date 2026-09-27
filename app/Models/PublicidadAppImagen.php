<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * La imagen de una publicidad del carrusel. Son 21, y guardan la ruta de un
 * archivo (PERF-2).
 *
 * Una por publicidad: `publicidad_id` es UNIQUE.
 *
 * @property int $id
 * @property int $publicidad_id
 * @property string $ruta_archivo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PublicidadApp $publicidad
 */
#[Fillable(['publicidad_id', 'ruta_archivo'])]
class PublicidadAppImagen extends Model
{
    protected $table = 'publicidad_app_imagenes';

    /** @return BelongsTo<PublicidadApp, $this> */
    public function publicidad(): BelongsTo
    {
        return $this->belongsTo(PublicidadApp::class, 'publicidad_id');
    }
}
