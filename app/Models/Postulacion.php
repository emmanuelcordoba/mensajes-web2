<?php

namespace App\Models;

use Database\Factories\PostulacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Alguien que se postula para ser cadete. Son 725, de las cuales 583 ya fueron
 * contratadas.
 *
 * `cadete_id` es lo que distingue una pendiente de una ya convertida en cadete, y
 * tiene un índice parcial para buscar justamente las pendientes.
 *
 * ## Lo que el esquema exige y el sistema viejo no
 *
 * `dni` y `email` son UNIQUE, `email` tiene que estar en minúsculas y sin espacios
 * —35 de las 725 tenían mayúsculas—, y `fecha_nacimiento` es NOT NULL.
 *
 * ⚠️ **`password` no está acá.** El modelo viejo lo tenía en `$fillable` y la tabla
 * guardaba una contraseña en texto plano por postulación; se limpiaron las 705 el
 * 2026-09-16. El esquema nuevo no tiene la columna: la cuenta se crea al contratar,
 * y es un `User` con su hash.
 *
 * ⚠️ Las imágenes tampoco: `dni_frente`, `dni_dorso`, `boleta_de_servicio` y `foto`
 * eran columnas con base64 o una ruta, y ahora son filas de
 * `postulacion_documentos` (PERF-2). Ver la relación `documentos()`.
 *
 * ⚠️ `fecha_nacimiento` no tiene CHECK de rango: hay 21 postulaciones con fechas
 * imposibles, todas con forma de fecha de carga (DATA-10). Se corrigen antes de
 * migrar.
 *
 * @property int $id
 * @property string $nombres
 * @property string $apellidos
 * @property int $dni
 * @property Carbon $fecha_nacimiento
 * @property string $direccion
 * @property string $telefono
 * @property string $email
 * @property string $tipo_vehiculo
 * @property string|null $pregunta_tiene_celular
 * @property string|null $pregunta_tiene_datos
 * @property string|null $pregunta_experiencia_app
 * @property string|null $pregunta_equipo_en_condiciones
 * @property string|null $recomendado_por
 * @property int|null $cadete_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $nombre_completo
 * @property-read Cadete|null $cadete
 * @property-read Collection<int, PostulacionDocumento> $documentos
 */
#[Fillable([
    'nombres',
    'apellidos',
    'dni',
    'fecha_nacimiento',
    'direccion',
    'telefono',
    'email',
    'tipo_vehiculo',
    'pregunta_tiene_celular',
    'pregunta_tiene_datos',
    'pregunta_experiencia_app',
    'pregunta_equipo_en_condiciones',
    'recomendado_por',
    'cadete_id',
])]
class Postulacion extends Model
{
    /** @use HasFactory<PostulacionFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'postulaciones';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'dni' => 'integer',
        ];
    }

    /**
     * El email se guarda en minúsculas y sin espacios, que es lo que pide el CHECK
     * de la columna. Igual que en `User`.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $valor): string => mb_strtolower(trim($valor)),
        );
    }

    /**
     * El cadete en que se convirtió, si se contrató.
     *
     * @return BelongsTo<Cadete, $this>
     */
    public function cadete(): BelongsTo
    {
        return $this->belongsTo(Cadete::class);
    }

    /** @return HasMany<PostulacionDocumento, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(PostulacionDocumento::class, 'postulacion_id');
    }

    /**
     * Nombres primero, al revés que en `Cadete`: acá es una persona que se está
     * presentando, no un móvil en una planilla.
     *
     * @return Attribute<string, never>
     */
    protected function nombreCompleto(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("{$this->nombres} {$this->apellidos}"),
        );
    }

    public function fueContratada(): bool
    {
        return $this->cadete_id !== null;
    }

    /**
     * Las que todavía esperan una decisión. Hay un índice parcial para esto.
     *
     * @param  Builder<Postulacion>  $query
     * @return Builder<Postulacion>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereNull('cadete_id');
    }
}
