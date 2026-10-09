<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Requests\Api\CadeteApp\V1\PostularseRequest;
use App\Services\PostulacionDesdeLaApp;
use Illuminate\Http\JsonResponse;

/**
 * Postularse como cadete desde la app, sin tener cuenta.
 *
 * Es uno de los dos endpoints públicos que quedan, y el único que escribe. Qué se acepta
 * está en `PostularseRequest`; cómo se guarda, en `PostulacionDesdeLaApp`.
 *
 * ⚠️ **La respuesta es `{ok: true}` y nada más**, como en el sistema viejo. La app no lee
 * nada de ella: muestra «Hemos recibido su postulación» y vuelve al inicio. No devolver la
 * postulación tampoco es un descuido —adentro está el DNI de la persona y las cuatro
 * imágenes— y quien se postula no tiene con qué volver a consultarla.
 */
class PostulacionController extends ControladorDeLaApp
{
    public function store(PostularseRequest $request, PostulacionDesdeLaApp $postulaciones): JsonResponse
    {
        $postulaciones->guardar($request->validated());

        return response()->json(['ok' => true]);
    }
}
