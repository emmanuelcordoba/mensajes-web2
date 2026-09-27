<?php

namespace App\Policies;

use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;

/**
 * Quién llega a qué pedido desde el panel.
 *
 * Es la mitad que el middleware de rol no cubre. `VerificarRol` decide quién entra a
 * la ruta; esto decide **a qué fila llega**, y sin eso un comercio con rol
 * `restringido` veía, imprimía y borraba los pedidos de cualquier otro mandando el
 * id. Ver SEC-7.
 *
 * La regla vive en `User::alcanzaPedido()` porque también la necesitan los listados,
 * que filtran en la consulta en vez de preguntar fila por fila.
 */
class PedidoPolicy
{
    /** Cualquiera de los tres roles del panel puede ver el listado. */
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO]);
    }

    public function view(User $user, Pedido $pedido): bool
    {
        return $this->viewAny($user) && $user->alcanzaPedido($pedido);
    }

    /** Un comercio no carga pedidos desde el panel: los carga desde la app. */
    public function create(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function update(User $user, Pedido $pedido): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO])
            || ($user->tieneRol(Rol::RESTRINGIDO) && $user->alcanzaPedido($pedido));
    }

    /**
     * ⚠️ Borrar sí lo puede un comercio, sobre sus propios pedidos: es lo que hace
     * hoy el sistema viejo y no se cambia sin decidirlo. Lo que no podía es borrar
     * los de otro, que era el agujero.
     */
    public function delete(User $user, Pedido $pedido): bool
    {
        return $this->update($user, $pedido);
    }
}
