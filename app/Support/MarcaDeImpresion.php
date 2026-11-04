<?php

namespace App\Support;

use App\Models\Pedido;
use Illuminate\Support\Facades\Cache;

/**
 * La marca de «imprimir el cupón cuando se le asigne el cadete».
 *
 * El cupón es el papel que se le entrega al cadete, así que recién tiene sentido imprimirlo
 * cuando hay alguien a quien dárselo. La oficina lo decide antes: abre el pedido, ve que es
 * uno de los que llevan comprobante, y lo deja marcado aunque todavía no tenga a quién
 * mandarle. Esto es esa marca.
 *
 * ⚠️ **No es un campo del pedido y no va a la base.** Es una nota sobre algo que todavía no
 * pasó y que deja de valer en cuanto pasa: guardarla sería una columna que el día de mañana
 * nadie sabe leer, y además una escritura más por cada tilde. Vive en la caché.
 *
 * ⚠️ **Es compartida, no del navegador que la puso.** En la oficina la lista la miran varias
 * personas a la vez y el que marca no siempre es el que termina asignando. En
 * `localStorage` la marca se habría perdido justo en ese caso, que es el único para el que
 * se pidió guardarla.
 */
final class MarcaDeImpresion
{
    /**
     * Cuánto vive la marca.
     *
     * Un pedido que no encontró cadete en todo el día ya no lo va a encontrar: al día
     * siguiente la oficina lo vuelve a mirar y decide de nuevo.
     */
    private const HORAS = 24;

    public static function puesta(Pedido $pedido): bool
    {
        return Cache::get(self::clave($pedido)) === true;
    }

    public static function poner(Pedido $pedido, bool $imprimir): void
    {
        if (! $imprimir) {
            self::sacar($pedido);

            return;
        }

        Cache::put(self::clave($pedido), true, now()->addHours(self::HORAS));
    }

    /** Se saca al destildarla, y también cuando ya se imprimió: cumplió. */
    public static function sacar(Pedido $pedido): void
    {
        Cache::forget(self::clave($pedido));
    }

    private static function clave(Pedido $pedido): string
    {
        return 'pedido:'.$pedido->id.':imprimir';
    }
}
