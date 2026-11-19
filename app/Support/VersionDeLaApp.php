<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Qué versión de la app mandó este pedido.
 *
 * ⚠️ **Existe porque faltaba la respuesta a «¿y qué versión corre ese teléfono?».** En
 * noviembre un solo aparato mandó 3.179 ubicaciones de más en 19 horas, y de las 23
 * cabeceras que llegaron ninguna decía la versión: no se podía distinguir un teléfono con
 * un error ya arreglado de uno con un error nuevo. Desde la 2.3.14 las apps mandan
 * `X-App-Version` en cada pedido a la API.
 *
 * ⚠️ **Es dato de afuera, así que se limita y se limpia.** Lo escribe el cliente, no
 * nosotros: un teléfono con la app modificada —o cualquiera con `curl`— puede poner lo que
 * quiera. Va a parar a un registro que después se lee, así que se recorta a 20 caracteres y
 * se queda sólo con lo que puede ser una versión. Null cuando no viene, que es lo que dicen
 * las versiones viejas, y eso ya es una respuesta.
 */
class VersionDeLaApp
{
    public const CABECERA = 'X-App-Version';

    private const LARGO_MAXIMO = 20;

    public static function de(Request $peticion): ?string
    {
        $version = $peticion->header(self::CABECERA);

        if (! is_string($version) || $version === '') {
            return null;
        }

        // Números, puntos, guiones y letras: `2.3.14`, `2.4.0-beta`. Nada más.
        $limpia = preg_replace('/[^0-9A-Za-z.\-]/', '', $version) ?? '';

        return $limpia === '' ? null : mb_substr($limpia, 0, self::LARGO_MAXIMO);
    }
}
