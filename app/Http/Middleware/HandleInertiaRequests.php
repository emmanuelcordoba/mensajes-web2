<?php

namespace App\Http\Middleware;

use App\Models\Mensaje;
use App\Models\Rol;
use App\Models\User;
use App\Support\Navegacion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => self::usuario($request->user()),
            ],
            // El menú del panel, ya recortado por rol: lo que un comercio no
            // puede usar no viaja. Ver App\Support\Navegacion.
            'navegacion' => Navegacion::para($request->user()),
            // El globito del sobre de la barra de arriba. Ver `mensajesSinLeer()`.
            //
            // ⚠️ **Una función y no el número, y hace falta.** Las props compartidas se
            // arman ANTES de que corra el controlador —`Inertia\Middleware::handle()`
            // comparte y recién después llama a `$next`—, así que contando acá el número
            // sale de antes de lo que esa pantalla hizo. Se nota justo donde importa: abrir
            // una conversación la marca leída, y el globito seguía mostrando el número
            // viejo hasta el refresco siguiente. Inertia resuelve las funciones al armar la
            // respuesta, que es después del controlador, y ahí el número ya es el de ahora.
            'mensajesSinLeer' => fn (): ?int => self::mensajesSinLeer($request->user()),
            // ⚠️ El mensaje que dejan los `->with('success', ...)` de los controladores.
            // Hasta ahora no lo compartía nadie, así que se escribía en la sesión y no lo
            // leía nadie: todas las pantallas del panel contestaban en silencio —el panel
            // viejo mostraba estas dos alertas arriba de cada vista—. Se muestra con
            // `sonner`, que ya estaba montado en app.tsx sin que nada le mandara nada.
            //
            // ⚠️ **`always()`, y es lo que arregla el aviso repetido.** Las pantallas que
            // se refrescan solas lo hacen con `usePoll(..., { only: [...] })`, y en una
            // recarga parcial Inertia manda SÓLO las props pedidas y deja las demás como
            // estaban. O sea que «Pedido 957329 asignado al móvil 1615» se quedaba pegado
            // en las props y el cartel volvía a salir en cada refresco, uno atrás del
            // otro, para siempre. Con `always()` el aviso viaja también en las parciales,
            // ya vacío —el mensaje de sesión vale para un solo pedido—, y se borra el de
            // antes. Vale para las cuatro pantallas que se refrescan: Inicio, la cola, el
            // mapa y los mensajes.
            'aviso' => Inertia::always([
                'success' => $request->session()->get('success'),
                'danger' => $request->session()->get('danger'),
            ]),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Lo que el frontend sabe de quien está mirando.
     *
     * ⚠️ UNA LISTA EXPLÍCITA Y NO EL MODELO. Antes se compartía `$request->user()`
     * entero, así que en **cada página** —el listado de pedidos, el perfil, la
     * portada— viajaban `bloqueado`, `bloqueado_mensaje`, `codigo_generado_at`,
     * `rol_id`, `cliente_restringido_id` y `deleted_at`. Ninguna de las seis la lee
     * nadie. `codigo_de_verificacion` sí estaba oculto —SEC-10—, pero el resto
     * salía por omisión, que es la misma forma de SEC-14: el servidor manda todo lo
     * que tiene y confía en que el cliente no mire.
     *
     * Con una lista explícita, agregar una columna a `users` no la publica sola.
     * Esto es lo que el frontend lee hoy, comprobado uno por uno:
     *
     * | Campo               | Quién lo usa                                   |
     * |---------------------|------------------------------------------------|
     * | `name`              | el menú de la topbar y el perfil               |
     * | `email`             | lo mismo                                       |
     * | `email_verified_at` | el aviso de «verificá tu email» en el perfil   |
     * | `id`                | nadie todavía; es la identidad y no es secreto |
     *
     * Y uno que falta y hace falta: **`rol`**, el nombre del rol y no `rol_id`. El
     * panel tiene que poder esconder lo que un `restringido` no puede usar, y un
     * número de fila no le sirve para nada. Viene de la relación, que en las rutas
     * del panel ya está cargada porque la miró `VerificarRol`.
     *
     * ⚠️ `avatar` no está, y los componentes lo leen: `user-info.tsx` y
     * `app-header.tsx` hacen `src={user.avatar}`. Nunca lo mandó nadie, así que el
     * avatar siempre cayó en las iniciales. Queda igual a propósito: las fotos
     * existen —`user_fotos`, 725 migradas— y servirlas necesita el endpoint con URL
     * firmada que todavía no está. Cuando esté, se agrega acá.
     *
     * @return array<string, mixed>|null
     */
    private static function usuario(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'rol' => $user->rol?->rol,
        ];
    }

    /**
     * Cuántos mensajes esperan a la central, para el globito del sobre.
     *
     * ⚠️ Viaja en las props compartidas y no lo pide el frontend al dibujarse, para que el
     * número esté desde la primera pintura: si no, el globito aparecería un instante
     * después de cada página. De ahí en más lo refresca el propio componente cada diez
     * segundos, como el `setInterval` del sistema viejo.
     *
     * ⚠️ **null y no 0 para quien no puede mirar el chat.** Son dos cosas distintas —«no te
     * corresponde» y «no hay ninguno»— y el frontend las dibuja distinto: con null no hay
     * sobre. Un comercio no lo recibe, y tampoco hace la consulta. El sistema viejo esconde
     * el sobre con un `@if(tieneRol([...]))` en la barra.
     *
     * La regla de qué se cuenta vive en el modelo: ver `Mensaje::cuantosEsperanALaCentral()`.
     */
    private static function mensajesSinLeer(?User $user): ?int
    {
        if ($user === null || ! $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO])) {
            return null;
        }

        return Mensaje::cuantosEsperanALaCentral();
    }
}
