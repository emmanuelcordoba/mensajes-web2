<?php

use App\Http\Controllers\Panel\PedidoController;
use App\Models\Rol;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    /*
    | El panel. El middleware dice quién entra a la ruta; a qué fila llega lo dice
    | PedidoPolicy, y las dos cosas hacen falta: sin la segunda, un comercio veía los
    | pedidos de cualquier otro mandando el id (SEC-7).
    */
    Route::middleware('rol:'.implode(',', [Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO]))
        ->prefix('panel')
        ->name('panel.')
        ->group(function () {
            Route::get('pedidos', [PedidoController::class, 'index'])->name('pedidos.index');
            // Después del listado, que es una ruta exacta y no la captura.
            Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->name('pedidos.show');
        });
});

require __DIR__.'/settings.php';
