<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Pedido;

/**
 * Cuánto sale un viaje: la tarifa, tal como la calcula el sistema viejo.
 *
 * Es el `Pedido::cotizar()` de allá, palabra por palabra en su aritmética, y por eso vale
 * la pena decir qué cobra: un mínimo, después tantos kilómetros por un precio que cambia
 * a partir del quinto, un porcentaje encima si el cadete vuelve al origen, y un adicional
 * por cada mil pesos de garantía.
 *
 * Lo usan el alta de pedidos del panel —que lo pide por su propia ruta cuando las dos
 * direcciones salieron de Google y hay una distancia— y, en el sistema viejo, también la
 * app del cliente y la API. Acá es un servicio y no un método del modelo porque es una
 * regla de precios que lee configuraciones, no algo que un pedido sepa de sí mismo.
 *
 * ⚠️ **La aritmética se replica exacta, con sus bordes raros incluidos**, porque es plata
 * que la oficina ya conoce de memoria y un peso de diferencia es un reclamo:
 *
 * - Los kilómetros se **truncan**: 4.999 metros son 4 km, así que 4.999 m todavía paga la
 *   tarifa corta y 5.000 m paga la larga.
 * - El mínimo no es un piso que se suma: reemplaza al costo por distancia cuando éste no
 *   llega. Después el retorno se calcula sobre el número que quedó, mínimo incluido.
 * - La garantía suma sólo si pasa de 1.000, y por cada mil **enteros**: 1.000 no suma nada,
 *   1.999 suma uno solo.
 *
 * ⚠️ **Las cuentas van con `bc` y no con float.** El sistema viejo las hace en coma
 * flotante y escribe el resultado en una columna `NUMERIC(12,2)`; acá se hacen en decimal
 * y se redondea al final a los dos decimales que la columna guarda. Para los valores de
 * producción —costos enteros y porcentajes enteros— las dos cuentas dan el mismo número;
 * la diferencia aparecería recién con un costo de muchos decimales, y entonces el bueno es
 * éste. Ver el `casts()` de `Pedido` y `Configuracion::valor()`.
 *
 * ⚠️ **Frena si falta una configuración**, por `Configuracion::numeroCrudo()`. En el
 * sistema viejo una clave ausente daba «Attempt to read property on null» en medio de la
 * cuenta, sin decir cuál faltaba, y el pedido se podía llegar a guardar con precio cero.
 */
class Cotizador
{
    /** Las seis claves que necesita la tarifa, para poder decir cuál falta. */
    public const CLAVES = [
        'COSTO_MINIMO',
        'COSTO_POR_KM',
        'COSTO_POR_KM_5KM',
        'COSTO_RETORNO',
        'COSTO_RETORNO_5KM',
        'COSTO_GARANTIA',
    ];

    /** Desde cuántos kilómetros rige la tarifa larga. */
    public const KILOMETROS_DE_TARIFA_LARGA = 5;

    /** Desde cuánta garantía se empieza a cobrar el adicional, sin incluirla. */
    public const GARANTIA_SIN_CARGO = '1000';

    /** Cada cuántos pesos de garantía se cobra el adicional una vez. */
    public const PASO_DE_GARANTIA = '1000';

    /**
     * La tarifa de un viaje de tantos metros.
     *
     * @param  int  $metros  Lo que contesta Google Directions: metros, no kilómetros.
     * @param  string|null  $garantia  Lo que el cadete lleva en mano, como lo guarda la columna.
     * @return numeric-string
     */
    public function cotizar(int $metros, bool $retornoOrigen = false, ?string $garantia = null): string
    {
        // ⚠️ Truncado y no redondeado, como el `floor()` viejo. Un viaje de 0 a 999 metros
        // no paga distancia y se queda en el mínimo.
        $kilometros = max(0, intdiv($metros, 1000));
        $tarifaCorta = $kilometros < self::KILOMETROS_DE_TARIFA_LARGA;

        $porDistancia = bcmul(
            (string) $kilometros,
            Configuracion::numeroCrudo($tarifaCorta ? 'COSTO_POR_KM' : 'COSTO_POR_KM_5KM'),
            4
        );

        $minimo = Configuracion::numeroCrudo('COSTO_MINIMO');

        // El mínimo REEMPLAZA al costo por distancia; no se suman.
        $total = bccomp($porDistancia, $minimo, 4) < 0 ? $minimo : $porDistancia;

        if ($retornoOrigen) {
            $porcentaje = Configuracion::numeroCrudo(
                $tarifaCorta ? 'COSTO_RETORNO' : 'COSTO_RETORNO_5KM'
            );

            // Sobre el total que quedó, mínimo incluido: es lo que hace el sistema viejo.
            $total = bcadd($total, bcmul($total, bcdiv($porcentaje, '100', 8), 4), 4);
        }

        $total = bcadd($total, $this->porLaGarantia($garantia), 4);

        return self::conDosDecimales($total);
    }

    /**
     * El total, redondeado a lo que guarda la columna.
     *
     * ⚠️ `bcmath` TRUNCA, no redondea: sumar medio centavo antes de cortar es cómo se
     * redondea al alza sin pasar por un float. Los precios no son negativos —todos los
     * costos y los kilómetros son positivos—, así que no hace falta el caso simétrico.
     *
     * @param  numeric-string  $valor
     * @return numeric-string
     */
    protected static function conDosDecimales(string $valor): string
    {
        return bcadd($valor, '0.005', 2);
    }

    /**
     * El adicional por llevar plata en mano.
     *
     * @return numeric-string
     */
    protected function porLaGarantia(?string $garantia): string
    {
        if ($garantia === null || ! is_numeric($garantia)) {
            return '0.00';
        }

        $monto = Pedido::comoMonto($garantia);

        // ⚠️ Estrictamente mayor, como el `> 1000` viejo: una garantía de exactamente mil
        // no suma nada.
        if (bccomp($monto, self::GARANTIA_SIN_CARGO, 2) <= 0) {
            return '0.00';
        }

        // Miles enteros: 1.999 cuenta una vez, no una y pico.
        $miles = bcdiv($monto, self::PASO_DE_GARANTIA, 0);

        return bcmul($miles, Configuracion::numeroCrudo('COSTO_GARANTIA'), 4);
    }
}
