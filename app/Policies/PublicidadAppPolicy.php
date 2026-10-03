<?php

namespace App\Policies;

use App\Models\PublicidadApp;
use App\Models\Rol;
use App\Models\User;

/**
 * Quién toca el carrusel de la app.
 *
 * El mismo recorte que el sistema viejo hacía con las rutas: ver, crear y editar es de
 * empleado y admin, y **borrar suma `rol:admin`** —allá `ajax.publicidadapp.destroy`
 * llevaba ese middleware, junto con el borrado de usuarios—.
 */
class PublicidadAppPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PublicidadApp $publicidad): bool
    {
        return $this->viewAny($user);
    }

    /**
     * ⚠️ Sin el modelo en la firma: el listado pregunta si se puede borrar **alguna**,
     * para decidir si dibujar la columna. Con un modelo haría falta uno de mentira.
     */
    public function delete(User $user, ?PublicidadApp $publicidad = null): bool
    {
        return $user->tieneRol(Rol::ADMIN);
    }
}
