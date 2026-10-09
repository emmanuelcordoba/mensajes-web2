<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Requests\Api\CadeteApp\V1\MovimientosDeSaldoRequest;
use App\Http\Resources\Api\CadeteApp\V1\MovimientoDeLaApp;
use App\Models\Pedido;
use App\Services\MovimientosDeSaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La cobranza del cadete: cuánto saldo le queda y en qué se fue.
 *
 * Son dos endpoints de una sola pantalla. **Sólo tiene sentido para los que cobran por
 * saldo**: 48 cadetes de los 220: los otros 172 cobran una cuota semanal, que se arregla en
 * la oficina y no tiene nada que mostrar acá. Para ésos la respuesta trae `cobranza: null` y
 * la pantalla de la app no se dibuja.
 *
 * ⚠️ Lo que viaja es plata, así que viaja como texto de punta a punta y nunca pasa por float.
 * El formato con el que se imprime está en `MovimientoDeLaApp`.
 */
class CobranzaController extends ControladorDeLaApp
{
    /** El saldo y los primeros movimientos. */
    public function index(Request $request, MovimientosDeSaldo $movimientos): JsonResponse
    {
        $cadete = self::elCadete($request);

        $cobranza = null;

        if ($cadete->cobraPorSaldo()) {
            $pagina = $movimientos->pagina($cadete);

            $cobranza = [
                // ⚠️ Cero y no null cuando nunca se le cargó nada. La columna es nullable y
                // el sistema viejo manda el null tal cual, así que la pantalla muestra «$ »
                // a secas. Un saldo que no existe es un saldo de cero.
                'saldo' => Pedido::montoParaMostrar($cadete->cobranza_saldo) ?? '0',
                'movimientos' => MovimientoDeLaApp::collection($pagina['movimientos']),
                'mas_movimientos' => $pagina['hay_mas'],
            ];
        }

        return response()->json([
            'ok' => true,
            'cobranza' => $cobranza,
            // La app compara contra la cadena 'Saldo' para decidir si dibuja la pantalla.
            'modalidad' => $cadete->modalidad_cobranza,
        ]);
    }

    /**
     * Los de más atrás, cuando el cadete toca «Cargar más».
     *
     * Cuántos trae cada página y por qué el orden lleva desempate está en
     * `MovimientosDeSaldo`.
     */
    public function movimientos(MovimientosDeSaldoRequest $request, MovimientosDeSaldo $movimientos): JsonResponse
    {
        $cadete = self::elCadete($request);

        $pagina = $movimientos->pagina($cadete, $request->integer('offset'));

        return response()->json([
            'ok' => true,
            'movimientos' => MovimientoDeLaApp::collection($pagina['movimientos']),
            'mas_movimientos' => $pagina['hay_mas'],
        ]);
    }
}
