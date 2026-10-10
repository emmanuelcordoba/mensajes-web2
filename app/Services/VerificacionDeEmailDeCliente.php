<?php

namespace App\Services;

use App\Mail\CodigoDeVerificacion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * El código de seis dígitos con el que un cliente verifica su casilla al registrarse.
 *
 * ⚠️ **El código NO sale en ninguna respuesta, y el sistema viejo lo devuelve en `reenviar`**
 * —`makeVisible('codigo_de_verificacion')`—. Acá se puede cerrar y en la recuperación de
 * contraseña del cadete no: medido en la app, `reenviarCodigo()` **ignora la respuesta**, sólo
 * reinicia su contador de 60 segundos (`verify.page.ts`). Y `verificar()` manda el código al
 * SERVIDOR y muestra lo que el servidor conteste, así que la verificación ya era del lado
 * correcto. O sea que mandarlo era regalar la cuenta sin que nadie lo usara.
 *
 * ⚠️ **Y ahí estaba el agujero grande.** `verificar/{user}` y `verificar/{user}/reenviar` son
 * públicos y se identifican por el id en la URL. En MongoDB ese id era un ObjectId de 24
 * caracteres; en PostgreSQL es un entero correlativo. Con el código viajando en la respuesta
 * de `reenviar` —que además es un GET sin límite de intentos— alcanzaba con recorrer ids para
 * verificar la casilla de cualquier cuenta pendiente y llevarse un token válido. Enumerar era
 * impracticable allá y es trivial acá, así que portarlo tal cual habría convertido un riesgo
 * teórico en uno real.
 *
 * Lo que queda sosteniendo esas dos rutas: el código no se devuelve nunca, `verificar` tiene
 * el límite de cinco intentos por cuenta que ya tenía, y `reenviar` ahora también tiene uno
 * —allá no tiene ninguno y regenera el código en cada llamada, así que servía para llenarle
 * la casilla a cualquiera—.
 */
class VerificacionDeEmailDeCliente
{
    /** Seis dígitos, como en el sistema viejo. */
    public const MINIMO = 100000;

    public const MAXIMO = 999999;

    /**
     * Escribe un código nuevo en la cuenta y lo manda por mail.
     *
     * ⚠️ `random_int` y no `rand`: el viejo usa `rand`, que es predecible a partir de unas
     * pocas salidas, y acá el código es lo único que protege la cuenta hasta que se verifica.
     */
    public function mandarCodigo(User $cuenta, string $nombre): string
    {
        $codigo = (string) random_int(self::MINIMO, self::MAXIMO);

        $cuenta->codigo_de_verificacion = (int) $codigo;
        $cuenta->save();

        Mail::to($cuenta->email, $nombre)->queue(new CodigoDeVerificacion($cuenta, $codigo, $nombre));

        return $codigo;
    }

    /**
     * Marca la casilla como verificada y quema el código.
     *
     * ⚠️ La comparación la hace el modelo con `hash_equals`. Antes era `==`, y con el código
     * en null aceptaba el número 0: eso alcanzaba para llevarse un token válido sabiendo sólo
     * el id. Ver SEC-3 y `User::codigoDeVerificacionCoincide()`.
     */
    public function verificar(User $cuenta): void
    {
        $cuenta->codigo_de_verificacion = null;
        $cuenta->email_verified_at = Carbon::now();
        $cuenta->save();
    }
}
