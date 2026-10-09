<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Requests\Api\CadeteApp\V1\EnviarMensajeDesdeLaAppRequest;
use App\Http\Resources\Api\CadeteApp\V1\CadeteDeLaApp;
use App\Http\Resources\Api\CadeteApp\V1\MensajeDeLaApp;
use App\Services\ConversacionConElCadete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El chat del cadete con la central.
 *
 * Son tres endpoints: la conversación, el envío y cuántos quedaron sin leer para el globito
 * rojo de la pestaña.
 *
 * ⚠️ **La forma de cada mensaje es lo delicado**, porque de dos de sus campos depende de qué
 * lado de la pantalla se dibuja cada globo. Está en `MensajeDeLaApp`.
 *
 * ⚠️ **Las notificaciones push no se portan**: no hay Firebase en el sistema nuevo. El
 * sistema viejo le avisaba a la central cuando el cadete escribía; ahora el panel se refresca
 * solo cada cinco segundos y lo ve ahí.
 */
class MensajeController extends ControladorDeLaApp
{
    /**
     * La conversación, y de paso queda leída.
     *
     * ⚠️ **Abrir el chat marca todo como leído, y la app lo pide cada 10 segundos** mientras
     * la pantalla está abierta. O sea que esto escribe en cada vuelta mientras haya algo sin
     * leer, y es el comportamiento del sistema viejo: el cadete tiene el chat a la vista, así
     * que está leyendo. Lo que sí cambia es que sean una sentencia y no una por mensaje.
     *
     * ⚠️ Y el orden se pide explícitamente, porque marcar como leído reescribe filas. El
     * porqué completo está en `ConversacionConElCadete::mensajes()`.
     */
    public function index(Request $request, ConversacionConElCadete $conversacion): JsonResponse
    {
        $cadete = self::elCadete($request);

        $conversacion->losLeyoElCadete($cadete);

        return response()->json([
            'mensajes' => MensajeDeLaApp::conversacion($conversacion->mensajes($cadete), $cadete),
            // El cadete entero viaja una vez acá, y no repetido en cada mensaje. La app lo usa
            // para dibujar el pie con el campo de escribir: `<ion-footer *ngIf="cadete">`.
            'cadete' => new CadeteDeLaApp($cadete),
        ]);
    }

    /**
     * El cadete escribe.
     *
     * ⚠️ **Devuelve la conversación entera además del mensaje nuevo**, porque es lo que la
     * app lee: `enviarMensaje()` hace `this.mensajes = data['mensajes']` y descarta
     * `data['mensaje']`. Si no viniera, el chat se vaciaría al enviar.
     */
    public function store(
        EnviarMensajeDesdeLaAppRequest $request,
        ConversacionConElCadete $conversacion,
    ): JsonResponse {
        $cadete = self::elCadete($request);

        $mensaje = $conversacion->escribeElCadete(
            $cadete,
            self::laCuenta($request),
            $request->string('mensaje')->value(),
        );

        return response()->json([
            'ok' => true,
            'mensaje' => (new MensajeDeLaApp($mensaje))->deLaConversacionDe($cadete),
            'mensajes' => MensajeDeLaApp::conversacion($conversacion->mensajes($cadete), $cadete),
        ]);
    }

    /**
     * Cuántos quedaron sin leer, para el globito rojo de la pestaña de chat.
     *
     * ⚠️ La app lee `resp['cantidad']` y nada más —`tabs.page.ts`—, pero `mensajes` está en
     * el contrato y son los que todavía no vio: 28 en todo el sistema. Se emite.
     */
    public function noLeidos(Request $request, ConversacionConElCadete $conversacion): JsonResponse
    {
        $cadete = self::elCadete($request);

        $sinLeer = $conversacion->sinLeerPorElCadete($cadete);

        return response()->json([
            'mensajes' => MensajeDeLaApp::conversacion($sinLeer, $cadete),
            'cantidad' => $sinLeer->count(),
        ]);
    }
}
