<?php

namespace App\Policies;

use App\Models\Rol;
use App\Models\User;

/**
 * Quién usa el chat con los cadetes.
 *
 * El mismo recorte que el sistema viejo hacía con la ruta: admin y empleado. Un comercio
 * no habla con los cadetes —ni siquiera ve la entrada en el menú—.
 *
 * Son dos habilidades y no una: leer la conversación y escribir en ella son cosas
 * distintas, aunque hoy las tenga la misma gente.
 */
class MensajePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
