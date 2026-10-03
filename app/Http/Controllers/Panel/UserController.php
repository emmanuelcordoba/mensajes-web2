<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\GuardarUsuarioRequest;
use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las cuentas del panel.
 *
 * Es la pantalla de Usuarios del menú lateral viejo. Muestra sólo los cuatro roles que
 * usan el panel o la API —`Rol::DEL_PANEL`—, como hacía aquélla: las 1.832 cuentas de
 * cadete y las 7.342 de la app no se administran desde acá.
 *
 * ⚠️ Lo que viaja al frontend es una lista explícita de campos y no el modelo. `users`
 * tiene `codigo_de_verificacion` —con el que se le cambia la contraseña a cualquiera,
 * SEC-10—, el secreto del segundo factor y el hash. Mandar el modelo entero es la forma
 * de SEC-14: el servidor manda todo lo que tiene y confía en que el cliente no mire.
 *
 * ⚠️ Quién puede qué lo decide `UserPolicy`, y el listado pregunta lo mismo que el
 * controlador aplica. En el sistema viejo el botón de borrar se dibujaba para todos y un
 * empleado recibía 403 al apretarlo.
 */
class UserController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $usuarios = User::query()
            ->with(['rol:id,rol,display_rol', 'clienteRestringido:id,numero,nombre_mostrado'])
            ->whereHas('rol', fn (Builder $rol) => $rol->whereIn('rol', Rol::DEL_PANEL))
            ->orderBy('name')
            ->paginate(10)
            ->through(fn (User $usuario): array => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'iniciales' => $usuario->iniciales,
                'color' => $usuario->color,
                'rol' => $usuario->rol?->display_rol,
                'comercio' => $usuario->clienteRestringido?->nombre_mostrado,
                // La misma pregunta que hace el controlador antes de escribir.
                'puede_editarse' => request()->user()?->can('update', $usuario) ?? false,
                'puede_borrarse' => request()->user()?->can('delete', $usuario) ?? false,
            ]);

        return Inertia::render('users/index', [
            'usuarios' => $usuarios,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('users/create', [
            'roles' => $this->roles(),
        ]);
    }

    public function store(GuardarUsuarioRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $usuario = new User;
        $this->guardar($usuario, $request);

        return to_route('panel.users.index')
            ->with('success', 'Cuenta creada.');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('users/edit', [
            'roles' => $this->roles(),
            'usuario' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'iniciales' => $user->iniciales,
                'color' => $user->color,
                'rol_id' => $user->rol_id,
                'cliente_restringido_id' => $user->cliente_restringido_id,
                'comercio' => $user->clienteRestringido?->nombre_mostrado,
            ],
        ]);
    }

    public function update(GuardarUsuarioRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->guardar($user, $request);

        return to_route('panel.users.index')
            ->with('success', 'Cuenta actualizada.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        // Baja lógica, como todo en el sistema. La cuenta deja de poder entrar aunque
        // conserve una sesión: el proveedor de Eloquent no devuelve filas borradas.
        $user->delete();

        return to_route('panel.users.index')
            ->with('success', 'Cuenta dada de baja.');
    }

    /**
     * Escribe los campos que esta pantalla edita, uno por uno.
     *
     * ⚠️ Asignación explícita y no `fill($request->all())`. En el sistema viejo la
     * edición llenaba el modelo con todo lo que viniera en el formulario, y como `rol_id`
     * y `password` están en el `fillable` de User alcanzaba con agregar un campo al
     * cuerpo para volverse administrador o para guardar una contraseña sin hashear. Ver
     * SEC-4.
     */
    protected function guardar(User $usuario, GuardarUsuarioRequest $request): void
    {
        $usuario->name = $request->string('name')->value();
        $usuario->email = $request->string('email')->value();
        $usuario->rol_id = $request->integer('rol_id');
        $usuario->iniciales = $request->input('iniciales');
        $usuario->color = $request->input('color');

        // Sólo un comercio mira un cliente; en los demás roles la columna vuelve a null,
        // así que cambiar de rol no deja el vínculo viejo colgado.
        $usuario->cliente_restringido_id = $request->esComercio()
            ? $request->integer('cliente_restringido_id')
            : null;

        // En blanco significa «no la toques». El cast `hashed` del modelo la encripta.
        if ($request->filled('password')) {
            $usuario->password = $request->string('password')->value();
        }

        $usuario->save();
    }

    /**
     * Los roles que esta pantalla ofrece.
     *
     * ⚠️ El de administrador sólo aparece si quien mira puede nombrarlo. No es
     * decoración: el validador lo rechaza igual —SEC-4—, pero ofrecer una opción que
     * después se niega es el botón que siempre falla.
     *
     * @return array<int, array{id: int, nombre: string, es_comercio: bool}>
     */
    protected function roles(): array
    {
        $puedeNombrarAdmin = request()->user()?->can('asignarRolDeAdmin', User::class) ?? false;

        return Rol::query()
            ->whereIn('rol', Rol::DEL_PANEL)
            ->when(! $puedeNombrarAdmin, fn (Builder $q) => $q->where('rol', '!=', Rol::ADMIN))
            ->orderBy('display_rol')
            ->get()
            ->map(fn (Rol $rol): array => [
                'id' => $rol->id,
                'nombre' => $rol->display_rol,
                'es_comercio' => $rol->rol === Rol::RESTRINGIDO,
            ])
            ->all();
    }

    /**
     * Los comercios que se pueden asignar a una cuenta `restringido`.
     *
     * Se buscan contra el servidor y no se mandan los 11.057 en la página: el formulario
     * pide los que coinciden con lo que se escribe. Es público porque es su propia ruta.
     */
    public function comercios(): JsonResponse
    {
        $this->authorize('create', User::class);

        $buscado = trim((string) request()->string('q'));

        $encontrados = Cliente::query()
            ->when($buscado !== '', fn (Builder $q) => $q->where(
                function (Builder $w) use ($buscado): void {
                    $w->where('nombre_mostrado', 'ilike', '%'.$buscado.'%');

                    // ⚠️ Por número SÓLO si lo que se escribió es un número. Antes esto
                    // era un `orWhere('numero', (int) $buscado)` suelto, y `(int)` sobre
                    // un texto da 0: buscar «farmacia» traía además al cliente número 0,
                    // que es un banco. Un resultado de más en una lista de la que se
                    // elige a quién le va a ver los pedidos una cuenta.
                    if (ctype_digit($buscado)) {
                        $w->orWhere('numero', (int) $buscado);
                    }
                },
            ))
            ->orderBy('nombre_mostrado')
            ->limit(20)
            ->get(['id', 'numero', 'nombre_mostrado'])
            // Con el número además del nombre: hay nombres repetidos entre los clientes.
            ->map(fn (Cliente $cliente): array => [
                'id' => $cliente->id,
                'nombre' => 'N° '.$cliente->numero.' · '.$cliente->nombre_mostrado,
            ])
            ->all();

        return response()->json($encontrados);
    }
}
