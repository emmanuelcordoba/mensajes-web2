<?php

namespace App\Http\Controllers\Api\ClienteApp\V1;

use App\Http\Requests\Api\ClienteApp\V1\BuscarClientePorNumeroRequest;
use App\Http\Requests\Api\ClienteApp\V1\LoginDeClienteRequest;
use App\Http\Requests\Api\ClienteApp\V1\RegistrarClienteDelPanelRequest;
use App\Http\Requests\Api\ClienteApp\V1\RegistrarseRequest;
use App\Http\Resources\Api\ClienteApp\V1\ClienteConSuCuenta;
use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use App\Services\RegistroDeClienteApp;
use App\Services\VerificacionDeEmailDeCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Entrar, registrarse y salir de la app de clientes.
 *
 * ⚠️ **Hay DOS registros y no es redundancia.** Quien nunca pidió nada se registra y queda un
 * cliente nuevo; quien la oficina ya tenía cargado —porque llamaba por teléfono— se registra
 * contra su número de cliente en dos pasos, y la fila que ya existe pasa a tener cuenta. Las
 * dos puertas y por qué están, en `RegistroDeClienteApp`.
 *
 * ⚠️ **Las notificaciones push sí se mandan** —ver `NotificacionesAlTelefono`—, pero el token
 * del teléfono del cliente no se guarda acá: entra al verificar el email y por
 * `guardarfcmtoken`, que es de la tanda siguiente.
 */
class AuthController extends ControladorDeLaApp
{
    /**
     * Los mismos números y la misma forma de clave que el login del cadete: por cuenta Y por
     * IP. El porqué está escrito allá y vale igual.
     */
    public const MAXIMO_DE_INTENTOS = 10;

    public const MINUTOS_DE_BLOQUEO = 15;

