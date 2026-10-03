<?php

namespace App\Policies;

use App\Models\HorarioAtencion;
use App\Models\Rol;
use App\Models\User;

/**
 * Quién toca el horario de atención.
 *
 * Es trabajo de oficina, como las configuraciones: empleado y admin. Y como ahí, no hay
 * `create` ni `delete` — es una sola fila, y la aplicación la busca esperando encontrarla.
 */
class HorarioAtencionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function update(User $user, HorarioAtencion $horario): bool
    {
        return $this->viewAny($user);
    }
}
