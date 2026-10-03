<?php

namespace App\Http\Middleware;

use App\Models\Configuracion;
use App\Models\Rol;
use App\Models\User;
use App\Support\Navegacion;
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
            // El menú del panel, ya recortado por rol: lo que un comercio no
            // puede usar no viaja. Ver App\Support\Navegacion.
            'navegacion' => Navegacion::para($request->user()),
            // El selector de la barra de arriba. Ver `tiempoDeDemora()`.
            'tiempoDeDemora' => self::tiempoDeDemora($request->user()),
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
     * El tiempo de demora que la barra de arriba deja cambiar.
     *
     * Es una fila de `configuraciones` que la oficina toca varias veces por día —cuando
     * hay demora, cuando se normaliza—, así que el sistema viejo la puso en la barra en
     * lugar de hacer abrir una pantalla. Acá igual.
     *
     * ⚠️ Es una consulta por página, pero **sólo para quien puede cambiarlo**: un comercio
     * no la hace. Es una fila buscada por una columna UNIQUE; si alguna vez pesa, lo que
     * corresponde es cachearla, no sacarla de acá.
     *
     * @return array{valor: string|null, opciones: list<string>}|null
     */
    private static function tiempoDeDemora(?User $user): ?array
    {
        if ($user === null || ! $user->tieneRol([Rol::ADMIN, Rol::EMPLEADO])) {
            return null;
        }

        $configuracion = Configuracion::query()
            ->where('nombre', 'TIEMPO_DE_DEMORA')
            ->first(['id', 'valor']);

        if ($configuracion === null) {
            return null;
        }

        return [
            'id' => $configuracion->id,
            'valor' => $configuracion->valor,
            'opciones' => Configuracion::TIEMPOS_DE_DEMORA,
        ];
    }
}
