<?php

use App\Http\Controllers\Panel\CadeteController;
use App\Http\Controllers\Panel\ClienteController;
use App\Http\Controllers\Panel\ConfiguracionController;
use App\Http\Controllers\Panel\HorarioAtencionController;
use App\Http\Controllers\Panel\PedidoController;
use App\Http\Controllers\Panel\PendienteController;
use App\Http\Controllers\Panel\PublicidadAppController;
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
                /*
                | El carrusel de la app. Borrar suma `rol:admin` por la política, como en
                | el sistema viejo, donde `ajax.publicidadapp.destroy` llevaba ese
                | middleware junto con el borrado de usuarios.
                |
                | ⚠️ `create` antes de `{publicidadApp}`, o el parámetro la captura.
                */
                Route::get('publicidad-app/create', [PublicidadAppController::class, 'create'])->name('publicidad-app.create');
                Route::get('publicidad-app', [PublicidadAppController::class, 'index'])->name('publicidad-app.index');
                Route::post('publicidad-app', [PublicidadAppController::class, 'store'])->name('publicidad-app.store');
                Route::get('publicidad-app/{publicidadApp}/edit', [PublicidadAppController::class, 'edit'])->name('publicidad-app.edit');
                Route::patch('publicidad-app/{publicidadApp}', [PublicidadAppController::class, 'update'])->name('publicidad-app.update');
                Route::patch('publicidad-app/{publicidadApp}/visibilidad', [PublicidadAppController::class, 'cambiarVisibilidad'])->name('publicidad-app.visibilidad');
                Route::delete('publicidad-app/{publicidadApp}', [PublicidadAppController::class, 'destroy'])->name('publicidad-app.destroy');

                /*
                | El horario de atención es UNA fila: no hay listado ni alta, sólo la
                | pantalla y su guardado. Ver HorarioAtencionController.
                */
                Route::get('horario-de-atencion', [HorarioAtencionController::class, 'edit'])->name('horario-de-atencion.index');
                Route::patch('horario-de-atencion', [HorarioAtencionController::class, 'update'])->name('horario-de-atencion.update');

                /*
                | Los cadetes.
                |
                | ⚠️ Todo lo que empieza con un segmento literal va ANTES de
                | `cadetes/{cadete}`, o el parámetro lo captura: valen las seis primeras,
                | incluidas las cuatro pantallas que todavía no están.
                |
                | Las tres últimas no son CRUD y son del listado: ponerlo en la cola o
                | sacarlo, y bloquearlo. El bloqueo se escribe en `users`, así que PATCH y
                | DELETE sobre el mismo recurso —`cadetes/{cadete}/bloqueo`— son poner y
                | quitar el motivo. La baja suma `rol:admin` por la política, y CadetePolicy
                | además dice POR QUÉ no cuando la cobranza está abierta.
                */
                Route::get('cadetes/create', [CadeteController::class, 'create'])->name('cadetes.create');
                Route::get('cadetes/postulaciones', PendienteController::class)->name('cadetes.postulaciones.index');
                Route::get('cadetes/cola', PendienteController::class)->name('cadetes.cola');
                Route::get('cadetes/cobranzas/semanal', PendienteController::class)->name('cadetes.cobranzas.semanal.index');
                Route::get('cadetes/cobranzas/saldo', PendienteController::class)->name('cadetes.cobranzas.saldo.index');
                Route::get('cadetes', [CadeteController::class, 'index'])->name('cadetes.index');
                Route::post('cadetes', [CadeteController::class, 'store'])->name('cadetes.store');
                Route::get('cadetes/{cadete}/edit', [CadeteController::class, 'edit'])->name('cadetes.edit');
                Route::patch('cadetes/{cadete}', [CadeteController::class, 'update'])->name('cadetes.update');
                Route::delete('cadetes/{cadete}', [CadeteController::class, 'destroy'])->name('cadetes.destroy');
                Route::patch('cadetes/{cadete}/estado', [CadeteController::class, 'cambiarEstado'])->name('cadetes.estado');
                Route::patch('cadetes/{cadete}/bloqueo', [CadeteController::class, 'bloquear'])->name('cadetes.bloquear');
                Route::delete('cadetes/{cadete}/bloqueo', [CadeteController::class, 'desbloquear'])->name('cadetes.desbloquear');

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

                /*
                | Los parámetros del negocio. Se leen al cotizar un pedido y al cobrarle
                | al cadete, así que el validador exige lo mismo que el CHECK de la
                | columna: ver GuardarConfiguracionRequest.
                |
                | El `update` lo usa también el selector de demora de la barra de arriba,
                | que es otra fila de la misma tabla.
                */
                Route::get('configuraciones', [ConfiguracionController::class, 'index'])->name('configuraciones.index');
                Route::patch('configuraciones/{configuracion}', [ConfiguracionController::class, 'update'])->name('configuraciones.update');
                Route::get('mapa', PendienteController::class)->name('mapa');
                Route::get('estadisticas', PendienteController::class)->name('estadisticas.index');
            });
    });
});

require __DIR__.'/settings.php';
