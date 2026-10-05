<?php

namespace App\Policies;

use App\Models\Cadete;
use App\Models\Rol;
use App\Models\User;
use App\Services\BajaDeCadete;
use App\Services\CambioDeModalidadDeCobranza;
use Illuminate\Auth\Access\Response;

/**
 * Quién toca qué cadete desde el panel.
 *
 * El mismo recorte que el sistema viejo hacía con las rutas: el grupo deja entrar a
 * empleado y admin, y la baja suma `rol:admin` encima. Ver SEC-6: la baja vivía en el
 * grupo ajax, que hasta el 2026-08-20 incluía al rol `restringido`, así que una cuenta de
 * comercio podía dar de baja cadetes.
 *
 * ⚠️ Tres de los cinco métodos devuelven `Response` y no `bool`, para poder decir POR QUÉ
 * no. Las tres negativas se arreglan —cerrando la cobranza, desbloqueando, o reponiendo la
 * cuenta—, y para arreglarlas hay que leerlas. El listado pregunta exactamente lo mismo
 * que el controlador aplica, así que no se dibuja un botón que iba a fallar: en el panel
 * viejo el de cambiar estado estaba siempre, y sobre un cadete bloqueado no hacía nada ni
 * avisaba.
 */
class CadetePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Cadete $cadete): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Mover la cola entera: mandar a uno al final, o vaciarla.
     *
     * ⚠️ Es otra pregunta que `cambiarEstado`, aunque vaciar la cola termine cambiando
     * estados. `cambiarEstado` niega sobre un cadete bloqueado, porque de lo que habla es
     * de **meterlo** en la cola; sacar de la cola a alguien bloqueado tiene que poder
     * hacerse siempre. Las razones de verdad —que no haya nadie, que ese cadete no esté en
     * la cola— no son de rol y viven en `ColaDeCadetes`.
     */
    public function ordenarCola(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Ponerlo en la cola o sacarlo.
     *
     * ⚠️ Un cadete bloqueado no entra a la cola, y es la condición que el panel viejo
     * comprobaba en silencio: `if($cadete->user->bloqueado == null)`, y si estaba
     * bloqueado el controlador redirigía sin hacer nada y sin decir nada.
     *
     * Sin cuenta activa se permite igual: la cola de la web no la usa —el bloqueo vive en
     * `users` y acá no hay nada que leer—, y es la central la que lo pone y lo saca.
     */
    public function cambiarEstado(User $user, Cadete $cadete): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $cadete->user?->estaBloqueado() === true
            ? Response::deny('El móvil '.$cadete->numero_movil.' está bloqueado. Desbloquealo antes de ponerlo en la cola.')
            : Response::allow();
    }

    /**
     * Bloquearlo y desbloquearlo: la misma habilidad, porque es el mismo campo.
     *
     * ⚠️ El bloqueo se escribe en `users`, no en `cadetes`, así que sin cuenta activa no
     * hay dónde escribirlo. Pasa aunque `cadetes.user_id` sea NOT NULL: `User` usa
     * SoftDeletes y la relación no devuelve una cuenta dada de baja.
     */
    public function bloquear(User $user, Cadete $cadete): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $cadete->user === null
            ? Response::deny('El móvil '.$cadete->numero_movil.' no tiene cuenta activa, y el bloqueo se guarda en la cuenta.')
            : Response::allow();
    }

    /** Mirar las pantallas de cobranzas. El mismo recorte que el resto del grupo. */
    public function verCobranzas(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Escribir en la cobranza: abrir un ciclo, registrar un pago, extender un vencimiento,
     * cambiar las cuotas generales.
     *
     * ⚠️ El cadete es opcional porque las cuotas generales no son de nadie: son dos filas,
     * una por vehículo. La habilidad es la misma —el mismo recorte que el resto del grupo—
     * y tenerla dos veces sólo invitaría a que se separaran.
     */
    public function cobrar(User $user, ?Cadete $cadete = null): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Cargarle saldo.
     *
     * ⚠️ Sólo a quien cobra por saldo. Cargarle saldo a un cadete de modalidad semanal
     * escribe un movimiento y un saldo que ninguna pantalla vuelve a mostrar, porque el
     * listado de saldo no lo lista: plata que entra y desaparece.
     */
    public function cargarSaldo(User $user, Cadete $cadete): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        if (! $cadete->cobraPorSaldo()) {
            return Response::deny('El móvil '.$cadete->numero_movil.' cobra por semana: para cargarle saldo hay que pasarlo a esa modalidad.');
        }

        // Sin el porcentaje configurado no se sabe cuánto es una carga que sirva, así que
        // no se ofrece ninguna. Es un sistema a medio configurar, no un caso de uso.
        return Cadete::saldoMinimoSiSePuede() === null
            ? Response::deny('Falta configurar el porcentaje de saldo, así que no se sabe cuál es la carga mínima.')
            : Response::allow();
    }

    /**
     * Pasarlo de una modalidad de cobranza a la otra.
     *
     * La regla —no con plata en el medio— vive en `CambioDeModalidadDeCobranza`, que es
     * la que aplica el servicio antes de escribir. Acá se pregunta la misma.
     */
    public function cambiarModalidad(User $user, Cadete $cadete): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        $motivo = CambioDeModalidadDeCobranza::porQueNo($cadete);

        return $motivo === null
            ? Response::allow()
            : Response::deny($motivo);
    }

    /**
     * Darlo de baja.
     *
     * Son dos negativas distintas: no tener el rol, y tener la cobranza abierta. La regla
     * de negocio vive en `BajaDeCadete::porQueNo()`, que es la que aplica el servicio
     * antes de escribir; acá se pregunta la misma.
     */
    public function delete(User $user, Cadete $cadete): Response
    {
        // ⚠️ Sin mensaje, y es a propósito: el listado muestra el motivo de la negativa en
        // lugar del botón, y «sólo un administrador» repetido en las diez filas no le dice
        // nada a un empleado que ya sabe que no puede. Los motivos que sí se muestran son
        // los que esa persona puede ir a arreglar.
        if (! $user->tieneRol(Rol::ADMIN)) {
            return Response::deny();
        }

        $motivo = BajaDeCadete::porQueNo($cadete);

        return $motivo === null
            ? Response::allow()
            : Response::deny($motivo);
    }
}
