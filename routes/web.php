<?php

use App\Http\Controllers\Panel\PedidoController;
use App\Http\Controllers\Panel\PendienteController;
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
                Route::get('clientes/create', PendienteController::class)->name('clientes.create');
                Route::get('clientes', PendienteController::class)->name('clientes.index');
                Route::get('publicidad-app', PendienteController::class)->name('publicidad-app.index');
                Route::get('horario-de-atencion', PendienteController::class)->name('horario-de-atencion.index');

                Route::get('cadetes/create', PendienteController::class)->name('cadetes.create');
                Route::get('cadetes/postulaciones', PendienteController::class)->name('cadetes.postulaciones.index');
                Route::get('cadetes/cola', PendienteController::class)->name('cadetes.cola');
                Route::get('cadetes/cobranzas/semanal', PendienteController::class)->name('cadetes.cobranzas.semanal.index');
                Route::get('cadetes/cobranzas/saldo', PendienteController::class)->name('cadetes.cobranzas.saldo.index');
                Route::get('cadetes', PendienteController::class)->name('cadetes.index');

                Route::get('users/create', PendienteController::class)->name('users.create');
                Route::get('users', PendienteController::class)->name('users.index');

                Route::get('configuraciones', PendienteController::class)->name('configuraciones.index');
                Route::get('mapa', PendienteController::class)->name('mapa');
                Route::get('estadisticas', PendienteController::class)->name('estadisticas.index');
            });
    });
});

require __DIR__.'/settings.php';
