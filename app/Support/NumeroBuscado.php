<?php

namespace App\Support;

/**
 * El número que escribió quien busca, o null si lo escrito no puede estar en la columna.
 *
 * ⚠️ **`ctype_digit` es la mitad del chequeo, y faltaba la otra.** Que lo escrito sean
 * todos dígitos evita que `(int)` sobre un texto dé 0 y traiga al móvil 0 o al cliente 0
 * —el descuido del panel viejo, anotado en CadeteController y en UserController—, pero no
 * dice nada del tamaño. `dni`, `numero_movil` y `numero` son columnas `integer`, y
 * comparar una contra un número más grande que su máximo no devuelve «ningún resultado»:
 * hace fallar la consulta entera con
 * `SQLSTATE[22003]: value "20123456789" is out of range for type integer`.
 *
 * En una caja de búsqueda eso es un 500 que cualquiera dispara sin querer: un CUIT, un
 * teléfono con característica, un DNI con un dígito de más. Apareció el 2026-11-07
 * buscando por DNI en Postulaciones, y los diez buscadores del panel lo tenían igual.
 *
 * ⚠️ Mongo no tenía este límite —un número era un número— así que es una trampa que nace
 * con Postgres y no existía en el sistema viejo. No la cubre ningún test de los que
 * vinieron del panel viejo porque allá no podía pasar.
 *
 * El alta no necesita esto: valida `regex:/^\d{7,8}$/` antes de escribir, o sea 99.999.999
 * como máximo. Una búsqueda no valida nada, y por eso la regla vive acá.
 */
final class NumeroBuscado
{
    /** El mayor que entra en una columna `integer` de PostgreSQL. */
    public const MAXIMO = 2147483647;

    /**
     * El número, o null si lo escrito no es un número que esa columna pueda contener.
     *
     * Devolver null es lo correcto y no un atajo: significa «por número, no busques», y
     * quien llama ya sabe qué hacer con eso —no agregar la condición, o comparar contra
     * un número que no existe—. Un DNI de once dígitos no está en la tabla, así que no
     * buscarlo da el mismo resultado que buscarlo, sin el 500.
     */
    public static function deTexto(string $texto): ?int
    {
        if (! ctype_digit($texto)) {
            return null;
        }

        // `(int)` sobre una cadena más grande que PHP_INT_MAX satura en PHP_INT_MAX, así
        // que un número enorme cae en el límite de abajo y no se escapa dando negativo.
        $numero = (int) $texto;

        return $numero > self::MAXIMO ? null : $numero;
    }
}
