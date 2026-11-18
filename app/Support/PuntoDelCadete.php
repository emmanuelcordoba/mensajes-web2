<?php

namespace App\Support;

use App\Models\Cadete;

/**
 * El cadete como lo dibuja el mapa, con la antigüedad de su posición.
 *
 * ⚠️ **La frescura es el dato que faltaba.** La tabla guardaba dónde está el cadete y no
 * cuándo lo dijo, así que el mapa pintaba igual al que está parado en una esquina hace tres
 * minutos y al que dejó de mandar hace 57 días. Desde la oficina se veían idénticos, y por
 * eso «no llega la ubicación» y «el cadete está quieto» eran la misma pantalla.
 *
 * ⚠️ **El corte vive acá y no en el frontend**: lo dibujan dos pantallas —el Mapa del menú
 * y el detalle de un cadete de la cola, en Inicio— y las dos tienen que estar de acuerdo.
 * Antes cada controlador armaba el arreglo por su cuenta, con los mismos cinco campos
 * escritos dos veces.
 */
class PuntoDelCadete
{
    /**
     * A partir de cuántos minutos sin novedades la posición se considera vieja.
     *
     * ⚠️ Diez es holgado a propósito. La app manda cuando el cadete se mueve 50 metros
     * —`distanceFilter` en `backgroundgeolocation.service.ts`— y **no tiene latido**, así
     * que un cadete esperando en una esquina no manda nada sin estar roto. El día que la
     * app mande cada tantos minutos aunque no se mueva, esto puede bajar.
     */
    public const MINUTOS_PARA_QUEDAR_VIEJA = 10;

    /**
     * @return array<string, mixed>
     */
    public static function de(Cadete $cadete): array
    {
        $minutos = self::minutosDesdeLaUltima($cadete);

        return [
            'movil' => $cadete->numero_movil,
            // Son coordenadas, no plata: acá el float es el tipo que corresponde.
            'lat' => $cadete->ubicacion_lat,
            'lon' => $cadete->ubicacion_lon,
            'vehiculo' => $cadete->tipo_vehiculo,
            'con_pedido' => $cadete->estado === Cadete::ESTADO_CON_PEDIDO_APP,
            'hace_minutos' => $minutos,
            // ⚠️ **Sin fecha también es vieja.** Son las posiciones que ya estaban
            // guardadas cuando se agregó la columna: de ésas no sabemos el cuándo, y
            // darlas por frescas es exactamente el error que esto vino a arreglar.
            'vieja' => $minutos === null || $minutos >= self::MINUTOS_PARA_QUEDAR_VIEJA,
        ];
    }

    /** Cuántos minutos enteros pasaron desde la última posición, o null si no hay fecha. */
    public static function minutosDesdeLaUltima(Cadete $cadete): ?int
    {
        if ($cadete->ubicacion_actualizada_en === null) {
            return null;
        }

        return (int) $cadete->ubicacion_actualizada_en->diffInMinutes(now(), absolute: true);
    }
}
