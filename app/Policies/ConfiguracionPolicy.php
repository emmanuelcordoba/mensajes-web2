<?php

namespace App\Policies;

use App\Models\Configuracion;
use App\Models\Rol;
use App\Models\User;

/**
 * Quién toca los parámetros del negocio.
 *
 * El mismo recorte que el sistema viejo hacía con las rutas: el grupo de administración
 * es de empleado y admin, y la configuración entra ahí. No hay un `rol:admin` encima,
 * porque cambiar el costo por kilómetro es trabajo de oficina, no de sistemas.
 *
 * ⚠️ Pero no se borra ni se crea: son ocho filas que el código busca por nombre y espera
 * encontrar. Por eso esta política no tiene `create` ni `delete`, y la tabla tampoco
 * tiene `deleted_at`.
 */
class ConfiguracionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function update(User $user, Configuracion $configuracion): bool
    {
        return $this->viewAny($user);
    }
}
