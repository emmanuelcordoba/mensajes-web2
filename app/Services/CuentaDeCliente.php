<?php

namespace App\Services;

use App\Http\Requests\Api\ClienteApp\V1\RegistrarseRequest;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Corregir el email y la contraseña de la cuenta de la app de un cliente, desde el panel.
 *
 * El email y la contraseña no son columnas de `clientes`: viven en `users`, y hasta ahora
 * sólo se editaban desde la pantalla de Cuentas. Esto es el mismo cambio hecho desde el
 * cliente, que es donde el empleado está mirando cuando se entera de que el email está
 * mal escrito.
 *
 * ⚠️ **Está en un servicio y no adentro del formulario porque cambiar una contraseña no
 * es escribir una columna.** Son cuatro escrituras que tienen que ir juntas o no ir: la
 * contraseña, el `remember_token`, los tokens de la app y las sesiones abiertas. Ver
 * `revocar()`.
 *
 * ⚠️ **Sólo corrige la cuenta que ya existe; no crea ninguna.** 3.684 de los 11.000
 * clientes no tienen cuenta —los cargó la oficina y nunca se registraron—, y darles una
 * desde acá es un alta, no una corrección: habría que decidir qué pasa con la
 * verificación del email y avisarle a la persona. La cuenta del cliente la crea la app al
 * registrarse (`RegistroDeClienteApp`).
 *
 * ⚠️ **Las 7.316 cuentas de clientes son todas de rol `cliente_app`.** Lo verifiqué
 * contra producción antes de escribir esto, y es la razón por la que esta pantalla no
 * necesita preguntar qué rol tiene la cuenta que está tocando: ninguna entra a /admin,
 * así que cambiarle la contraseña desde acá no es un camino para tomar el panel. Si algún
 * día un cliente cuelga de una cuenta con otro rol, esa suposición deja de valer.
 *
 * ⚠️ **6 de esas cuentas son además de un cadete**, porque es la misma persona. Cambiarle
 * el email le cambia también el ingreso a la app de cadete. Es correcto —es una sola
 * cuenta— pero no es evidente, así que la pantalla lo dice antes de que alguien lo
 * descubra.
 */
class CuentaDeCliente
{
    /**
     * Qué contraseña se le puede poner a un cliente, en un solo lugar.
     *
     * ⚠️ **Es la regla de la app y NO `Password::defaults()`, a propósito.** En producción
     * el default de la casa pide 12 caracteres con mayúsculas, números, símbolos y que no
     * esté filtrada (`AppServiceProvider::configureDefaults()`), y eso está bien para una
     * cuenta del panel. Pero la app pide `min:6` al registrarse
     * (`RegistrarseRequest::LARGO_MINIMO_DE_CONTRASENA`), así que exigir el default acá
     * dejaría al panel sin poder escribir una contraseña que la app sí acepta —y que la
     * persona va a poder volver a poner ella misma desde la recuperación, con la regla de
     * la app—. Ser más estricto en esta pantalla no hace más segura a la cuenta: mueve el
     * problema a donde no se ve.
     *
     * La asimetría que queda anotada: la pantalla de Cuentas sí usa `Password::default()`,
     * y ahí sí hay cuentas del panel de por medio. Unificarlas pide decidir la regla por
     * rol, que es una discusión aparte.
     *
     * @return list<string>
     */
    public static function reglasDeContrasena(): array
    {
        return ['string', 'min:'.RegistrarseRequest::LARGO_MINIMO_DE_CONTRASENA, 'max:255'];
    }

    /** Lo que se le muestra a quien edita cuando la contraseña es demasiado corta. */
    public static function mensajeDeContrasena(): string
    {
        return 'La contraseña tiene que tener al menos '
            .RegistrarseRequest::LARGO_MINIMO_DE_CONTRASENA.' caracteres.';
    }

    /**
     * Por qué no se puede editar la cuenta, o null si se puede.
     *
     * La pregunta se hace en los dos lados —el formulario antes de dibujar los campos y
     * el servicio antes de escribir—, igual que en `BajaDeCliente`. El que se
     * desincroniza en silencio es el formulario, que ofrece algo que después falla.
     */
    public static function porQueNo(Cliente $cliente): ?string
    {
        if ($cliente->user === null) {
            return 'Este cliente no tiene cuenta de la app, así que no hay email ni contraseña que corregir.';
        }

        return null;
    }

