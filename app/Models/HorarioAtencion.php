<?php

namespace App\Models;

use Database\Factories\HorarioAtencionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * El horario en que la empresa atiende. Es **una sola fila**.
 *
 * `desde` y `hasta` son `TIME` en el esquema nuevo y texto en el viejo, donde el
 * panel no validaba el formato: podía haber cualquier cosa guardada ahí.
 *
 * ⚠️ Un horario que cruza la medianoche —de 22:00 a 02:00— no se puede evaluar
 * comparando `desde <= ahora <= hasta`. Hoy no hay ninguno así, pero el esquema no
 * lo impide y cambiar el horario es una pantalla del panel, así que
 * `estaAbierto()` lo maneja.
 *
 * @property int $id
 * @property string $desde
 * @property string $hasta
 * @property string $mensaje_horario
 * @property string $mensaje_confirmacion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['desde', 'hasta', 'mensaje_horario', 'mensaje_confirmacion'])]
class HorarioAtencion extends Model
{
    /** @use HasFactory<HorarioAtencionFactory> */
    use HasFactory;

    protected $table = 'horarios_atencion';

    /** La única fila. Null si todavía no se configuró. */
    public static function actual(): ?self
    {
        return self::query()->first();
    }

    /**
     * Si a esa hora la empresa atiende.
     *
     * Cuando `hasta` es menor que `desde` el rango cruza la medianoche, y entonces
     * la comparación se invierte: está abierto si es más tarde que `desde` **o** más
     * temprano que `hasta`.
     */
    public function estaAbierto(?Carbon $cuando = null): bool
    {
        $hora = ($cuando ?? Carbon::now())->format('H:i:s');

        $desde = self::comoHora($this->desde);
        $hasta = self::comoHora($this->hasta);

        if ($desde <= $hasta) {
            return $hora >= $desde && $hora <= $hasta;
        }

        return $hora >= $desde || $hora <= $hasta;
    }

    /**
     * `HH:MM:SS`, que es lo que devuelve un TIME de PostgreSQL y lo que hace
     * comparables dos horas como cadenas.
     *
     * Va por Carbon y no por un recorte de texto porque un valor puesto en memoria
     * antes de guardar puede venir como `8:00`, y PostgreSQL lo acepta: recortarlo
     * a ocho caracteres daría `8:00:00:`, que compara mal contra todo.
     */
    private static function comoHora(string $valor): string
    {
        return Carbon::parse($valor)->format('H:i:s');
    }
}
