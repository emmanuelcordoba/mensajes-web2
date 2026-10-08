<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CadeteDeLaApp;
use App\Http\Resources\Api\UsuarioDeLaApp;
use App\Models\Cadete;
use App\Models\Rol;
use App\Models\User;
use App\Services\IngresoALaColaDesdeLaApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

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
 * ⚠️ **Las notificaciones push no se portan**: no hay Firebase en el sistema nuevo.
 */
class CadeteController extends Controller
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
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $clave = 'login-cadete|'.mb_strtolower(trim($datos['email'])).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, self::MAXIMO_DE_INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            return response()->json([
                'error' => 'Demasiados intentos. Probá de nuevo en '
                    .ceil($segundos / 60).' minutos.',
                'code' => 429,
            ], 429)->header('Retry-After', (string) $segundos);
        }

        $usuario = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($datos['email']))])
            ->with('cadete')
            ->get()
            ->first(fn (User $candidato): bool => Hash::check($datos['password'], $candidato->password));

        if ($usuario === null || ! $usuario->tieneRol(Rol::CADETE) || $usuario->cadete === null) {
            RateLimiter::hit($clave, self::MINUTOS_DE_BLOQUEO * 60);

            return response()->json([
                'error' => 'El email y/o contraseña son incorrectos.',
                'code' => 400,
            ], 400);
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
            return response()->json(['error' => $motivo, 'code' => 400], 400);
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
     * solo: `$request->validate()` lee los dos formatos.
     */
    public function actualizarUbicacion(Request $request): JsonResponse
    {
        $cadete = self::elCadete($request);

        $datos = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $cadete->ubicacion_lat = (float) $datos['lat'];
        $cadete->ubicacion_lon = (float) $datos['lon'];
        $cadete->save();

        return response()->json([
            'ok' => true,
            'cadete' => new CadeteDeLaApp($cadete),
        ]);
    }

    /**
     * El cadete de quien está autenticado.
     *
     * ⚠️ Una cuenta con rol de cadete pero sin fila de cadete no existe en la práctica, pero
     * el tipo lo permite y el sistema viejo hacía `Auth::user()->cadete->algo` sin mirar:
     * eso es un 500 con «Attempt to read property on null» en la cara del cadete.
     */
    protected static function elCadete(Request $request): Cadete
    {
        $cadete = $request->user()?->cadete;

        if ($cadete === null) {
            throw ValidationException::withMessages([
                'cadete' => 'Esta cuenta no tiene un cadete asociado.',
            ]);
        }

        return $cadete;
    }
}
