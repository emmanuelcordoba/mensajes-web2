<?php

namespace App\Policies;

use App\Models\Postulacion;
use App\Models\Rol;
use App\Models\User;
use App\Services\ContratacionDePostulacion;
use Illuminate\Auth\Access\Response;

/**
 * Quién toca qué postulación desde el panel.
 *
 * El mismo recorte que el sistema viejo hacía con las rutas: el grupo deja entrar a
 * empleado y admin, y nada más. Contratar y rechazar no llevaban `rol:admin` encima, así
 * que acá tampoco.
 *
 * ⚠️ Rechazar sí cambió de peso. En el sistema viejo era un `forceDelete()`: borraba la
 * postulación **para siempre**, con los documentos de identidad de esa persona, que no
 * están en ningún otro respaldo (DATA-9). Acá es una baja lógica y los archivos quedan, así
 * que dejarlo al alcance de un empleado ya no es lo mismo que antes.
 */
class PostulacionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    public function view(User $user, Postulacion $postulacion): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Contratar: convertirla en un cadete.
     *
     * La regla vive en `ContratacionDePostulacion::porQueNo()`, que es la que aplica el
     * servicio antes de escribir. Acá se pregunta la misma, para que la pantalla muestre
     * el motivo en lugar de un botón que iba a fallar.
     */
    public function contratar(User $user, Postulacion $postulacion): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        $motivo = ContratacionDePostulacion::porQueNo($postulacion);

        return $motivo === null
            ? Response::allow()
            : Response::deny($motivo);
    }

    /**
     * Rechazar.
     *
     * ⚠️ Una postulación ya contratada no se rechaza: es el legajo de un cadete que está
     * trabajando, y sacarlo de la lista lo dejaría sin sus documentos a la vista. Para que
     * se vaya hay que dar de baja al cadete, y de eso se encarga `BajaDeCadete`, que se la
     * lleva con él.
     */
    public function delete(User $user, Postulacion $postulacion): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        return $postulacion->fueContratada()
            ? Response::deny('Esta postulación ya es el legajo de un cadete: para que se vaya hay que dar de baja al cadete.')
            : Response::allow();
    }
}
