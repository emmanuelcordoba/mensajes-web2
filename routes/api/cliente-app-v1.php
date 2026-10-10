<?php

use App\Http\Controllers\Api\ClienteApp\V1\AuthController;
use App\Http\Controllers\Api\ClienteApp\V1\ClienteController;
use App\Http\Controllers\Api\ClienteApp\V1\VerificacionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La app de clientes — contrato 1
|--------------------------------------------------------------------------
|
| ⚠️ **Las URL las manda la app**, que está publicada en los teléfonos y pide
| `/api/clientesapp/login`, `/api/clientesapp/verificar/{user}` y compañía. El prefijo
| `clientesapp` se ve raro al lado de las rutas del cadete, que no llevan ninguno, y se queda.
|
| ⚠️ **Esta app declara UNA interfaz en todo `interfaces.ts`.** El contrato está implícito en
| lo que leen las pantallas, así que cada respuesta dice en su recurso de dónde salió cada
| campo. Es lo que vuelve delicado tocar cualquier cosa de acá.
|
| ⚠️ **Se está portando por partes.** Lo que hay es con qué un cliente entra, se registra,
| verifica su casilla, ve el inicio y edita su perfil. Faltan los pedidos: los cuatro de esta
| versión y los dos de la v2.
|
| ⚠️ **`v2/clientesapp/get-setup` NO se porta.** Su único consumidor dejó de llamarlo el
| 2024-06-30 —commit `1333550` de la app— cuando el precio pasó a calcularlo el servidor. Ver
| `ClienteController`.
*/

Route::prefix('clientesapp')->group(function (): void {
    /*
    | Las públicas.
    |
    | ⚠️ `verificar/{user}` y su `reenviar` se identifican por el id de la cuenta en la URL, y
    | ese id acá es un entero correlativo. Lo que las sostiene está en
    | `VerificacionDeEmailDeCliente`.
    |
    | ⚠️ El ingreso y el registro con Facebook del sistema viejo no están: se borraron allá el
    | 2026-08-11 porque el handler de la app estaba vacío y había un solo usuario con
    | `facebook_id`, sin contraseña y sin ningún pedido en seis años. Ver DEP-1.
    */
    Route::post('login', [AuthController::class, 'login'])->name('api.cliente-app.v1.login');
    Route::post('register', [AuthController::class, 'registrarse'])->name('api.cliente-app.v1.registro');

    Route::get('registerCliente/getCliente', [AuthController::class, 'buscarPorNumero'])
        ->name('api.cliente-app.v1.registro.buscar');
    Route::post('registerCliente/{cliente}', [AuthController::class, 'registrarClienteDelPanel'])
        ->name('api.cliente-app.v1.registro.existente');

    Route::post('verificar/{user}', [VerificacionController::class, 'verificar'])
        ->name('api.cliente-app.v1.verificar');
    Route::get('verificar/{user}/reenviar', [VerificacionController::class, 'reenviar'])
        ->name('api.cliente-app.v1.verificar.reenviar');

    /*
    | Y lo que necesita un token.
    |
    | ⚠️ El rol se exige además del token: el mismo guard lo usan la app del cadete y la
    | integración. Ver SEC-13.
    */
    Route::middleware(['auth:sanctum', 'rol:cliente_app'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('api.cliente-app.v1.logout');

        Route::post('guardarfcmtoken', [ClienteController::class, 'guardarFcmToken'])
            ->name('api.cliente-app.v1.fcm-token');
        Route::get('getinicio', [ClienteController::class, 'inicio'])->name('api.cliente-app.v1.inicio');
        Route::get('getperfil', [ClienteController::class, 'perfil'])->name('api.cliente-app.v1.perfil');
        Route::post('editarperfil', [ClienteController::class, 'guardarPerfil'])
            ->name('api.cliente-app.v1.perfil.guardar');
    });
});
