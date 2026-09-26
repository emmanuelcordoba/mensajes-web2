<?php

namespace App\Etl\Tablas;

use App\Etl\FechaDeNacimiento;
use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * cadetes. Va después de users, que la referencia por `user_id` NOT NULL.
 *
 * ⚠️ Tres cosas del origen frenan la carga, y las tres las lista `problemas()`:
 * los 2 pares de `numero_movil` que chocan al castear, los DNI inválidos de
 * cadetes activos y los grupos de DNI repetido (DATA-4 y DATA-6).
 *
 * ⚠️ Las fechas son texto, no UTCDateTime. `fecha_nacimiento`, `fecha_inicio` y
 * `fecha_vencimiento` vienen como `aaaa-mm-dd`, salvo tres nacimientos de
 * cadetes dados de baja: dos en `dd/mm/aaaa` y uno con el año de 6 dígitos. El
 * ETL **no los interpreta**: en `dd/mm/aaaa` no se puede saber si `05/11` es
 * mayo o noviembre, y poner mal la fecha de nacimiento de alguien es peor que
 * frenar. Se corrigen en el sistema viejo.
 *
 * Dos renombres respecto del sistema viejo: `monto_semanal_personal` —que es un
 * booleano pese al nombre, DATA-3— pasa a `tiene_monto_semanal_personal`, y los
 * 13 sin valor entran como `false`, que es el default de la columna.
 *
 * ⚠️ Al leer datos históricos, PANEL-5: cuando un cadete se va, la operación
 * RECICLA su cuenta en lugar de dar de baja y alta, así que el historial de una
 * fila no es necesariamente de la persona que hoy figura en ella. El ETL copia
 * lo que hay; la advertencia es para quien lea después.
 */
class Cadetes extends Migrador
{
    /**
     * El formato y la regla de posibilidad viven en FechaDeNacimiento: los
     * comparte con `postulaciones`, que tiene el mismo problema. Ver DATA-10.
     */
    private const FECHA = FechaDeNacimiento::FORMATO;

    /** @var array<string, int>|null */
    private ?array $users = null;

    public function coleccion(): string
    {
        return 'cadetes';
    }

    public function tabla(): string
    {
        return 'cadetes';
    }

    public function anotaIds(): bool
    {
        return true;
    }

    public function campos(): array
    {
        return [
            'numero_movil', 'apellidos', 'nombres', 'direccion', 'telefono', 'fecha_nacimiento',
            'dni', 'observaciones', 'estado', 'tipo_vehiculo', 'ubicacion_lat', 'ubicacion_lon',
            'fcm_token', 'orden_cola', 'fecha_inicio', 'fecha_vencimiento',
            'monto_semanal', 'monto_deuda', 'monto_pagado_efectivo', 'monto_pagado_tickets',
            'cobranza_saldo', 'monto_semanal_personal', 'modalidad_cobranza', 'user_id',
            'created_at', 'updated_at', 'deleted_at',
        ];
    }

