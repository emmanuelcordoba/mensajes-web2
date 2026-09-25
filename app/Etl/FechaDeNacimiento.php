<?php

namespace App\Etl;

use RuntimeException;

/**
 * La regla de DATA-10, en un solo lugar: la usan `cadetes` y `postulaciones`,
 * que guardan la fecha de nacimiento con el mismo formato y el mismo problema.
 *
 * Son dos comprobaciones distintas y conviene no confundirlas:
 *
 * - **Formato.** El sistema viejo guarda texto. Casi todas son `aaaa-mm-dd`,
 *   pero hay `dd/mm/aaaa` y una con el año de 6 dígitos. El ETL **no las
 *   interpreta**: en `dd/mm/aaaa` no se sabe si 05/11 es mayo o noviembre, y
 *   poner mal la fecha de nacimiento de alguien es peor que frenar.
 *
 * - **Posibilidad.** `0001-03-03` cumple el formato, entra en una columna DATE
 *   sin chistar y pasa el `required|date` de Laravel. Por eso ninguna medición
 *   anterior lo vio. ⚠️ El esquema **no** lo rechaza —no hay CHECK de rango— así
 *   que acá se frena por decisión, no porque la carga vaya a fallar: mudar la
 *   fecha de nacimiento de alguien nacido en el año 190 no tiene sentido.
 */
class FechaDeNacimiento
{
    public const FORMATO = '/^\d{4}-\d{2}-\d{2}$/';

    /** Antes de esto es un dígito perdido: `0990-…` donde iba `1990-…`. */
    public const ANIO_MINIMO = 1900;

    /** Un cadete o un postulante más joven que esto no existe. */
    public const EDAD_MINIMA = 16;

    public static function bienEscrita(mixed $valor): bool
    {
        return is_string($valor) && preg_match(self::FORMATO, trim($valor)) === 1;
    }

    /**
     * Si además de estar bien escrita puede ser de una persona de verdad.
     */
    public static function posible(string $fecha): bool
    {
        if ((int) substr($fecha, 0, 4) < self::ANIO_MINIMO) {
            return false;
        }

        return $fecha <= date('Y-m-d', strtotime('-'.self::EDAD_MINIMA.' years'));
    }

    /**
     * La fecha lista para una columna DATE, o una excepción que no delata el
     * valor: es el dato de una persona y el mensaje va a la consola.
     */
    public static function paraLaColumna(mixed $valor, string $campo): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        $fecha = trim($valor);

        if (! self::bienEscrita($fecha)) {
            throw new RuntimeException(
                "«{$campo}» tiene el valor con formato «".preg_replace('/\d/', '9', $fecha)
                .'», que no es aaaa-mm-dd. Hay que corregirlo en el sistema viejo (DATA-10).'
            );
        }

        return $fecha;
    }
}
