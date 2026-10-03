<?php

namespace App\Policies;

use App\Models\Rol;
use App\Models\User;

/**
 * Quién toca qué cuenta desde el panel.
 *
 * ⚠️ `update()` y `delete()` miran al usuario de destino, no sólo al rol de quien pide.
 * La cuenta de un administrador sólo la toca otro administrador: si un empleado pudiera
 * cambiarle la contraseña o el email, todo lo que el panel reserva a admin quedaría a un
 * paso de él. Eso es SEC-9, y la regla vive en `User::puedeAdministrarA()` para que la
 * lean el controlador y el listado sin copiarla.
 *
 * ⚠️ `delete()` es sólo de administradores, como en el sistema viejo —la ruta
 * `ajax.users.eliminar` lleva `rol:admin`—. Pero allá el listado dibujaba el botón de
 * borrar **para todos**, sin preguntar nada: un empleado lo apretaba y recibía 403. Acá
 * el listado pregunta esta política antes de dibujarlo, que es la diferencia entre un
 * botón que no está y uno que falla.
 */
class UserPolicy
{
    /** Entran los dos roles de oficina, como el grupo de rutas del sistema viejo. */
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Sólo un administrador crea otro administrador. Ver SEC-4.
     *
     * Vive acá y no sólo en el validador porque la pregunta la hace también el
     * formulario, que necesita saber si ofrecer ese rol en el desplegable.
     */
    public function asignarRolDeAdmin(User $user): bool
    {
        return $user->tieneRol(Rol::ADMIN);
    }

    public function update(User $user, User $otro): bool
    {
        return $this->viewAny($user) && $user->puedeAdministrarA($otro);
    }

    public function delete(User $user, User $otro): bool
    {
        // Nadie se borra a sí mismo: el siguiente clic sería contra una cuenta que ya no
        // entra. El sistema viejo no lo impedía.
        if ($user->id === $otro->id) {
            return false;
        }

        return $user->tieneRol(Rol::ADMIN) && $user->puedeAdministrarA($otro);
    }
}
