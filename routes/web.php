<?php

use App\Http\Controllers\Panel\ClienteController;
use App\Http\Controllers\Panel\PedidoController;
use App\Http\Controllers\Panel\PendienteController;
use App\Http\Controllers\Panel\UserController;
use App\Models\Rol;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | El panel
    |--------------------------------------------------------------------------
    |
    | Dos grupos, como en el sistema viejo: lo que un comercio necesita para trabajar
    | y lo que es administrar el negocio. El corte es el mismo que hace el menú
    | lateral, y está escrito dos veces a propósito —acá y en App\Support\Navegacion—
    | porque son dos preguntas distintas: quién entra a la ruta y qué se le ofrece.
    | NavegacionTest compara las dos respuestas, entrada por entrada y rol por rol.
    |
    | ⚠️ Muchas de estas pantallas todavía no están hechas y van a PendienteController,
    | que no muestra más que su nombre. La ruta y su nombre definitivo existen desde
    | ahora: cuando la pantalla esté, cambia el controlador y nada más. Ver el docblock
    | de PendienteController.
    |
    */
    Route::prefix('panel')->name('panel.')->group(function () {

        /*
        | Acceso compartido: admin, empleado y los comercios.
        |
        | El middleware dice quién entra a la ruta; a qué fila llega lo dice
        | PedidoPolicy, y las dos cosas hacen falta: sin la segunda, un comercio veía
        | los pedidos de cualquier otro mandando el id (SEC-7).
        */
        Route::middleware('rol:'.implode(',', [Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO]))
            ->group(function () {
                // La pantalla principal del sistema viejo: el alta de un pedido, con la
                // lista de cadetes activos al costado. Es la más cargada de todas.
                Route::get('/', PendienteController::class)->name('inicio');

                Route::get('pedidos', [PedidoController::class, 'index'])->name('pedidos.index');
                // Después del listado, que es una ruta exacta y no la captura.
                Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->name('pedidos.show');
            });

        /*
        | Administración: sólo admin y empleado.
        |
        | Es el mismo recorte que el menú viejo hace con
        | @if(Auth::user()->tieneRol(['admin','empleado'])), y que el sistema viejo
        | también lleva al middleware. Ver SEC-4: hasta el 2026-08-12 todo el panel
        | colgaba de un único grupo que no distinguía entre los tres roles, y una
        | cuenta de comercio llegaba a la administración de usuarios y podía crearse un
        | administrador.
        */
        Route::middleware('rol:'.implode(',', [Rol::ADMIN, Rol::EMPLEADO]))
            ->group(function () {
                Route::get('mensajes', PendienteController::class)->name('mensajes.index');

                // ⚠️ `create` antes de cualquier `{cliente}` que se agregue después, o
                // lo captura. Vale para los cuatro recursos de acá abajo.
                /*
                | Los clientes.
                |
                | ⚠️ `clientes/create` antes de `clientes/{cliente}`, o el parámetro la
                | captura. La baja suma `rol:admin` sobre el grupo, como en el sistema
                | viejo: se lleva los pedidos del cliente y su cuenta de la app, y eso no
                | queda al alcance de cualquier rol. Lo decide ClientePolicy, que además
                | dice POR QUÉ no cuando el cliente está asignado a una cuenta de panel.
                */
                Route::get('clientes/create', [ClienteController::class, 'create'])->name('clientes.create');
                Route::get('clientes', [ClienteController::class, 'index'])->name('clientes.index');
                Route::post('clientes', [ClienteController::class, 'store'])->name('clientes.store');
                Route::get('clientes/{cliente}/edit', [ClienteController::class, 'edit'])->name('clientes.edit');
                Route::patch('clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
                Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');
                Route::get('publicidad-app', PendienteController::class)->name('publicidad-app.index');
                Route::get('horario-de-atencion', PendienteController::class)->name('horario-de-atencion.index');

                Route::get('cadetes/create', PendienteController::class)->name('cadetes.create');
                Route::get('cadetes/postulaciones', PendienteController::class)->name('cadetes.postulaciones.index');
                Route::get('cadetes/cola', PendienteController::class)->name('cadetes.cola');
                Route::get('cadetes/cobranzas/semanal', PendienteController::class)->name('cadetes.cobranzas.semanal.index');
                Route::get('cadetes/cobranzas/saldo', PendienteController::class)->name('cadetes.cobranzas.saldo.index');
                Route::get('cadetes', PendienteController::class)->name('cadetes.index');

                /*
                | Las cuentas del panel.
                |
                | ⚠️ `users/create` y `users/comercios` van antes de `users/{user}`, o el
                | parámetro las captura. Quién puede qué lo decide UserPolicy: el grupo
                | deja entrar a empleado y admin, y de ahí para adentro la cuenta de un
                | administrador sólo la toca otro administrador (SEC-9).
                */
                Route::get('users/create', [UserController::class, 'create'])->name('users.create');
                Route::get('users/comercios', [UserController::class, 'comercios'])->name('users.comercios');
                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::post('users', [UserController::class, 'store'])->name('users.store');
                Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
                Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

                Route::get('configuraciones', PendienteController::class)->name('configuraciones.index');
                Route::get('mapa', PendienteController::class)->name('mapa');
                Route::get('estadisticas', PendienteController::class)->name('estadisticas.index');
            });
    });
});

require __DIR__.'/settings.php';
