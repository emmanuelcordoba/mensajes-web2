<?php

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

/*
| Qué se guarda y qué no, fuera de local.
|
| ⚠️ **Esto existe por un día entero de tabla vacía.** El filtro usaba
| `IncomingEntry::isFailedRequest()`, que suena a lo que hace falta y mira `>= 500`: los 429
| que vinimos a entender no entraban, ni los 404, ni los 422. En local no se notaba —ahí se
| guarda todo— y en producción no había nada que mirar: ni un error, ni una caída, la
| pantalla de Fallas diciendo que no falló nada.
|
| El corte en 400 es el mismo que `FallasDeLaApi` usa para leer, así que lo que se guarda y
| lo que se cuenta son el mismo conjunto. Si alguien vuelve a mover uno, este archivo avisa.
|
| ⚠️ Se prueba el filtro de verdad: el que el proveedor registró en `Telescope::$filterUsing`
| al arrancar la aplicación. `APP_ENV` es `testing`, así que la rama de local está apagada y
| esto corre igual que en producción. Telescope no está grabando —`TELESCOPE_ENABLED` en
| falso en phpunit.xml—, y no hace falta: lo que se pregunta es qué contesta el filtro.
*/

/** Lo que el filtro registrado contesta para una entrada. */
function seGuarda(IncomingEntry $entrada): bool
{
    return collect(Telescope::$filterUsing)->every->__invoke($entrada);
}

/** Una respuesta con un código, como la arma el watcher de pedidos. */
function unaRespuesta(int $codigo): IncomingEntry
{
    return IncomingEntry::make(['response_status' => $codigo])->type('request');
}

test('a failed response is kept from 400 up', function (int $codigo) {
    expect(seGuarda(unaRespuesta($codigo)))->toBeTrue();
})->with([
    // El que motivó todo esto.
    429,
    // Un formulario rechazado: el de la postulación es de los que más falla.
    422,
    401,
    404,
    500,
    503,
]);

test('what came out fine is not kept', function (int $codigo) {
    expect(seGuarda(unaRespuesta($codigo)))->toBeFalse();
})->with([200, 201, 204, 302, 304]);

test('a warning is kept, and the everyday noise is not', function () {
    $renglon = fn (string $nivel): IncomingEntry => IncomingEntry::make(['level' => $nivel])->type('log');

    // El renglón del tope de pedidos —de qué móvil fue cada 429— es un warning, y es el
    // único lugar donde queda escrito quién.
    expect(seGuarda($renglon('warning')))->toBeTrue()
        ->and(seGuarda($renglon('error')))->toBeTrue()
        ->and(seGuarda($renglon('critical')))->toBeTrue()
        ->and(seGuarda($renglon('info')))->toBeFalse()
        ->and(seGuarda($renglon('debug')))->toBeFalse();
});

test('a query is not kept, which is what would make this huge', function () {
    // El watcher de consultas está apagado fuera de local, pero el filtro tiene que
    // decir que no igual: son decenas por pedido sobre 16K pedidos por día.
    expect(seGuarda(IncomingEntry::make(['sql' => 'select 1'])->type('query')))->toBeFalse();
});
