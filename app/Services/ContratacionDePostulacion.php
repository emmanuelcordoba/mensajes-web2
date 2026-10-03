<?php

namespace App\Services;

use App\Models\Cadete;
use App\Models\Postulacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Contratar a alguien que se postuló: convertir la postulación en un cadete.
 *
 * Es el `contratar` del panel viejo, con lo que le faltaba. No escribe las filas: eso ya
 * lo hace `AltaDeCadete`, que es el mismo camino por el que entra un cadete cargado a
 * mano. Acá se decide si se puede, se arman los datos desde la postulación y se la deja
 * apuntando al cadete que salió de ella.
 *
 * ## Lo que el panel viejo no preguntaba
 *
 * ⚠️ **No miraba si la postulación ya había sido contratada.** Dos envíos del mismo
 * formulario creaban dos cadetes —con dos cuentas— a partir de la misma persona, y
 * `cadete_id` terminaba apuntando al segundo: el primero quedaba suelto, con su móvil
 * ocupado y sin legajo.
 *
 * ⚠️ **No miraba el email.** En MongoDB no había índice único, así que creaba una segunda
 * cuenta con el mismo email y nadie se enteraba hasta que alguien intentaba entrar. Acá
 * `users.email` es UNIQUE: sin esta comprobación, contratar respondería 500 contra el
 * índice, que es el peor momento para enterarse.
 *
 * Sí miraba el DNI y los documentos, y las dos preguntas siguen acá.
 */
class ContratacionDePostulacion
{
    /**
     * Por qué no se puede contratar, o null si se puede.
     *
     * Las cuatro negativas se arreglan, y el listado y la pantalla muestran el motivo en
     * lugar de un botón que iba a fallar.
     */
    public static function porQueNo(Postulacion $postulacion): ?string
    {
        if ($postulacion->fueContratada()) {
            $movil = $postulacion->cadete?->numero_movil;

            return 'Esta postulación ya fue contratada'
                .($movil === null ? '.' : ': es el móvil '.$movil.'.');
        }

        $faltan = DocumentosDePostulacion::faltanParaContratar($postulacion);

        if ($faltan !== []) {
            return 'Faltan los documentos: '.implode(', ', array_map(
                static fn (string $tipo): string => str_replace('_', ' ', $tipo),
                $faltan,
            )).'. Sin ellos no se puede contratar.';
        }

        // El DNI se comprobó al postularse, pero hasta la contratación alguien pudo
        // cargarlo en otro cadete. `withTrashed` porque el UNIQUE de la columna cuenta a
        // los dados de baja.
        $deOtroCadete = Cadete::withTrashed()->where('dni', $postulacion->dni)->exists();

        if ($deOtroCadete) {
            return 'El DNI de esta postulación ya lo tiene otro cadete, aunque esté dado de baja.';
        }

        // Lo mismo con el email, que es el que va a usar la cuenta de la app.
        $deOtraCuenta = User::withTrashed()->where('email', $postulacion->email)->exists();

        if ($deOtraCuenta) {
            return 'El email de esta postulación ya lo tiene otra cuenta, aunque esté dada de baja.';
        }

        return null;
    }

    /**
     * Contrata, y devuelve la contraseña que hay que pasarle a la persona.
     *
     * ⚠️ La contraseña se muestra UNA vez y no se vuelve a ver: lo que queda guardado es
     * su hash. Es lo mismo que hacía el panel viejo, salvo que allá era un PIN de seis
     * dígitos —`random_int(100000, 999999)`— y acá son doce caracteres, la misma exigencia
     * que para cualquier otra cuenta del sistema. Es la misma tabla `users` y el mismo
     * login; si la app necesita un PIN, eso es un mecanismo aparte y no una contraseña más
     * corta para todos.
     *
     * ⚠️ Acá NO se mueve ningún archivo. El panel viejo, al contratar, decodificaba el
     * base64 de los tres documentos y recién entonces los escribía a disco. Ya son
     * archivos desde que se cargan.
     */
    public function contratar(Postulacion $postulacion, int $numeroMovil, ?User $autor = null): string
    {
        $motivo = self::porQueNo($postulacion);

        if ($motivo !== null) {
            // Llegar acá significa que la pantalla ofreció algo que la regla niega.
            throw new RuntimeException($motivo);
        }

        $password = Str::password(12, symbols: false);

        DB::transaction(function () use ($postulacion, $numeroMovil, $password): void {
            $cadete = (new AltaDeCadete)->alta([
                'nombres' => $postulacion->nombres,
                'apellidos' => $postulacion->apellidos,
                'direccion' => $postulacion->direccion,
                'telefono' => $postulacion->telefono,
                'fecha_nacimiento' => $postulacion->fecha_nacimiento,
                'tipo_vehiculo' => $postulacion->tipo_vehiculo,
                'dni' => $postulacion->dni,
                'numero_movil' => $numeroMovil,
                'email' => $postulacion->email,
                'password' => $password,
            ]);

            // La postulación pasa a ser el legajo de ese cadete.
            $postulacion->cadete()->associate($cadete);
            $postulacion->save();
        });

        Log::info('Postulación contratada desde /panel', [
            'postulacion_id' => $postulacion->id,
            'cadete_id' => $postulacion->cadete_id,
            'numero_movil' => $numeroMovil,
            'autor' => $autor?->email,
        ]);

        return $password;
    }
}
