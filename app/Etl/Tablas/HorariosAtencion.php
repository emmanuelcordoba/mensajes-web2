<?php

namespace App\Etl\Tablas;

use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * horarios_atencion. Una sola fila, y la colección se llama
 * `horario_de_atencion` en el sistema viejo.
 *
 * ⚠️ `desde` y `hasta` son texto en MongoDB —hoy `08:00` y `21:00`— y acá son
 * TIME. El panel sólo exige que vengan (`StoreHorarioDeAtencion`), sin formato,
 * así que un texto cualquiera rompería el casteo: el ETL comprueba HH:MM y
 * frena, y el sistema nuevo tiene que validar `date_format:H:i`.
 */
class HorariosAtencion extends Migrador
{
    private const HORA = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function coleccion(): string
    {
        return 'horario_de_atencion';
    }

    public function tabla(): string
    {
        return 'horarios_atencion';
    }

    public function campos(): array
    {
        return ['desde', 'hasta', 'mensaje_horario', 'mensaje_confirmacion', 'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        return [
            'desde' => self::hora($documento['desde'] ?? null, 'desde'),
            'hasta' => self::hora($documento['hasta'] ?? null, 'hasta'),
            'mensaje_horario' => trim((string) ($documento['mensaje_horario'] ?? '')),
            'mensaje_confirmacion' => trim((string) ($documento['mensaje_confirmacion'] ?? '')),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $malas = 0;

        foreach ($this->origen->documentos($this->coleccion(), [], ['desde', 'hasta']) as $documento) {
            foreach (['desde', 'hasta'] as $campo) {
                $hora = is_string($documento[$campo] ?? null) ? trim($documento[$campo]) : '';

                if (preg_match(self::HORA, $hora) !== 1) {
                    $malas++;
                }
            }
        }

        return $malas === 0 ? [] : [sprintf(
            '%d horas de atención no están en HH:MM. La columna es TIME y el casteo falla.',
            $malas,
        )];
    }

    private static function hora(mixed $valor, string $campo): string
    {
        $hora = is_string($valor) ? trim($valor) : '';

        if (preg_match(self::HORA, $hora) !== 1) {
            throw new RuntimeException(
                "El horario de atención tiene «{$campo}» fuera de HH:MM, y la columna es TIME."
            );
        }

        return $hora;
    }
}
