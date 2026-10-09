<?php

namespace App\Http\Controllers\Api\CadeteApp\V1;

use App\Http\Requests\Api\CadeteApp\V1\GuardarFcmTokenRequest;
use App\Http\Requests\Api\CadeteApp\V1\GuardarPerfilRequest;
use App\Http\Requests\Api\CadeteApp\V1\LoginDeLaAppRequest;
use App\Http\Requests\Api\CadeteApp\V1\UbicacionDelCadeteRequest;
use App\Http\Resources\Api\CadeteApp\V1\CadeteDeLaApp;
use App\Http\Resources\Api\CadeteApp\V1\UsuarioDeLaApp;
use App\Models\Cadete;
use App\Models\Rol;
use App\Models\User;
use App\Services\HistorialDelCadete;
use App\Services\IngresoALaColaDesdeLaApp;
use App\Services\PerfilDelCadete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La API de la app del cadete.
 *
 * ⚠️ **El contrato lo manda la app, no este archivo.** Está escrito en
 * `mensajes-cadeteria-cadete-app/src/app/interfaces/interfaces.ts`, y la app está publicada
 * en los teléfonos: no se actualiza a la par del servidor. Cada campo que falte o cambie de
 * tipo es una pantalla rota para alguien que está trabajando. Las formas viven en
 * `CadeteDeLaApp` y `UsuarioDeLaApp`, con el porqué de cada decisión.
 *
 * ⚠️ **Las rutas no llevan prefijo de versión** —`/api/getcadete`, `/api/cambiarestado`—
 * porque así las pide la app. Se ven mal y se quedan.
 *
 * ⚠️ **Las notificaciones push sí se portan.** El token del teléfono entra por
 * `guardarFcmToken()` y sale por `cerrarSesion()`; lo que se manda está en
 * `NotificacionesAlTelefono` y cómo se manda en `EnviarNotificacion`.
 */
class CadeteController extends ControladorDeLaApp
{
    /**
     * Cuántas contraseñas erradas se aguantan por cuenta Y POR IP, y por cuánto.
     *
     * Son los números del sistema viejo. El motivo de contar por las dos cosas está escrito
     * allá y vale igual: contar sólo por cuenta deja que cualquiera deje a un cadete afuera
     * de la app tirándole intentos fallidos a su email, y un cadete que no puede entrar no
     * puede trabajar.
     */
    public const MAXIMO_DE_INTENTOS = 10;

    public const MINUTOS_DE_BLOQUEO = 15;

    /**
     * Entrar a la app.
     *
     * ⚠️ **Sólo cuentan los intentos FALLIDOS**, como en el sistema viejo: al entrar bien
     * se limpia el contador. Un `throttle` en la ruta contaría también los logins buenos y
     * dejaría afuera a un cadete que reinstala la app o al que se le vence el token.
     *
     * ⚠️ El email se compara **sin distinguir mayúsculas**, y después se comprueba la
     * contraseña contra cada candidato: en MongoDB conviven cuentas que sólo difieren en
     * mayúsculas (DATA-7). Acá la columna ya es minúscula con un CHECK, pero la comparación
     * se deja igual de tolerante porque lo que escribe el cadete en el teléfono no lo es.
     *
     * ⚠️ **El mismo mensaje para «no existe» y «contraseña equivocada»**, y también para
     * «no es un cadete»: decir cuál de las tres es sirve para averiguar qué emails existen.
     */
    public function login(LoginDeLaAppRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $clave = 'login-cadete|'.mb_strtolower(trim($datos['email'])).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, self::MAXIMO_DE_INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            return self::error(
                'Demasiados intentos. Probá de nuevo en '.ceil($segundos / 60).' minutos.',
                429,
            )->header('Retry-After', (string) $segundos);
        }