    /**
     * Escribe el email y/o la contraseña nuevos.
     *
     * Las dos son opcionales y en blanco significa «no la toques»: así guardar un cambio
     * de dirección no le borra la contraseña a nadie.
     *
     * @return array{email: bool, contrasena: bool, tokens: int, sesiones: int} qué se
     *                                                                          cambió, para poder decírselo a quien editó
     */
    public function actualizar(Cliente $cliente, ?string $email, ?string $contrasena): array
    {
        $motivo = self::porQueNo($cliente);

        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        $cuenta = $cliente->user;

        return DB::transaction(function () use ($cuenta, $email, $contrasena): array {
            $cambios = ['email' => false, 'contrasena' => false, 'tokens' => 0, 'sesiones' => 0];

            // El modelo ya normaliza en su mutador; acá se normaliza para poder comparar
            // con lo guardado y no escribir de más. Ver DATA-7.
            $email = $email === null ? null : mb_strtolower(trim($email));

            if (filled($email) && $email !== $cuenta->email) {
                $cuenta->email = $email;

                // ⚠️ **La verificación se cae con el email, y eso tiene una consecuencia
                // que hay que conocer.** `AuthController::login()` responde 424 y NO deja
                // entrar cuando `email_verified_at` es null, así que la persona va a tener
                // que verificar la casilla nueva antes de volver a usar la app. No es un
                // callejón: ese mismo 424 le manda el código al email nuevo.
                //
                // Se hace igual, por dos razones. La primera es que ya es la regla de la
                // casa: `ProfileController::update()` hace exactamente esto cuando alguien
                // se cambia su propio email. La segunda es la que importa: si la
                // verificación sobreviviera al cambio, apuntar la cuenta de un cliente a
                // cualquier casilla y entrar con ella sería un movimiento de una sola
                // pantalla.
                $cuenta->email_verified_at = null;

                $cambios['email'] = true;
            }

            if (filled($contrasena)) {
                // El cast `hashed` del modelo la encripta.
                $cuenta->password = $contrasena;
                $cambios['contrasena'] = true;
            }

            $cuenta->save();

            if ($cambios['contrasena']) {
                $revocado = self::revocar($cuenta);
                $cambios['tokens'] = $revocado['tokens'];
                $cambios['sesiones'] = $revocado['sesiones'];
            }

            return $cambios;
        });
    }

    /**
     * Corta todo lo que seguía abierto con la contraseña vieja.
     *
     * ⚠️ **Cambiar la contraseña no invalida nada por sí solo, y es la parte que se
     * olvida.** Un token de Sanctum no guarda la contraseña ni la mira: sigue entrando a
     * la API después de que la contraseña cambió. Si se la cambiás porque la cuenta quedó
     * en manos de otro, sin esto no le cortaste el acceso. Son tres cosas distintas:
     *
     * - **Los tokens de la app** (`personal_access_tokens`), que es con lo que entra el
     *   teléfono. `AuthController::login()` emite uno por ingreso y no los vence.
     * - **Las sesiones del navegador**, que viven en la tabla `sessions` porque
     *   `SESSION_DRIVER=database`. Si algún día pasa a `redis` o `file` esto no las
     *   encuentra, y por eso mira el driver en vez de suponerlo.
     * - **El `remember_token`**, que es la cookie de «mantenerme conectado» y no está en
     *   ninguna de las dos tablas.
     *
     * @return array{tokens: int, sesiones: int}
     */
    private static function revocar(User $cuenta): array
    {
        $tokens = $cuenta->tokens()->delete();

        $cuenta->setRememberToken(Str::random(60));
        $cuenta->save();

        return ['tokens' => $tokens, 'sesiones' => self::cerrarSesiones($cuenta)];
    }

    /** Las sesiones guardadas en la base, si es ahí donde están. */
    private static function cerrarSesiones(User $cuenta): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $cuenta->getKey())
            ->delete();
    }
}
