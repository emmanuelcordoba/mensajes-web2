<?php

namespace App\Etl\Tablas;

use App\Etl\FechaDeNacimiento;
use App\Etl\MapaDeIds;
use App\Etl\Migrador;
use App\Etl\Origen;
use RuntimeException;

/**
 * postulaciones. 725 documentos, en una colección que se llama
 * `postulaciones_cadetes`. Va después de cadetes, que referencia.
 *
 * ⚠️ **La columna `password` no existe acá, y es deliberado.** Las postulaciones
 * guardaban `123456` en texto plano; el alta dejó de guardar contraseña con el
 * arreglo de SEC-1 —no es una credencial hasta que se contrata, y contratar
 * genera una nueva— y las que quedaban se borraron en producción el 2026-09-16.
 * No se migra.
 *
 * ⚠️ **Las cuatro imágenes tampoco van acá**: `dni_frente`, `dni_dorso`,
 * `boleta_de_servicio` y `foto` se van a `postulacion_documentos`, que guarda la
 * ruta de un archivo (PERF-2). Son las más sensibles de las tres tablas de
 * imágenes: dos de las cuatro son el DNI de la persona.
 *
 * `cadete_id` es lo que distingue una postulación pendiente de una ya
 * convertida en cadete: lo tienen 583 de las 725.
 *
 * ⚠️ La unicidad del DNI **contra cadetes** no es una restricción y no puede
 * serlo: está en otra tabla. Hay postulaciones pendientes cuyo DNI ya tiene un
 * cadete, y la contratación las frena desde PANEL-15.
 */
class Postulaciones extends Migrador
{
    /** @var array<string, int>|null */
    private ?array $cadetes = null;

    public function coleccion(): string
    {
        return 'postulaciones_cadetes';
    }

    public function tabla(): string
    {
        return 'postulaciones';
    }

    public function anotaIds(): bool
    {
        return true;
    }

    public function campos(): array
    {
        return [
            'nombres', 'apellidos', 'dni', 'fecha_nacimiento', 'direccion', 'telefono', 'email',
            'tipo_vehiculo', 'pregunta_tiene_celular', 'pregunta_tiene_datos',
            'pregunta_experiencia_app', 'pregunta_equipo_en_condiciones', 'recomendado_por',
            'cadete_id', 'created_at', 'updated_at', 'deleted_at',
        ];
    }