    public function fila(array $documento): ?array
    {
        $this->users ??= MapaDeIds::mapa('users');

        return [
            'numero_movil' => (int) ($documento['numero_movil'] ?? 0),
            'apellidos' => self::aplastar((string) ($documento['apellidos'] ?? '')),
            'nombres' => self::aplastar((string) ($documento['nombres'] ?? '')),
            'direccion' => self::aplastar((string) ($documento['direccion'] ?? '')),
            'telefono' => self::aplastar((string) ($documento['telefono'] ?? '')),
            'fecha_nacimiento' => self::fecha($documento['fecha_nacimiento'] ?? null, 'fecha_nacimiento'),
            'dni' => self::dni($documento['dni'] ?? null),
            'observaciones' => self::texto($documento['observaciones'] ?? null),
            'estado' => self::aplastar((string) ($documento['estado'] ?? '')),
            'tipo_vehiculo' => self::aplastar((string) ($documento['tipo_vehiculo'] ?? '')),
            'ubicacion_lat' => self::numero($documento['ubicacion_lat'] ?? null),
            'ubicacion_lon' => self::numero($documento['ubicacion_lon'] ?? null),
            'fcm_token' => self::texto($documento['fcm_token'] ?? null),
            'orden_cola' => isset($documento['orden_cola']) ? (int) $documento['orden_cola'] : null,
            'fecha_inicio' => self::fecha($documento['fecha_inicio'] ?? null, 'fecha_inicio'),
            'fecha_vencimiento' => self::fecha($documento['fecha_vencimiento'] ?? null, 'fecha_vencimiento'),
            // NUMERIC(12,2): los 8 saldos con más decimales los redondea
            // PostgreSQL, y son 0,0224 en total sobre 382 cadetes.
            'monto_semanal' => self::numero($documento['monto_semanal'] ?? null),
            'monto_deuda' => self::numero($documento['monto_deuda'] ?? null),
            'monto_pagado_efectivo' => self::numero($documento['monto_pagado_efectivo'] ?? null),
            'monto_pagado_tickets' => self::numero($documento['monto_pagado_tickets'] ?? null),
            'cobranza_saldo' => self::numero($documento['cobranza_saldo'] ?? null),
            'tiene_monto_semanal_personal' => (bool) ($documento['monto_semanal_personal'] ?? false),
            'modalidad_cobranza' => self::texto($documento['modalidad_cobranza'] ?? null) ?? 'Semanal',
            'user_id' => $this->user($documento),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
            'deleted_at' => Origen::fecha($documento['deleted_at'] ?? null),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $problemas = [];

        $moviles = [];
        $dnis = [];
        $dniInvalidos = 0;
        $fechasRaras = 0;
        $fechasImposibles = 0;

        $campos = ['numero_movil', 'dni', 'fecha_nacimiento'];

        foreach ($this->origen->documentos($this->coleccion(), [], $campos) as $documento) {
            $movil = (int) ($documento['numero_movil'] ?? 0);
            $moviles[$movil] = ($moviles[$movil] ?? 0) + 1;

            $dni = $documento['dni'] ?? null;

            if ($dni !== null && $dni !== '') {
                if (self::dniValido($dni)) {
                    $numero = (int) $dni;
                    $dnis[$numero] = ($dnis[$numero] ?? 0) + 1;
                } else {
                    $dniInvalidos++;
                }
            }

            $nacimiento = is_string($documento['fecha_nacimiento'] ?? null)
                ? trim($documento['fecha_nacimiento'])
                : '';

            if ($nacimiento !== '' && preg_match(self::FECHA, $nacimiento) !== 1) {
                $fechasRaras++;
            } elseif ($nacimiento !== '' && ! FechaDeNacimiento::posible($nacimiento)) {
                $fechasImposibles++;
            }
        }

        $movilesRepetidos = array_filter($moviles, static fn (int $n): bool => $n > 1);
        $dnisRepetidos = array_filter($dnis, static fn (int $n): bool => $n > 1);

        if ($movilesRepetidos !== []) {
            $problemas[] = sprintf(
                '%d números de móvil quedan repetidos al castearlos a INTEGER, entre %d cadetes. '
                .'cadetes.numero_movil es UNIQUE: se resuelven con la pantalla «Cadetes con número '
                .'de móvil repetido» (DATA-6).',
                count($movilesRepetidos),
                array_sum($movilesRepetidos),
            );
        }

        if ($dniInvalidos > 0) {
            $problemas[] = sprintf(
                '%d DNI no tienen 7 u 8 dígitos. La columna es INTEGER y el valor no se inventa: '
                .'se corrigen con la pantalla «Cadetes con DNI inválido» (DATA-6).',
                $dniInvalidos,
            );
        }

        if ($dnisRepetidos !== []) {
            $problemas[] = sprintf(
                '%d DNI quedan repetidos al castearlos, entre %d cadetes. cadetes.dni es UNIQUE, y '
                .'la unicidad cuenta también a los dados de baja: se resuelven con la pantalla '
                .'«Cadetes con DNI repetido» (DATA-4).',
                count($dnisRepetidos),
                array_sum($dnisRepetidos),
            );
        }

        if ($fechasRaras > 0) {
            $problemas[] = sprintf(
                '%d fechas de nacimiento no están en aaaa-mm-dd. El ETL no las interpreta: en '
                .'dd/mm/aaaa no se sabe si 05/11 es mayo o noviembre, y poner mal la fecha de '
                .'nacimiento de alguien es peor que frenar.',
                $fechasRaras,
            );
        }

        if ($fechasImposibles > 0) {
            $problemas[] = sprintf(
                '%d fechas de nacimiento son imposibles: anteriores a 1900 o de alguien que hoy '
                .'tendría menos de %d años. ⚠️ El esquema NO las rechaza —`fecha_nacimiento` es '
                .'DATE NOT NULL, sin CHECK de rango—, así que esto frena por decisión, no porque '
                .'la carga vaya a fallar: son datos basura que no tiene sentido mudar. Ver DATA-10.',
                $fechasImposibles,
                FechaDeNacimiento::EDAD_MINIMA,
            );
        }

        return $problemas;
    }

    /**
     * Una fecha del sistema viejo, que son strings y no UTCDateTime.
     */
    private static function fecha(mixed $valor, string $campo): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $fecha = trim($valor);

        if ($fecha === '') {
            return null;
        }

        if (preg_match(self::FECHA, $fecha) !== 1) {
            throw new RuntimeException(
                "«{$campo}» tiene el valor con formato «".preg_replace('/\d/', '9', $fecha)
                .'», que no es aaaa-mm-dd. Hay que corregirlo en el sistema viejo.'
            );
        }

        return $fecha;
    }

    private static function dniValido(mixed $valor): bool
    {
        $dni = ltrim(trim((string) $valor), '0');

        return preg_match('/^\d{7,8}$/', $dni) === 1;
    }

    private static function dni(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! self::dniValido($valor)) {
            throw new RuntimeException(
                'Un cadete tiene un DNI que no son 7 u 8 dígitos. Hay que corregirlo en el '
                .'sistema viejo, con la pantalla «Cadetes con DNI inválido».'
            );
        }

        return (int) ltrim(trim((string) $valor), '0');
    }

    /**
     * @param  array<string, mixed>  $documento
     */
    private function user(array $documento): int
    {
        $legacy = Origen::id($documento['user_id'] ?? null);

        return ($legacy === null ? null : ($this->users[$legacy] ?? null)) ?? throw new RuntimeException(
            'Un cadete apunta al usuario «'.($legacy ?? 'ninguno').'», que no está en '
            .'migracion_ids. cadetes.user_id es NOT NULL. ¿Se cargó users antes?'
        );
    }

    private static function numero(mixed $valor): ?float
    {
        return is_int($valor) || is_float($valor) ? (float) $valor : null;
    }

    private static function aplastar(string $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $valor));
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = self::aplastar($valor);

        return $texto === '' ? null : $texto;
    }
}
