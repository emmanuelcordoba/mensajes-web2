<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una de las cuatro imágenes de una postulación. Son 2.900 filas.
 *
 * En el sistema viejo eran cuatro columnas de la postulación, con base64 adentro o
 * con una ruta según si ya se había contratado. Acá son filas, y guardan **la ruta
 * de un archivo, no sus bytes** (PERF-2).
 *
 * ⚠️ Dos de los cuatro tipos son el DNI de la persona, de frente y de dorso. El
 * disco es privado y la API sirve los bytes: nada de esto va en `public/`. Eso es
 * también por qué el panel viejo los muestra rotos (PANEL-20) — apuntaba al disco
 * público y le faltaba el prefijo.
 *
 * ⚠️ **`ruta_archivo` es opaca.** Hoy las escribió el ETL con el ObjectId viejo
 * adentro, y las que escriba la aplicación no van a tener uno. El `CHECK` valida la
 * forma de una ruta relativa segura, no de dónde salió.
 *
 * @property int $id
 * @property int $postulacion_id
 * @property string $tipo
 * @property string $ruta_archivo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Postulacion $postulacion
 */
#[Fillable(['postulacion_id', 'tipo', 'ruta_archivo'])]
class PostulacionDocumento extends Model
{
    public const TIPO_FOTO = 'foto';

    public const TIPO_DNI_FRENTE = 'dni_frente';

    public const TIPO_DNI_DORSO = 'dni_dorso';

    public const TIPO_BOLETA_DE_SERVICIO = 'boleta_de_servicio';

    /** Los cuatro, en el orden del CHECK de la columna. */
    public const TIPOS = [
        self::TIPO_FOTO,
        self::TIPO_DNI_FRENTE,
        self::TIPO_DNI_DORSO,
        self::TIPO_BOLETA_DE_SERVICIO,
    ];

    /** Los dos que son documentación de identidad, y no se muestran a la ligera. */
    public const TIPOS_DE_IDENTIDAD = [
        self::TIPO_DNI_FRENTE,
        self::TIPO_DNI_DORSO,
    ];

    protected $table = 'postulacion_documentos';

    /** @return BelongsTo<Postulacion, $this> */
    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function esDocumentoDeIdentidad(): bool
    {
        return in_array($this->tipo, self::TIPOS_DE_IDENTIDAD, true);
    }
}