    /**
     * Entrar.
     *
     * ⚠️ **El 424 es un desvío, no un fallo de credenciales**, así que el contador se limpia
     * ANTES de devolverlo: la contraseña ya fue correcta, y castigar a quien se quedó sin
     * verificar el mail y reintenta no protege nada.
     *
     * ⚠️ **El 424 ya no lleva el usuario, y no es una pérdida.** El sistema viejo contesta
     * `{error: {error: '…', user: {…}}}` y la app hace `setUser(error.user)`, pero `error` ahí
     * es el `HttpErrorResponse` de Angular, que no tiene propiedad `user` —el cuerpo vive en
     * `error.error`—. O sea que ese modelo entero no puede llegar a la app con NINGUNA forma
     * que le demos al cuerpo, y la pantalla de verificación después hace `this.user._id` sobre
     * un `undefined`: **el camino login → «correo no verificado» → verificar está roto en la
     * app publicada**, y se arregla del lado de la app. Mientras tanto, mandar la cuenta
     * entera —con `rol_id`, `bloqueado`, `deleted_at`— era regalar datos a cambio de nada.
     */
    public function login(LoginDeClienteRequest $request, VerificacionDeEmailDeCliente $verificacion): JsonResponse
    {
        $datos = $request->validated();
        $email = mb_strtolower(trim($datos['email']));

        $clave = 'login-cliente|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, self::MAXIMO_DE_INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            return self::error(
                'Demasiados intentos. Probá de nuevo en '.ceil($segundos / 60).' minutos.',
                429,
            )->header('Retry-After', (string) $segundos);
        }

        // Ignorando mayúsculas, y después la contraseña contra cada candidato: lo que la
        // persona escribe en el teléfono no respeta mayúsculas. Ver DATA-7.
        $cuenta = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->with('cliente')
            ->get()
            ->first(fn (User $candidato): bool => Hash::check($datos['password'], $candidato->password));

        // El mismo mensaje para las tres cosas —no existe, contraseña equivocada, no es un
        // cliente de la app—: decir cuál sirve para averiguar qué emails existen.
        if ($cuenta === null || ! $cuenta->tieneRol(Rol::CLIENTE_APP) || $cuenta->cliente === null) {
            RateLimiter::hit($clave, self::MINUTOS_DE_BLOQUEO * 60);

            return self::error('El email y/o contraseña son incorrectos.', 400);
        }

        RateLimiter::clear($clave);

        if ($cuenta->email_verified_at === null) {
            // Se le manda un código nuevo, que es lo que la persona va a necesitar en la
            // pantalla siguiente. El sistema viejo no lo manda acá y deja a quien perdió el
            // mail sin forma de pedir otro hasta llegar a esa pantalla, que es justo la que
            // no funciona.
            $verificacion->mandarCodigo($cuenta, $cuenta->cliente->nombre_mostrado);

            return self::error('El email no está verificado.', 424);
        }

        // ⚠️ El token se emite y se devuelve, pero NO se guarda en la fila: en el sistema
        // viejo `users.api_token` guardaba el JWT en texto plano en 7.679 cuentas y el panel
        // lo mostraba. Ver SEC-5. La clave de la respuesta no cambia: la app lee
        // `res['api_token']` —`login.page.ts:33`—.
        return response()->json([
            'api_token' => $cuenta->createToken('app-clientes')->plainTextToken,
            'cliente' => new ClienteConSuCuenta($cuenta->cliente),
        ]);
    }

    /**
     * Registrarse de cero.
     *
     * La respuesta lleva el id de la cuenta porque la app lo guarda y con eso llama a
     * verificar: `register.page.ts:59` hace `setUser(res['user'])` y después
     * `verificar/{user}`.
     */
    public function registrarse(
        RegistrarseRequest $request,
        RegistroDeClienteApp $registro,
        VerificacionDeEmailDeCliente $verificacion,
    ): JsonResponse {
        $cliente = $registro->registrarNuevo($request->validated());
        $cuenta = $cliente->user;

        if ($cuenta === null) {
            // No puede pasar: el servicio acaba de crearla en la misma transacción.
            self::frenar('No se pudo crear la cuenta.', 500);
        }

        $verificacion->mandarCodigo($cuenta, $cliente->nombre_mostrado);

        return response()->json([
            'ok' => true,
            'user' => ['_id' => (string) $cuenta->id, 'email' => $cuenta->email],
        ]);
    }

    /**
     * Primer paso del registro de un cliente que ya está en el panel: buscarlo por su número.
     *
     * ⚠️ **ES PÚBLICO Y DEVUELVE UN TELÉFONO.** La app compara ese teléfono con el que la
     * persona tipea, **en el teléfono** —`registercliente.page.ts:65`—, así que el servidor
     * se lo tiene que dar. Y los números de cliente son correlativos: alguien puede
     * recorrerlos y quedarse con el teléfono de cada cliente que todavía no se registró. Es
     * SEC-15.
     *
     * Se porta porque la app publicada no puede hacer la comparación de otro modo. Lo que sí
     * se recorta, y el sistema viejo ya lo había recortado: sale el id y el teléfono, no el
     * cliente entero con nombre y dirección. La forma de cerrarlo es mover la comparación al
     * servidor —que el segundo paso reciba el teléfono y lo compare—, y eso pide republicar
     * la app.
     */
    public function buscarPorNumero(BuscarClientePorNumeroRequest $request): JsonResponse
    {
        $cliente = RegistroDeClienteApp::conNumero($request->integer('numero'));

        if ($cliente === null) {
            return self::error(['numero' => ['El número de cliente ingresado no es válido.']], 422);
        }

        $motivo = RegistroDeClienteApp::porQueNoSePuedeRegistrar($cliente);

        if ($motivo !== null) {
            return self::error(['numero' => [$motivo]], 422);
        }

        return response()->json([
            'ok' => true,
            'cliente' => [
                '_id' => (string) $cliente->id,
                'telefono' => $cliente->telefono,
            ],
        ]);
    }

    /**
     * Segundo paso: el cliente del panel queda con cuenta.
     *
     * ⚠️ **La regla se vuelve a preguntar acá, y ahí estaba SEC-11.** Allá este método
     * aceptaba cualquier id: le colgaba un usuario nuevo a un cliente ya registrado, que así
     * perdía su cuenta y todos sus pedidos.
     */
    public function registrarClienteDelPanel(
        RegistrarClienteDelPanelRequest $request,
        Cliente $cliente,
        RegistroDeClienteApp $registro,
        VerificacionDeEmailDeCliente $verificacion,
    ): JsonResponse {
        $motivo = RegistroDeClienteApp::porQueNoSePuedeRegistrar($cliente);

        if ($motivo !== null) {
            return self::error(['numero' => [$motivo]], 422);
        }

        $cliente = $registro->registrarExistente($cliente, $request->validated());
        $cuenta = $cliente->user;

        if ($cuenta === null) {
            self::frenar('No se pudo crear la cuenta.', 500);
        }

        $verificacion->mandarCodigo($cuenta, $cliente->nombre_mostrado);

        return response()->json([
            'ok' => true,
            'user' => ['_id' => (string) $cuenta->id, 'email' => $cuenta->email],
        ]);
    }

    /**
     * Salir: deja de recibir notificaciones en este teléfono.
     *
     * ⚠️ El token de la API no se revoca, por lo mismo que en la app del cadete: la app llama
     * a esto y recién después borra lo suyo.
     */
    public function logout(Request $request): JsonResponse
    {
        $cliente = self::elCliente($request);

        $cliente->fcm_token = null;
        $cliente->save();

        return response()->json(['ok' => true]);
    }
}
