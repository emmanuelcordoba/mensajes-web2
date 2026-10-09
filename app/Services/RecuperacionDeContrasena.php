<?php

namespace App\Services;

use App\Mail\CodigoDeRecuperacion;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Recuperar la contraseña desde una de las dos apps, con un código al mail.
 *
 * ⚠️ **Sólo para los roles de las apps.** Las cuentas del panel —admin, empleado,
 * restringido, cliente_api— tienen su propia recuperación en la web, basada en tokens con
 * vencimiento, que es más segura que ésta. Dejarlas entrar acá no les daba nada y las
 * exponía: con el código viajando en la respuesta HTTP (ver abajo), cualquiera que supiera
 * el mail de un administrador le tomaba la cuenta. Es SEC-2 y ya está arreglado allá.
 *
 * ⚠️ **Una cuenta por buzón, y el sistema viejo tenía que aguantar varias.** Allá
 * `sendcode` y `reset` recorren TODAS las cuentas con ese email, porque en MongoDB conviven
 * pares que sólo difieren en mayúsculas (DATA-7): el código va a todas y la contraseña
 * nueva se escribe en todas, o la persona cambia la de una y sigue entrando con la otra.
 *
 * Acá ese estado **no puede existir**: `users.email` es UNIQUE, tiene
 * `CHECK (email = lower(btrim(email)))` y el modelo lo normaliza en su mutador. Copiar el
 * bucle sería defender algo que el esquema ya impide, y dejar en el código la idea de que
 * puede haber dos cuentas con el mismo mail. La búsqueda sigue ignorando mayúsculas porque
 * lo que la persona escribe en el teléfono no las respeta; lo que no hace falta es el plural.
 *
 * ⚠️ **Un email que no existe y un email del panel se ven igual desde afuera**: las dos
 * veces no hay usuario. Distinguirlos delataría cuáles son cuentas de administración.
 */
class RecuperacionDeContrasena
{
    /** Los dos roles que usan las apps. */
    public const ROLES = [Rol::CADETE, Rol::CLIENTE_APP];

    /** Seis dígitos, como en el sistema viejo. */
    public const MINIMO = 100000;

    public const MAXIMO = 999999;

    /**
     * La cuenta de una de las apps con ese email, o null.
     *
     * ⚠️ `conEmail` ignora mayúsculas y la columna sólo guarda minúsculas, así que a lo
     * sumo hay una. `first()` y no `sole()`: si algún día el CHECK se cayera, lo que no
     * queremos es que una recuperación de contraseña explote por eso.
     */
    public function cuenta(string $email): ?User
    {
        return User::query()
            ->conEmail($email)
            ->whereHas('rol', fn ($consulta) => $consulta->whereIn('rol', self::ROLES))
            ->first();
    }

    /**
     * Genera un código, lo escribe en todas las cuentas de ese buzón y lo manda por mail.
     *
     * Devuelve el código y la cuenta, o null si no hay a quién. Quien llama decide qué de
     * eso sale en la respuesta; ver `RecuperarContrasenaController::enviarCodigo()`.
     *
     * @return array{codigo: string, usuario: User}|null
     */
    public function pedirCodigo(string $email): ?array
    {
        $usuario = $this->cuenta($email);

        if ($usuario === null) {
            return null;
        }

        // ⚠️ `random_int` y no `rand`: el viejo usa `rand`, que es predecible a partir de
        // unas pocas salidas. Acá el código es lo único que protege una cuenta, así que
        // tiene que salir del generador criptográfico.
        $codigo = (string) random_int(self::MINIMO, self::MAXIMO);

        $usuario->codigo_de_verificacion = (int) $codigo;
        $usuario->save();

        Mail::to($usuario->email, $usuario->name)->queue(new CodigoDeRecuperacion($usuario, $codigo));

        return ['codigo' => $codigo, 'usuario' => $usuario];
    }

    /**
     * La cuenta de ese buzón si el código coincide, o null.
     *
     * ⚠️ La comparación la hace el modelo, con `hash_equals`. Antes era `!=` entre el entero
     * guardado y lo que mandara el cliente, y eso aceptaba el código con basura pegada atrás
     * y —lo grave— el número 0 contra una cuenta sin código pendiente, porque `null == 0`.
     * Ver SEC-3 y `User::codigoDeVerificacionCoincide()`.
     */
    public function cuentaConEseCodigo(string $email, mixed $codigo): ?User
    {
        $cuenta = $this->cuenta($email);

        if ($cuenta === null || ! $cuenta->codigoDeVerificacionCoincide($codigo)) {
            return null;
        }

        return $cuenta;
    }

    /**
     * Escribe la contraseña nueva y quema el código.
     *
     * ⚠️ `email_verified_at` se escribe acá, como en el sistema viejo: llegar hasta este
     * punto prueba que la persona leyó un mail en esa casilla. Es la única verificación de
     * email que las apps tienen.
     */
    public function cambiar(User $cuenta, string $contrasena): void
    {
        $cuenta->codigo_de_verificacion = null;
        $cuenta->email_verified_at = Carbon::now();
        // El cast `hashed` del modelo la encripta.
        $cuenta->password = $contrasena;
        $cuenta->save();
    }
}
