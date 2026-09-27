<?php

namespace App\Models;

use Database\Factories\MontoSemanalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cuánto paga por semana un cadete, según el vehículo. Son **dos filas**, una por
 * vehículo, y `tipo_vehiculo` es UNIQUE para que no pueda haber dos del mismo.
 *
 * Lo usan los cadetes de modalidad semanal que no tienen un monto propio: los que sí
 * lo tienen llevan `tiene_monto_semanal_personal` en true y su propio
 * `monto_semanal`.
 *
 * ⚠️ `monto` no se castea: es `NUMERIC(12,2)` y llega como string. Es plata.
 *
 * @property int $id
 * @property string $tipo_vehiculo
 * @property string $monto
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tipo_vehiculo', 'monto'])]
class MontoSemanal extends Model
{
    /** @use HasFactory<MontoSemanalFactory> */
    use HasFactory;

    protected $table = 'montos_semanales';

    /**
     * El monto que le corresponde a un vehículo, o null si no está configurado.
     *
     * @return numeric-string|null
     */
    public static function para(string $tipoVehiculo): ?string
    {
        $monto = self::query()->where('tipo_vehiculo', $tipoVehiculo)->value('monto');

        return is_numeric($monto) ? (string) $monto : null;
    }
}
