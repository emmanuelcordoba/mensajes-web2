<?php

use App\Http\Controllers\Api\CadeteApp\V1\CadeteController;
use App\Http\Controllers\Api\CadeteApp\V1\CobranzaController;
use App\Http\Controllers\Api\CadeteApp\V1\MensajeController;
use App\Http\Controllers\Api\CadeteApp\V1\PedidoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La app del cadete — contrato 1
|--------------------------------------------------------------------------
|
| ⚠️ **Las URL las manda la app, no este archivo.** Está publicada en los teléfonos y pide
| `/api/getcadete`, `/api/cambiarestado` y `/api/pedidos/aceptar?id=`, sin prefijo de versión
| y con esos nombres. No se pueden acomodar sin republicar la app y esperar a que todos la
| actualicen, y mientras tanto el que no actualizó no puede trabajar. Lo que lee está
| declarado en `mensajes-cadeteria-cadete-app/src/app/interfaces/interfaces.ts`.
|
| ⚠️ **Se está portando por partes.** Lo que hay es con qué un cadete entra a la cola, qué
| hace con sus pedidos, su perfil con el historial, el chat con la central y la cobranza.
| Faltan la postulación, el reseteo de contraseña, `guardarfcmtoken` y `cerrarsesion` —y los
| dos primeros son los únicos públicos que quedan—.
|
| ⚠️ `getAsignados` y `getAceptados` del sistema viejo **no se portan**: buscados en toda la
| historia del repositorio de la app, nunca se llamaron.
*/

/*
| ⚠️ Los nombres llevan el consumidor y la versión —`api.cadete-app.v1.pedidos.index`— y no
| es verborragia: la app de clientes también lista pedidos, y `api.pedidos.index` no puede
| ser de los dos. El sistema viejo lo resuelve a medias —`cadetes.login` tiene dueño,
| `pedidos.aceptar` no— y por eso las rutas de clientes directamente no tienen nombre.
*/

// Pública: es por donde se entra.
Route::post('cadetes/login', [CadeteController::class, 'login'])->name('api.cadete-app.v1.login');

/*
| Y lo que necesita un token.
|
| ⚠️ El rol se exige además del token, y no es redundante: el mismo guard lo usan las apps
| de clientes, que se registran solas. Sin esto, el token de un cliente entraba a los
| endpoints del cadete. Ver SEC-13.
*/
Route::middleware(['auth:sanctum', 'rol:cadete'])->group(function () {
    Route::get('getcadete', [CadeteController::class, 'getCadete'])->name('api.cadete-app.v1.cadete');
    Route::get('cambiarestado', [CadeteController::class, 'cambiarEstado'])->name('api.cadete-app.v1.estado');
    Route::post('actualizarubicacion', [CadeteController::class, 'actualizarUbicacion'])->name('api.cadete-app.v1.ubicacion');

    /*
    | El perfil y el historial.
    |
    | ⚠️ `getperfil` y `getcadete` se parecen y no son lo mismo: el primero devuelve la
    | CUENTA con el cadete adentro —es la pantalla de datos personales— y el segundo el
    | cadete con su lugar en la cola, que es lo que la pantalla principal refresca.
    */
    Route::get('getperfil', [CadeteController::class, 'getPerfil'])->name('api.cadete-app.v1.perfil');
    Route::post('editarperfil', [CadeteController::class, 'editarPerfil'])->name('api.cadete-app.v1.perfil.guardar');
    Route::get('historial', [CadeteController::class, 'historial'])->name('api.cadete-app.v1.historial');

    /*
    | El chat con la central.
    |
    | ⚠️ `mensajes/noleidos` va ANTES de nada que pueda comérselo, y `mensajes/store` es un
    | POST a una URL que dice qué hace: así las pide la app publicada.
    */
    Route::get('mensajes', [MensajeController::class, 'index'])->name('api.cadete-app.v1.mensajes.index');
    Route::get('mensajes/noleidos', [MensajeController::class, 'noLeidos'])->name('api.cadete-app.v1.mensajes.noleidos');
    Route::post('mensajes/store', [MensajeController::class, 'store'])->name('api.cadete-app.v1.mensajes.store');

    /*
    | La cobranza.
    |
    | ⚠️ Sólo tiene algo que mostrar para los 48 cadetes que cobran por saldo. Los otros 172
    | cobran una cuota semanal, que se arregla en la oficina.
    */
    Route::get('cobranza', [CobranzaController::class, 'index'])->name('api.cadete-app.v1.cobranza');
    Route::get('cobranza/saldo/movimientos', [CobranzaController::class, 'movimientos'])
        ->name('api.cadete-app.v1.cobranza.movimientos');

    /*
    | Los pedidos.
    |
    | ⚠️ Los tres botones son GET con `?id=`, que para algo que escribe está mal. Así los
    | llama la app publicada y cambiarlo le rompe la pantalla a quien no actualizó.
    |
    | ⚠️ Y van ANTES de `pedidos/{pedido}`, o el comodín se los come con el id «aceptar».
    */
    Route::get('pedidos', [PedidoController::class, 'index'])->name('api.cadete-app.v1.pedidos.index');
    Route::get('pedidos/aceptar', [PedidoController::class, 'aceptar'])->name('api.cadete-app.v1.pedidos.aceptar');
    Route::get('pedidos/rechazar', [PedidoController::class, 'rechazar'])->name('api.cadete-app.v1.pedidos.rechazar');
    Route::get('pedidos/finalizar', [PedidoController::class, 'finalizar'])->name('api.cadete-app.v1.pedidos.finalizar');
    Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->name('api.cadete-app.v1.pedidos.show');
});
