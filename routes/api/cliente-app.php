<?php

use App\Http\Controllers\Api\ClienteApp\V1\AuthController;
use App\Http\Controllers\Api\ClienteApp\V1\ClienteController;
use App\Http\Controllers\Api\ClienteApp\V1\PedidoController;
use App\Http\Controllers\Api\ClienteApp\V1\VerificacionController;
use App\Http\Controllers\Api\ClienteApp\V2\PedidoController as PedidoControllerV2;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La app de clientes
|--------------------------------------------------------------------------
|
| ⚠️ **Acá viven las DOS versiones**, y por eso el archivo no lleva ninguna en el nombre: la
| app usa las dos a la vez —el listado y el perfil por la v1, el alta y la cotización por la
| v2—, así que partirlas en dos archivos obligaría a leer los dos para entender qué ve esta
| app. Es el mismo motivo por el que el consumidor va antes que la versión en las carpetas de
| los controladores, donde la versión sí importa porque es la forma de cada contrato.
|
| ⚠️ **Las URL las manda la app**, que está publicada en los teléfonos y pide
| `/api/clientesapp/login`, `/api/clientesapp/verificar/{user}` y compañía. El prefijo
| `clientesapp` se ve raro al lado de las rutas del cadete, que no llevan ninguno, y se queda.
|
| ⚠️ **Esta app declara UNA interfaz en todo `interfaces.ts`.** El contrato está implícito en
| lo que leen las pantallas, así que cada respuesta dice en su recurso de dónde salió cada
| campo. Es lo que vuelve delicado tocar cualquier cosa de acá.
|
| ⚠️ **Está completa.** De los 18 endpoints del sistema viejo se portaron 17: los dos que
| quedaron afuera son `v2/clientesapp/get-setup` y el alta de pedidos de la v1, y los dos
| están muertos.
|
| ⚠️ **`get-setup` NO se porta.** Su único consumidor dejó de llamarlo el 2024-06-30 —commit
| `1333550` de la app— cuando el precio pasó a calcularlo el servidor. Ver `ClienteController`.
|
| ⚠️ **El alta de pedidos de la v1 tampoco**: se borró del sistema viejo el 2026-08-11 porque
| el último pedido creado por esa vía fue del 2025-01-30 mientras la v2 seguía activa. Ver
| DEP-2.
|
| ⚠️ **La v2 no reemplaza a la v1: la completa.** La app usa las dos a la vez —el listado por
| la v1, el alta por la v2—, así que las rutas de las dos viven en este archivo y no en uno
| aparte. Lo que la v2 tiene son dos endpoints que se agregaron después.
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

        /*
        | Los pedidos.
        |
        | ⚠️ El binding resuelve cualquier pedido por id, sin mirar de quién es, así que los
        | tres los comprueban y contestan 404 —no 403— cuando no es del cliente: un 403
        | confirma que existe, y los ids son correlativos.
        */
        Route::get('getpedidos', [PedidoController::class, 'index'])->name('api.cliente-app.v1.pedidos.index');
        Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->name('api.cliente-app.v1.pedidos.show');
        Route::post('pedidos/{pedido}/aceptar', [PedidoController::class, 'aceptar'])
            ->name('api.cliente-app.v1.pedidos.aceptar');
        Route::delete('pedidos/{pedido}', [PedidoController::class, 'destroy'])
            ->name('api.cliente-app.v1.pedidos.destroy');
    });
});

/*
| Y la v2, que son dos endpoints de la misma app.
|
| ⚠️ `cotizar` va ANTES de nada que pueda comérselo y es un GET con parámetros, como la pide
| la app. El alta es un POST a `pedidos/` con la barra final: así la manda
| `data.service.ts:70`.
*/
Route::prefix('v2/clientesapp')->middleware(['auth:sanctum', 'rol:cliente_app'])->group(function (): void {
    Route::get('pedidos/cotizar', [PedidoControllerV2::class, 'cotizar'])
        ->name('api.cliente-app.v2.pedidos.cotizar');
    Route::post('pedidos', [PedidoControllerV2::class, 'store'])
        ->name('api.cliente-app.v2.pedidos.store');
});
