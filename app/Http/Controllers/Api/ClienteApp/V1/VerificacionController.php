<?php

namespace App\Http\Controllers\Api\ClienteApp\V1;

use App\Http\Requests\Api\ClienteApp\V1\VerificarEmailRequest;
use App\Http\Resources\Api\ClienteApp\V1\ClienteConSuCuenta;
use App\Models\User;
use App\Services\VerificacionDeEmailDeCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verificar la casilla de un cliente recién registrado, y pedir otro código.
 *
 * ⚠️ **Las dos rutas son PÚBLICAS y se identifican por el id de la cuenta en la URL.** En
 * MongoDB ese id era un ObjectId de 24 caracteres y recorrerlos era impracticable; en
 * PostgreSQL es un entero correlativo. El porqué de que eso igual esté sostenido —y qué se
 * cerró para que lo esté— está en `VerificacionDeEmailDeCliente`.
 *
 * ⚠️ **Acertar el código no sólo verifica el mail: emite un token.** Por eso el límite es el
 * más ajustado de la API —cinco intentos por cuenta— y por eso el código no viaja en ninguna
 * respuesta.
 */
class VerificacionController extends ControladorDeLaApp
{
    /** Cinco alcanzan de sobra para alguien que tipea mal un número de seis dígitos. */
    public const MAXIMO_DE_INTENTOS = 5;

    public const MINUTOS_DE_BLOQUEO = 15;

    /** Y cuántos códigos nuevos se pueden pedir por hora, que allá no tiene tope ninguno. */
    public const MAXIMO_DE_REENVIOS = 5;

    public const MINUTOS_ENTRE_TANDAS_DE_REENVIO = 60;

    /**
     * El código que la persona copió del mail.
     *
     * ⚠️ La respuesta lleva el token DENTRO del cliente, en `cliente.user.api_token`, porque
     * es de donde la app lo lee —`verify.page.ts:62`—. En el login va arriba, en `api_token`.
     * Son dos pantallas publicadas que lo buscan en dos lugares distintos.
     */
    public function verificar(
        VerificarEmailRequest $request,
        User $user,
        VerificacionDeEmailDeCliente $verificacion,
    ): JsonResponse {
        $clave = 'verificar-email|'.$user->id;

        if (RateLimiter::tooManyAttempts($clave, self::MAXIMO_DE_INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            // ⚠️ 429 y no 422, aunque la app publicada sólo muestre el mensaje del servidor
            // cuando el status es 422: para un límite de intentos el status correcto es éste,
            // y con 429 la app cae en su rama genérica —«Hubo un error. Vuelva a intentar más
            // tarde.»—, que dice justamente lo que pasó.
            return self::error(
                'Demasiados intentos. Probá de nuevo en '.ceil($segundos / 60).' minutos.',
                429,
            )->header('Retry-After', (string) $segundos);
        }

        $cliente = $user->cliente;

        if ($cliente === null || ! $user->codigoDeVerificacionCoincide($request->input('codigo'))) {
            RateLimiter::hit($clave, self::MINUTOS_DE_BLOQUEO * 60);

            return self::error(['codigo' => ['El código ingresado es inválido.']], 422);
        }

        RateLimiter::clear($clave);

        $verificacion->verificar($user);

        if ($request->filled('fcm_token')) {
            $cliente->fcm_token = $request->string('fcm_token')->value();
            $cliente->save();
        }

        return response()->json([
            'ok' => true,
            'cliente' => (new ClienteConSuCuenta($cliente->refresh()))
                ->conElToken($user->createToken('app-clientes')->plainTextToken),
        ]);
    }

    /**
     * Pedir otro código.
     *
     * ⚠️ **No devuelve el código, y el sistema viejo sí.** La app ignora esta respuesta
     * —`reenviarCodigo()` sólo reinicia su contador—, así que cerrarlo no le cuesta nada a
     * nadie. Ver `VerificacionDeEmailDeCliente`.
     *
     * ⚠️ **Y tiene tope, que allá no tiene ninguno.** Es un GET público que regenera el
     * código: sin tope sirve para llenarle la casilla a cualquiera, y además invalida el
     * código que la persona estaba por tipear.
     */
    public function reenviar(User $user, VerificacionDeEmailDeCliente $verificacion): JsonResponse
    {
        $cliente = $user->cliente;

        if ($cliente === null) {
            // La misma respuesta que para una cuenta que no existe: desde afuera no hay que
            // poder distinguir qué ids son clientes.
            return self::error('Usuario no válido', 400);
        }

        $clave = 'reenviar-codigo|'.$user->id;

        if (RateLimiter::tooManyAttempts($clave, self::MAXIMO_DE_REENVIOS)) {
            $segundos = RateLimiter::availableIn($clave);

            return self::error(
                'Pediste demasiados códigos. Probá de nuevo en '.ceil($segundos / 60).' minutos.',
                429,
            )->header('Retry-After', (string) $segundos);
        }

        RateLimiter::hit($clave, self::MINUTOS_ENTRE_TANDAS_DE_REENVIO * 60);

        $verificacion->mandarCodigo($user, $cliente->nombre_mostrado);

        return response()->json(['ok' => true]);
    }
}
