<?php

namespace App\Support;

/**
 * La contraseña de un cadete, que es un PIN numérico.
 *
 * ⚠️ ES NUMÉRICO PORQUE EL MOSTRADOR SE MANEJA CON UN TECLADO NUMÉRICO. La pantalla de la
 * cola vive en un monitor aparte, al lado del mostrador, y ahí el cadete escribe su móvil
 * y su contraseña para ponerse en la cola: con un teclado numérico no hay forma de
 * escribir una letra. Es la restricción de la que sale todo lo demás de este archivo.
 *
 * ⚠️ Y es más débil que la de cualquier otra cuenta del sistema, a sabiendas. Un PIN de
 * tres dígitos son mil combinaciones; el `throttle` de la ruta del mostrador —veinte por
 * minuto— lo convierte en unos cincuenta minutos de intentos. Lo que ese PIN abre es la
 * app del cadete y su lugar en la cola, no el panel: las cuentas de la oficina siguen
 * pidiendo `Password::default()`, que es lo que corresponde para algo que se escribe con
 * un teclado completo.
 *
 * El rango y el largo del generado son los del sistema viejo, que es donde esta decisión
 * ya estaba tomada: `numeric|digits_between:3,8` en el alta, y `random_int(100000,
 * 999999)` al contratar una postulación.
 *
 * Vive en un solo lugar porque lo leen tres: el formulario del panel, el de /admin y la
 * contratación de una postulación.
 */
class PinDeCadete
{
    public const MINIMO = 3;

    public const MAXIMO = 8;

    /** Cuántos dígitos tiene el que genera el sistema al contratar. */
    public const DIGITOS_GENERADOS = 6;

    /**
     * Las reglas de validación.
     *
     * `digits_between` ya exige que sean todos dígitos, así que no hace falta `numeric`
     * además —que encima aceptaría `1e3` y `+5`—.
     *
     * @return list<string>
     */
    public static function reglas(): array
    {
        return ['digits_between:'.self::MINIMO.','.self::MAXIMO];
    }

    /** El mensaje, que dice qué se acepta y no que el formato es inválido. */
    public static function mensaje(): string
    {
        return 'La contraseña del cadete es un PIN de '.self::MINIMO.' a '.self::MAXIMO
            .' dígitos: la escribe con el teclado numérico del mostrador.';
    }

    /**
     * Uno nuevo, para una contratación.
     *
     * ⚠️ `random_int` y no `rand`: es el generador criptográfico. El sistema viejo ya
     * usaba éste.
     */
    public static function generar(): string
    {
        $desde = 10 ** (self::DIGITOS_GENERADOS - 1);

        return (string) random_int($desde, $desde * 10 - 1);
    }
}
