<?php

use App\Http\Controllers\Api\UserApi\V1\PedidoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La integración de terceros
|--------------------------------------------------------------------------
|
| Un consumidor externo cotiza un viaje y da de alta un pedido. Son dos rutas.
|
| ⚠️ **No tiene tráfico desde el 2021-12-02**: 76 pedidos en total, ninguno en casi cuatro
| años, y una sola cuenta con el rol `cliente_api`. Es DEP-3. Se porta porque mientras la
| cuenta exista el endpoint tiene que estar, y darla de baja es una decisión de la empresa.
|
| ⚠️ **Las URL no llevan versión**, como las del cadete: así están en el sistema viejo
| —`/api/usersapi/pedidos/`— y no hay consumidor al que convenga molestar para acomodarlas.
|
| ⚠️ **El precio nunca se recibe.** Las dos rutas lo calculan en el servidor. Ver
| `PedidoController` y `CotizacionDeViaje`.
*/

Route::prefix('usersapi')->middleware(['auth:sanctum', 'rol:cliente_api'])->group(function (): void {
    Route::post('pedidos/cotizar', [PedidoController::class, 'cotizar'])
        ->name('api.user-api.v1.pedidos.cotizar');
    Route::post('pedidos', [PedidoController::class, 'store'])
        ->name('api.user-api.v1.pedidos.store');
});
