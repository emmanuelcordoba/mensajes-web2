<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Cotizador;
use Illuminate\Http\Request;

/**
 * Cuánto sale un viaje: lo que el alta de pedidos pregunta antes de escribir el precio.
 *
 * Es el `ajax/pedidos/cotizar` del panel viejo. Lo llama el formulario cuando las dos
 * direcciones salieron del autocompletado de Google y entonces hay una distancia: Google
 * contesta los metros en el navegador y acá se convierten en pesos, porque la tarifa son
 * seis configuraciones de la empresa y no viajan al cliente.
 *
 * ⚠️ **Es su propia ruta y no `pedidos/algo`.** No pertenece a ningún pedido: se pregunta
 * antes de que exista. Y así tampoco la captura el `pedidos/{pedido}` del listado.
 *
 * ⚠️ **La distancia la manda el navegador, y eso está bien acá.** Es una cotización, no un
 * cobro: el precio que se guarda es el que el operador ve en el campo y puede reescribir, y
 * la validación del alta lo controla igual. En la API de la app del cliente la distancia no
 * se recibe nunca, se resuelve en el servidor, y el motivo está escrito allá.
 */
class TarifaController extends Controller
{
    /** @return array{costo: numeric-string} */
    public function __invoke(Request $request, Cotizador $cotizador): array
    {
        // Las mismas tres reglas del sistema viejo, más un mínimo en la distancia: allá no
        // lo tenía, y unos metros negativos descontaban del precio mínimo.
        $datos = $request->validate([
            'distancia' => ['required', 'integer', 'min:0', 'max:1000000'],
            'retorno_origen' => ['required', 'boolean'],
            'garantia' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        return [
            'costo' => $cotizador->cotizar(
                (int) $datos['distancia'],
                (bool) $datos['retorno_origen'],
                isset($datos['garantia']) ? (string) $datos['garantia'] : null,
            ),
        ];
    }
}