        $usuario = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($datos['email']))])
            ->with('cadete')
            ->get()
            ->first(fn (User $candidato): bool => Hash::check($datos['password'], $candidato->password));

        if ($usuario === null || ! $usuario->tieneRol(Rol::CADETE) || $usuario->cadete === null) {
            RateLimiter::hit($clave, self::MINUTOS_DE_BLOQUEO * 60);

            return self::error('El email y/o contraseña son incorrectos.', 400);
        }

        RateLimiter::clear($clave);

        // ⚠️ El token se emite y se devuelve, pero no se guarda en la fila del usuario. En
        // el sistema viejo `api_token` guardaba el JWT en texto plano: quien leyera la
        // tabla entraba como cualquier cadete. Ver SEC-5.
        $token = $usuario->createToken('app-cadetes')->plainTextToken;

        return response()->json([
            'ok' => true,
            // La app lee `res['access_token']` —`auth.service.ts:52`— y ese nombre no cambia
            // aunque el token ya no sea de Passport sino de Sanctum: para la app es una
            // cadena que guarda y repite en la cabecera.
            'access_token' => $token,
            'user' => new UsuarioDeLaApp($usuario),
        ]);
    }

    /** Los datos del cadete que está usando la app, con su lugar en la cola. */
    public function getCadete(Request $request): JsonResponse
    {
        $cadete = self::elCadete($request);

        return response()->json([
            'cadete' => (new CadeteDeLaApp($cadete))->conLaPosicionEnCola(),
        ]);
    }

    /**
     * Ponerse activo, o inactivo.
     *
     * Es el único botón de la pantalla principal de la app. Las negativas —y por qué no son
     * las mismas que las del mostrador— viven en `IngresoALaColaDesdeLaApp`.
     */
    public function cambiarEstado(Request $request, IngresoALaColaDesdeLaApp $ingreso): JsonResponse
    {
        $cadete = self::elCadete($request);

        $motivo = IngresoALaColaDesdeLaApp::porQueNo($cadete);

        if ($motivo !== null) {
            return self::error($motivo, 400);
        }

        $ingreso->alternar($cadete);

        return response()->json([
            'cadete' => (new CadeteDeLaApp($cadete))->conLaPosicionEnCola(),
        ]);
    }

    /**
     * Dónde está el cadete.
     *
     * Es lo que alimenta el mapa del panel y el del detalle de un cadete. La app la manda
     * sola mientras está activa, desde el seguimiento en segundo plano.
     *
     * ⚠️ El sistema viejo acepta esto por `form-data` o por JSON, con dos caminos distintos
     * y dos validaciones distintas —una de ellas devolvía 400 en vez de 422—. Acá es uno
     * solo: un `FormRequest` lee los dos formatos igual.
     */
    public function actualizarUbicacion(UbicacionDelCadeteRequest $request): JsonResponse
    {
        $cadete = self::elCadete($request);

        $datos = $request->validated();

        $cadete->ubicacion_lat = (float) $datos['lat'];
        $cadete->ubicacion_lon = (float) $datos['lon'];
        $cadete->save();

        return response()->json([
            'ok' => true,
            'cadete' => new CadeteDeLaApp($cadete),
        ]);
    }

    /**
     * El perfil: la cuenta del cadete con sus datos adentro.
     *
     * ⚠️ **No devuelve `reporte_semanal`, y el sistema viejo sí.** Allá la respuesta trae
     * `['cant_viajes' => 0, 'dinero' => 0]` escrito a mano, con ceros fijos, y la app no lo
     * lee en ninguna pantalla: `perfil.page.ts` y `perfil-editar.page.ts` toman
     * `resp['usuario']` y nada más. Era un campo que prometía un informe que nunca se hizo.
     * Los números de verdad están en el historial.
     */
    public function getPerfil(Request $request): JsonResponse
    {
        self::elCadete($request);

        return response()->json([
            'usuario' => new UsuarioDeLaApp(self::laCuenta($request)->load(['foto', 'cadete'])),
        ]);
    }

    /**
     * Guardar el perfil, con la foto si cambió.
     *
     * Qué se puede cambiar y qué no está en `GuardarPerfilRequest`; cómo se escribe, en
     * `PerfilDelCadete`.
     *
     * ⚠️ **La respuesta tiene que traer el `cadete` adentro del usuario.** La app hace
     * `this.usuario = res['usuario']` y la pantalla de perfil lee `usuario.cadete?.nombres`:
     * si el cadete no viaja, el cadete acaba de guardar bien y ve su perfil en blanco.
     */
    public function editarPerfil(GuardarPerfilRequest $request, PerfilDelCadete $perfil): JsonResponse
    {
        $cadete = self::elCadete($request);

        $cuenta = $perfil->guardar(self::laCuenta($request), $cadete, $request->validated());

        return response()->json([
            'ok' => true,
            'usuario' => new UsuarioDeLaApp($cuenta),
        ]);
    }

    /**
     * El historial: hoy, los últimos cinco días y los últimos seis meses.
     *
     * Las cuentas y las tres decisiones que no son obvias —por qué cuenta por `created_at`,
     * por qué un tramo abierto vale cero y por qué el mes en curso ahora sí aparece— están
     * en `HistorialDelCadete`.
     */
    public function historial(Request $request, HistorialDelCadete $historial): JsonResponse
    {
        return response()->json([
            'historial' => $historial->para(self::elCadete($request)),
        ]);
    }

    /**
     * Guardar el token con el que se le mandan notificaciones a este teléfono.
     *
     * La app lo manda sola al arrancar y cada vez que Firebase se lo renueva. Sin esto
     * guardado, el cadete no recibe el aviso de que le asignaron un pedido ni el del chat.
     *
     * ⚠️ **Un cadete, un teléfono.** La columna es una sola, así que el token nuevo pisa al
     * anterior: si el cadete entra desde otro aparato, los avisos van al último que entró.
     * Es como funciona el sistema viejo y es lo que la app espera.
     */
    public function guardarFcmToken(GuardarFcmTokenRequest $request): JsonResponse
    {
        $cadete = self::elCadete($request);

        $cadete->fcm_token = $request->string('fcm_token')->value();
        $cadete->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Cerrar sesión: se va de la cola y deja de recibir avisos.
     *
     * Hace las tres cosas que hace el sistema viejo —borrar el token, ponerse inactivo y
     * guardar— y una más que allá falta: **cerrar el tramo de actividad abierto**. Eso lo
     * hace el modelo al guardar, y es el agujero de PANEL-17: allá el fin del tramo se
     * escribe sólo en `cambiarEstado()`, así que el cadete que cierra sesión deja un tramo
     * abierto para siempre. Son 113.065 de ésos, y son los que en el historial valen cero.
     *
     * ⚠️ **El token de la API no se revoca acá, y es a propósito.** La app guarda el
     * `access_token` en el teléfono y lo sigue usando después de esto: `logout()` de
     * `auth.service.ts` llama a este endpoint y recién después borra lo suyo. Revocar el
     * token de Sanctum en el medio dejaría la respuesta sin llegar. Lo que corta el acceso
     * es que la app lo olvide, igual que allá.
     */
    public function cerrarSesion(Request $request): JsonResponse
    {
        $cadete = self::elCadete($request);

        $cadete->fcm_token = null;
        $cadete->estado = Cadete::ESTADO_INACTIVO;
        $cadete->save();

        return response()->json(['ok' => true]);
    }
}