    public function fila(array $documento): ?array
    {
        return [
            'nombres' => self::aplastar((string) ($documento['nombres'] ?? '')),
            'apellidos' => self::aplastar((string) ($documento['apellidos'] ?? '')),
            'dni' => self::dni($documento['dni'] ?? null),
            'fecha_nacimiento' => FechaDeNacimiento::paraLaColumna(
                $documento['fecha_nacimiento'] ?? null,
                'fecha_nacimiento',
            ),
            'direccion' => self::aplastar((string) ($documento['direccion'] ?? '')),
            'telefono' => self::aplastar((string) ($documento['telefono'] ?? '')),
            // El CHECK exige el email normalizado, igual que en users.
            'email' => mb_strtolower(trim((string) ($documento['email'] ?? ''))),
            'tipo_vehiculo' => self::aplastar((string) ($documento['tipo_vehiculo'] ?? '')),
            'pregunta_tiene_celular' => self::texto($documento['pregunta_tiene_celular'] ?? null),
            'pregunta_tiene_datos' => self::texto($documento['pregunta_tiene_datos'] ?? null),
            'pregunta_experiencia_app' => self::texto($documento['pregunta_experiencia_app'] ?? null),
            'pregunta_equipo_en_condiciones' => self::texto($documento['pregunta_equipo_en_condiciones'] ?? null),
            'recomendado_por' => self::texto($documento['recomendado_por'] ?? null),
            'cadete_id' => $this->cadete($documento),
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
        $dnis = [];
        $emails = [];
        $dniInvalidos = 0;
        $fechasRaras = 0;
        $fechasImposibles = 0;

        $campos = ['dni', 'email', 'fecha_nacimiento'];

        foreach ($this->origen->documentos($this->coleccion(), [], $campos) as $documento) {
            $dni = ltrim(trim((string) ($documento['dni'] ?? '')), '0');

            if (preg_match('/^\d{7,8}$/', $dni) === 1) {
                $dnis[(int) $dni] = ($dnis[(int) $dni] ?? 0) + 1;
            } else {
                $dniInvalidos++;
            }

            $email = mb_strtolower(trim((string) ($documento['email'] ?? '')));
            $emails[$email] = ($emails[$email] ?? 0) + 1;

            $fecha = $documento['fecha_nacimiento'] ?? null;

            if (! FechaDeNacimiento::bienEscrita($fecha)) {
                $fechasRaras++;
            } elseif (! FechaDeNacimiento::posible(trim((string) $fecha))) {
                $fechasImposibles++;
            }
        }

        $problemas = [];
        $dnisRepetidos = array_filter($dnis, static fn (int $n): bool => $n > 1);
        $emailsRepetidos = array_filter($emails, static fn (int $n): bool => $n > 1);

        if ($dniInvalidos > 0) {
            $problemas[] = sprintf(
                '%d postulaciones tienen un DNI que no son 7 u 8 dígitos, y la columna es INTEGER '
                .'NOT NULL.',
                $dniInvalidos,
            );
        }

        if ($dnisRepetidos !== []) {
            $problemas[] = sprintf(
                '%d DNI de postulación están repetidos, entre %d postulaciones. La columna es '
                .'UNIQUE, y es lo que `NumeroNoRepetido` ya exige al postularse.',
                count($dnisRepetidos),
                array_sum($dnisRepetidos),
            );
        }

        if ($emailsRepetidos !== []) {
            $problemas[] = sprintf(
                '%d emails de postulación quedan repetidos al normalizarlos, entre %d '
                .'postulaciones. La columna es UNIQUE.',
                count($emailsRepetidos),
                array_sum($emailsRepetidos),
            );
        }

        if ($fechasRaras > 0) {
            $problemas[] = sprintf(
                '%d fechas de nacimiento de postulación no están en aaaa-mm-dd. El ETL no las '
                .'interpreta (DATA-10).',
                $fechasRaras,
            );
        }

        if ($fechasImposibles > 0) {
            $problemas[] = sprintf(
                '%d fechas de nacimiento de postulación son imposibles: anteriores a %d o de '
                .'alguien que hoy tendría menos de %d años. ⚠️ El esquema NO las rechaza; esto '
                .'frena por decisión. Ver DATA-10.',
                $fechasImposibles,
                FechaDeNacimiento::ANIO_MINIMO,
                FechaDeNacimiento::EDAD_MINIMA,
            );
        }

        return $problemas;
    }

    private static function dni(mixed $valor): int
    {
        $dni = ltrim(trim((string) ($valor ?? '')), '0');

        if (preg_match('/^\d{7,8}$/', $dni) !== 1) {
            throw new RuntimeException(
                'Una postulación tiene un DNI que no son 7 u 8 dígitos, y la columna es INTEGER '
                .'NOT NULL.'
            );
        }

        return (int) $dni;
    }

    /**
     * Poblado al contratar: es lo que distingue una postulación pendiente de
     * una ya convertida en cadete.
     *
     * @param  array<string, mixed>  $documento
     */
    private function cadete(array $documento): ?int
    {
        $valor = $documento['cadete_id'] ?? null;

        if ($valor === null || $valor === '') {
            return null;
        }

        $legacy = Origen::id($valor);
        $this->cadetes ??= MapaDeIds::mapa('cadetes');

        return ($legacy === null ? null : ($this->cadetes[$legacy] ?? null))
            ?? throw new RuntimeException(
                'Una postulación contratada apunta al cadete «'.($legacy ?? 'ilegible').'», que no '
                .'está en migracion_ids.'
            );
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
