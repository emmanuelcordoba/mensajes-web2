<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Requests\Api\CadeteApp\V1\PedirCodigoRequest;
use App\Http\Requests\Api\CadeteApp\V1\RestablecerContrasenaRequest;
use App\Services\RecuperacionDeContrasena;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Recuperar la contraseña desde una de las dos apps, con un código al mail.
 *
 * ⚠️ **Estas dos rutas las comparten la app del cadete y la de clientes**, y por eso viven
 * en `CadeteApp/V1` con una nota en vez de duplicarse: en el sistema viejo están en
 * `Api/Auth/`, afuera de los cuatro consumidores, justamente porque no son de uno solo. Si
 * alguna de las dos apps necesita algún día una recuperación distinta, ahí se separan.
 *
 * ## ⚠️ ACÁ ESTÁ EL PEOR AGUJERO DE TODO EL SISTEMA VIEJO, Y SE PORTA
 *
 * `sendcode` **devuelve el código de verificación en la respuesta HTTP**, y la app lo
 * verifica del lado del cliente: `sendcode.page.ts` guarda
 * `response['user'].codigo_de_verificacion`, lo compara en el teléfono con lo que la persona
 * tipeó y, si coincide, navega a la pantalla de contraseña nueva llevando **el código que le
 * dio el servidor**. O sea que cualquiera que sepa el email de un cadete puede pedir el
 * código, leerlo de la respuesta y cambiarle la contraseña. Es SEC-2.
 *
 * Se replica porque **la app publicada no funciona sin eso**: si el código no viene, hace
 * `response['user'].codigo_de_verificacion.toString()` sobre un `undefined` y la pantalla se
 * rompe; y aunque no se rompiera, no tiene con qué verificar. Apagarlo hoy es dejar a los
 * 220 cadetes sin forma de recuperar la contraseña.
 *
 * `RECUPERACION_CODIGO_EN_LA_RESPUESTA=false` lo apaga, y es lo que hay que hacer **el día
 * que las dos apps se republiquen** verificando contra el servidor. Es la etapa 2 de SEC-2 y
 * no puede cerrarse del lado del servidor solo.
 *
 * Lo que sí se cierra desde acá, y ya está: sólo entran los roles de las apps —así que
 * ninguna cuenta del panel es tomable por esta vía—, el código sale de `random_int` y no de
 * `rand`, la comparación es `hash_equals` y no `!=`, y sale **sólo el código** en vez del
 * usuario entero con todas sus columnas.
 */
class RecuperarContrasenaController extends ControladorDeLaApp
{
    /**
     * Intentos fallidos que se toleran por cuenta antes de cerrar la puerta, y por cuánto.
     *
     * Son los números del sistema viejo. Cinco es holgado para alguien que se equivoca
     * tipeando y ridículo para quien adivina: seis dígitos son un millón de combinaciones.
     *
     * ⚠️ El contador cuelga del email y NO de la IP: lo que hay que acotar es cuántas veces
     * se puede adivinar el código **de una cuenta**, y repartir los pedidos entre varias IP
     * no debería comprar intentos extra.
     *
     * ⚠️ Y mientras el código viaje en la respuesta, esto es decoración: no hace falta
     * adivinar nada. Se porta igual, porque el día que se apague es lo único que queda.
     */
    public const MAXIMO_DE_INTENTOS = 5;

    public const MINUTOS_DE_BLOQUEO = 15;

    /** Si el código sale en la respuesta. El porqué entero está arriba. */
    public static function elCodigoSaleEnLaRespuesta(): bool
    {
        return (bool) config('auth.recuperacion.codigo_en_la_respuesta', true);
    }

    /**
     * Pedir el código.
     *
     * ⚠️ Contesta `{user: null}` tanto si el email no existe como si existe pero es del
     * panel, que es lo que hace el sistema viejo. Es un oráculo de qué casillas están
     * registradas, y se porta: con el código viajando al lado, ocultarlo no cambia nada.
     */
    public function enviarCodigo(PedirCodigoRequest $request, RecuperacionDeContrasena $recuperacion): JsonResponse
    {
        $pedido = $recuperacion->pedirCodigo($request->string('email')->value());

        if ($pedido === null || ! self::elCodigoSaleEnLaRespuesta()) {
            // ⚠️ Con el interruptor apagado la app publicada va a decir «Código inválido»,
            // porque es lo que hace cuando `user` viene null. Eso es el estado posterior a
            // republicarlas, no el de hoy.
            return response()->json(['user' => null]);
        }

        return response()->json([
            // Sólo el código: el sistema viejo manda el modelo entero del usuario, con
            // `rol_id`, `bloqueado`, `deleted_at` y todo lo demás. La app lee este campo y
            // nada más.
            'user' => ['codigo_de_verificacion' => $pedido['codigo']],
        ]);
    }

    /**
     * Cambiar la contraseña con el código.
     *
     * ⚠️ El mismo error para «no es una cuenta de las apps» y para «el código no coincide»:
     * distinguirlos delataría cuáles son cuentas del panel.
     */
    public function restablecer(
        RestablecerContrasenaRequest $request,
        RecuperacionDeContrasena $recuperacion,
    ): JsonResponse {
        $email = $request->string('email')->value();
        $clave = 'password-reset|'.mb_strtolower(trim($email));

        if (RateLimiter::tooManyAttempts($clave, self::MAXIMO_DE_INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            return self::error(
                'Demasiados intentos. Probá de nuevo en '.ceil($segundos / 60).' minutos.',
                429,
            )->header('Retry-After', (string) $segundos);
        }

        $cuenta = $recuperacion->cuentaConEseCodigo($email, $request->input('code'));

        if ($cuenta === null) {
            RateLimiter::hit($clave, self::MINUTOS_DE_BLOQUEO * 60);

            return self::error('El código es inválido.', 400);
        }

        RateLimiter::clear($clave);

        $recuperacion->cambiar($cuenta, $request->string('password')->value());

        // La app muestra su propio texto fijo y no lee nada de esto; se manda igual, que es
        // lo que contesta el sistema viejo.
        return response()->json(['mensaje' => 'Contraseña restaurada con éxito.']);
    }
}
