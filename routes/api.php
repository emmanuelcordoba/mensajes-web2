<?php

use App\Http\Controllers\Api\CadeteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La API de las apps
|--------------------------------------------------------------------------
|
| ⚠️ **Las URL las manda la app, no este archivo.** La app del cadete está publicada en los
| teléfonos y pide `/api/getcadete`, `/api/cambiarestado` y `/api/actualizarubicacion`, sin
| prefijo de versión y con esos nombres. No se pueden acomodar sin republicar la app y
| esperar a que todos la actualicen, y mientras tanto el que no actualizó no puede trabajar.
|
| ⚠️ **Se está portando por partes.** Lo que hay son los cuatro endpoints con los que un
| cadete entra a la cola y aparece en el mapa. Faltan los pedidos, el perfil, el historial,
| los mensajes, la cobranza, la postulación y el reseteo de contraseña, más las dos apps de
| clientes y la API de integración.
|
| ⚠️ `getAsignados` y `getAceptados` del sistema viejo **no se portan**: buscados en toda la
| historia del repositorio de la app, nunca se llamaron.
*/

// Pública: es por donde se entra.
Route::post('cadetes/login', [CadeteController::class, 'login'])->name('api.cadetes.login');

/*
| Y lo que necesita un token.
|
| ⚠️ El rol se exige además del token, y no es redundante: el mismo guard lo usan las apps
| de clientes, que se registran solas. Sin esto, el token de un cliente entraba a los
| endpoints del cadete. Ver SEC-13.
*/
Route::middleware(['auth:sanctum', 'rol:cadete'])->group(function () {
    Route::get('getcadete', [CadeteController::class, 'getCadete'])->name('api.cadetes.getcadete');
    Route::get('cambiarestado', [CadeteController::class, 'cambiarEstado'])->name('api.cadetes.cambiarestado');
    Route::post('actualizarubicacion', [CadeteController::class, 'actualizarUbicacion'])->name('api.cadetes.ubicacion');
});
