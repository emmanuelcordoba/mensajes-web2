<?php

namespace App\Policies;

use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    /**
     * ⚠️ NIEGA CON 404 Y NO CON 403, a propósito. Un comercio puede probar ids, y un
     * 403 le confirma que ese pedido existe; un 404 no le dice nada. Es la decisión
     * que ya tomó el sistema viejo —`abort_unless(..., 404)` en `PedidoController`—
     * y se conserva. Para eso el método devuelve una `Response` y no un bool.
     *
     * Vale para los dos motivos: no tener rol de panel y no alcanzar la fila. Si
     * distinguiera, la diferencia entre las dos respuestas volvería a ser la
     * confirmación que se quiere evitar.
     */
    public function view(User $user, Pedido $pedido): Response
    {
        return $this->viewAny($user) && $user->alcanzaPedido($pedido)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Las estadísticas son de toda la empresa, así que no las ve un comercio.
     *
     * ⚠️ **No alcanza con `viewAny`.** Un comercio con rol `restringido` sí puede ver el
     * listado de pedidos —los suyos—, y esta pantalla cuenta los de todos: son dos
     * preguntas distintas y por eso son dos métodos. En el menú viejo la entrada estaba
     * adentro del `@if` de oficina y no había ninguna regla del lado del servidor; acá la
     * regla vive acá, el menú la repite con su lista de roles, y hay un test que compara
     * las dos respuestas rol por rol.
     */
    public function verEstadisticas(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
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
     * Ver la lista de espera de Inicio: sólo la oficina.
     *
     * ⚠️ Es una regla de pedidos y por eso vive acá. La primera versión de Inicio dibujaba
     * esta columna cuando el usuario podía ver **cadetes** —`viewAny` de CadetePolicy—, que
     * da la misma respuesta pero por el motivo equivocado: el día que cambie quién puede ver
     * cadetes, cambiaría también quién ve los pedidos de la oficina, y nadie lo habría
     * decidido.
     *
     * En el panel viejo la columna está adentro del `@if(tieneRol(['admin','empleado']))` de
     * `panel.blade.php:273` y no hay ninguna regla del lado del servidor. Esta es esa misma
     * lista de roles, escrita donde se puede preguntar.
     */
    public function verListaDeEspera(User $user): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    /**
     * Darle el cadete a un pedido, desde la lista de espera: sólo la oficina.
     *
     * ⚠️ **En el sistema viejo esto no lo preguntaba nadie.** `ajax/pedidos/actualizar`
     * vivía en el grupo `rol:empleado,restringido,admin` y adentro no comprobaba nada: ni
     * el rol ni `alcanzaPedido`. O sea que una cuenta de comercio podía editar CUALQUIER
     * pedido —ni siquiera los suyos— y asignarle un cadete mandando el id. Es el mismo
     * patrón de SEC-6 y SEC-7, en el único endpoint de los tres que había quedado.
     *
     * La pantalla nunca se lo ofreció a un comercio: la lista de espera es de oficina. Lo
     * que faltaba era que el servidor lo dijera.
     */
    public function asignarCadete(User $user, Pedido $pedido): bool
    {
        return $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO]);
    }

    /**
     * ⚠️ Borrar sí lo puede un comercio, sobre sus propios pedidos: es lo que hace
     * hoy el sistema viejo y no se cambia sin decidirlo. Lo que no podía es borrar
     * los de otro, que era el agujero.
     */
    public function delete(User $user, Pedido $pedido): Response
    {
        // ⚠️ 404 y no 403, por lo mismo que `view()`: un comercio puede probar ids, y un 403
        // le confirma que ese pedido existe. Es además lo que contesta el sistema viejo
        // —`errorResponse('No se encontró el pedido solicitado.', 404)`—, que para borrar sí
        // comprobaba el alcance, al revés que para asignar.
        return $this->update($user, $pedido)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
