<?php

namespace App\Http\Controllers\Api\ClienteApp\V1;

use App\Http\Requests\Api\ClienteApp\V1\GuardarFcmTokenDelClienteRequest;
use App\Http\Requests\Api\ClienteApp\V1\GuardarPerfilDelClienteRequest;
use App\Http\Resources\Api\ClienteApp\V1\ClienteConSuCuenta;
use App\Http\Resources\Api\ClienteApp\V1\PublicidadDeLaApp;
use App\Models\PublicidadApp;
use App\Services\PerfilDelCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La pantalla de inicio y el perfil de un cliente de la app.
 *
 * ⚠️ **`get-setup` de la v2 NO se porta, y no es un olvido.** Ese endpoint devolvía las
 * publicidades, el horario y tres configuraciones de costo, y su único consumidor
 * —`alta.page.ts`— dejó de llamarlo el **2024-06-30**, en el commit `1333550` («Limpieza»),
 * cuando el precio pasó a calcularlo el servidor con `cotizarPedido`. El método quedó
 * colgado en `data.service.ts` sin que nadie lo use, igual que `getAsignados` en la app del
 * cadete. HEAD de la app es de 2026-08-06, así que ningún teléfono publicado en más de dos
 * años lo pide.
 *
 * Y de paso ese método tenía un 500 esperando: leía `$costo_por_km->valor` sin mirar si la
 * configuración existe.
 */
class ClienteController extends ControladorDeLaApp
{
    /**
     * Guardar el token de notificaciones de este teléfono.
     *
     * Sin esto, al cliente no le llega el aviso de que su pedido fue cotizado, que salió o
     * que se entregó. Los tres los manda `NotificacionesAlTelefono`.
     */
    public function guardarFcmToken(GuardarFcmTokenDelClienteRequest $request): JsonResponse
    {
        $cliente = self::elCliente($request);

        $cliente->fcm_token = $request->string('fcm_token')->value();
        $cliente->save();

        return response()->json(['ok' => true]);
    }

    /**
     * La pantalla de inicio: el carrusel.
     *
     * ⚠️ **Sólo van las publicidades, y el sistema viejo manda tres cosas más.** Allá la
     * respuesta lleva `usuario` —la cuenta ENTERA con su cliente anidado— y
     * `horariodeatencion`. Buscados en toda la historia del repositorio de la app:
     * `res['usuario']` no aparece nunca y `horariodeatencion` tampoco. El único consumidor es
     * `inicio.page.ts`, que desde su primera versión hace una sola cosa:
     * `this.slides = res['publicidades']`.
     *
     * O sea que mandar la cuenta entera era la forma de SEC-14 otra vez, en la pantalla que
     * el cliente abre siempre, a cambio de nada.
     */
    public function inicio(Request $request): JsonResponse
    {
        // El token y el rol los exige la ruta; esto deja dicho que hace falta un cliente.
        self::elCliente($request);

        $publicidades = PublicidadApp::query()
            ->visibles()
            ->with('imagen')
            ->orderBy('id')
            ->get();

        return response()->json([
            'ok' => true,
            'publicidades' => PublicidadDeLaApp::collection($publicidades),
        ]);
    }

    /** Los datos del cliente, para la pantalla de perfil. */
    public function perfil(Request $request): JsonResponse
    {
        $cliente = self::elCliente($request);

        return response()->json([
            'ok' => true,
            'cliente' => new ClienteConSuCuenta($cliente->load('user')),
        ]);
    }

    /**
     * Guardar el perfil.
     *
     * Qué se puede cambiar está en `GuardarPerfilDelClienteRequest`; cómo se escribe, en
     * `PerfilDelCliente`.
     */
    public function guardarPerfil(GuardarPerfilDelClienteRequest $request, PerfilDelCliente $perfiles): JsonResponse
    {
        $cliente = self::elCliente($request);

        $cliente = $perfiles->guardar(self::laCuenta($request), $cliente, $request->validated());

        return response()->json([
            'ok' => true,
            'cliente' => new ClienteConSuCuenta($cliente->load('user')),
        ]);
    }
}
