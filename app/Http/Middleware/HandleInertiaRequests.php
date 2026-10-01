<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
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
}
