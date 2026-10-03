<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use App\Services\BajaDeCliente;
use Illuminate\Auth\Access\Response;

/**
 * Quién toca qué cliente desde el panel.
 *
 * El mismo recorte que el sistema viejo hacía con las rutas: el grupo deja entrar a
 * empleado y admin, y `clientes.destroy` suma `rol:admin` encima, así que un empleado
 * recibe 403 al borrar. Dar de baja un cliente se lleva sus pedidos y su cuenta de la
 * app, y eso no queda al alcance de cualquier rol. Ver SEC-4.
 */
class ClientePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $this->viewAny($user);
    }

    /**
     * ⚠️ Devuelve una `Response` y no un bool para poder decir POR QUÉ no.
     *
     * Son dos negativas distintas: no tener el rol, y que el cliente esté asignado a una
     * cuenta de panel (PANEL-6). La segunda se arregla, y para arreglarla hay que
     * leerla; un 403 a secas no dice nada.
     *
     * La regla de negocio vive en `BajaDeCliente::porQueNo()`, que es la que aplica el
     * servicio antes de escribir. Acá se pregunta la misma.
     */
    public function delete(User $user, Cliente $cliente): Response
    {
        if (! $user->tieneRol(Rol::ADMIN)) {
            return Response::deny('Sólo un administrador da de baja un cliente.');
        }

        $motivo = BajaDeCliente::porQueNo($cliente);

        return $motivo === null
            ? Response::allow()
            : Response::deny($motivo);
    }
}
