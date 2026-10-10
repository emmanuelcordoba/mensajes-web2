<?php

namespace App\Http\Requests\Api\ClienteApp\V2;

use App\Http\Requests\Api\ClienteApp\V1\PeticionDeLaApp;

/**
 * Lo que hace falta para cotizar: la distancia, si vuelve al origen y cuánta plata lleva.
 *
 * ⚠️ **La distancia viene en METROS**, que es lo que la app calcula con Google Maps y lo que
 * `Cotizador` espera. El nombre del campo no lo dice y así lo manda la app publicada.
 *
 * ⚠️ **`envia_dinero_monto` acá es la SUMA de la plata que lleva más el valor declarado**
 * —`alta.page.ts:344` los suma antes de pedir la cotización—, mientras que en el alta los dos
 * viajan separados y sólo el primero queda en `garantia`. O sea que la tarifa se cobra sobre
 * una cifra y el pedido guarda otra. Es del sistema viejo y se replica; cambiarlo cambia lo
 * que se le cobra a la gente.
 */
class CotizarPedidoRequest extends PeticionDeLaApp
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Entero y no negativo: el viejo acepta `numeric` y después hace `intval`, así
            // que una distancia de -5 o «abc» entraba y cotizaba el mínimo.
            'distancia' => ['required', 'integer', 'min:0'],
            'retorno_origen' => ['required', 'boolean'],
            'envia_dinero_monto' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
