<?php

namespace App\Etl\Tablas;

use App\Etl\Migrador;
use App\Etl\Origen;

/**
 * configuraciones. Los 8 parámetros del negocio. Sin claves foráneas.
 *
 * `valor` queda como TEXT a propósito aunque en MongoDB convivan string y
 * double: es una tabla clave-valor y `tipo` dice cómo interpretarlo.
 * TIEMPO_DE_DEMORA es texto libre.
 *
 * ⚠️ El CHECK exige que las de tipo `numero` sean un número no negativo sin
 * notación científica. Hasta PANEL-16 el panel aceptaba texto y `floatval()` lo
 * convertía en 0 al cotizar, así que la restricción es nueva y `problemas()` la
 * comprueba antes de cargar.
 */
class Configuraciones extends Migrador
{
    /** Lo que exige configuraciones_valor_numerico_check. */
    private const NUMERO = '/^[0-9]+(\.[0-9]+)?$/';

    public function coleccion(): string
    {
        return 'configuraciones';
    }

    public function tabla(): string
    {
        return 'configuraciones';
    }

    public function campos(): array
    {
        return ['nombre', 'titulo', 'descripcion', 'tipo', 'valor', 'created_at', 'updated_at'];
    }

    public function fila(array $documento): ?array
    {
        return [
            'nombre' => trim((string) ($documento['nombre'] ?? '')),
            'titulo' => trim((string) ($documento['titulo'] ?? '')),
            'descripcion' => self::texto($documento['descripcion'] ?? null),
            'tipo' => trim((string) ($documento['tipo'] ?? '')),
            'valor' => self::valor($documento['valor'] ?? null),
            'created_at' => Origen::fecha($documento['created_at'] ?? null),
            'updated_at' => Origen::fecha($documento['updated_at'] ?? null),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $nombres = [];
        $malos = 0;

        foreach ($this->origen->documentos($this->coleccion(), [], ['nombre', 'tipo', 'valor']) as $documento) {
            $nombre = trim((string) ($documento['nombre'] ?? ''));
            $nombres[$nombre] = ($nombres[$nombre] ?? 0) + 1;

            if (($documento['tipo'] ?? '') !== 'numero') {
                continue;
            }

            if (preg_match(self::NUMERO, (string) self::valor($documento['valor'] ?? null)) !== 1) {
                $malos++;
            }
        }

        $problemas = [];
        $repetidos = array_keys(array_filter($nombres, static fn (int $n): bool => $n > 1));

        if ($repetidos !== []) {
            $problemas[] = sprintf(
                'configuraciones.nombre es UNIQUE y están repetidos: %s. El código las busca por '
                .'nombre una por una, así que un duplicado rompe en silencio.',
                implode(', ', $repetidos),
            );
        }

        if ($malos > 0) {
            $problemas[] = sprintf(
                '%d configuraciones de tipo `numero` no tienen un número no negativo. El CHECK las '
                .'rechaza, y al cotizar valían 0 (PANEL-16).',
                $malos,
            );
        }

        return $problemas;
    }

    /**
     * Un double de MongoDB escrito como lo espera el CHECK: 250.0 es «250», no
     * «250.0», y ninguno puede salir en notación científica.
     */
    private static function valor(mixed $valor): ?string
    {
        if (is_int($valor)) {
            return (string) $valor;
        }

        if (is_float($valor)) {
            return rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.');
        }

        return self::texto($valor);
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }
}
