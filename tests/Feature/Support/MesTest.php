<?php

use App\Support\Mes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
| El mes que mira la pantalla de estadísticas.
|
| Casi todo este archivo es el bug del sistema viejo: a `createFromFormat('Y-m', $mes)` PHP le
| completa el día de hoy, así que los últimos días de un mes largo, pedir un mes corto devuelve
| el mes siguiente. Ver la cabecera de `App\Support\Mes`.
|
| `Mes` usa `CarbonImmutable` y acá se congela el reloj con `Carbon`: desde Carbon 3 las dos
| clases comparten el instante de prueba —va a `FactoryImmutable::getDefaultInstance()`—, así
| que alcanza con fijarlo una vez.
*/

afterEach(function () {
    Carbon::setTestNow();
});

test('a short month asked on the 31st is that month, and not the next one', function () {
    // ⚠️ Esto es el bug. El 31 de marzo, `createFromFormat('Y-m', '2026-02')` cae el 3 de
    // marzo, y la pantalla vieja muestra marzo sin decir nada.
    //
    // El «hoy» tiene que ser posterior al mes pedido: pedido el 31 de enero, febrero es el
    // futuro y se trae al mes actual antes de llegar a esto.
    Carbon::setTestNow('2026-03-31 10:00:00');

    $mes = Mes::pedido('2026-02');

    expect($mes->etiqueta())->toBe('2026-02')
        ->and($mes->primerDia()->toDateString())->toBe('2026-02-01')
        ->and($mes->despuesDelUltimoDia()->toDateString())->toBe('2026-03-01')
        ->and($mes->dias())->toHaveCount(28);
});

test('the day of the month you ask from never leaks into the answer', function (string $hoy) {
    Carbon::setTestNow($hoy);

    expect(Mes::pedido('2026-02')->primerDia()->toDateString())->toBe('2026-02-01');
})->with(['2026-03-01 00:00:00', '2026-03-15 12:00:00', '2026-03-29 23:59:59', '2026-03-31 08:00:00']);

test('the last day of a long month does not turn a 30-day month into the next one', function () {
    // hoy 2026-05-31, pido 2026-04  ->  el sistema viejo muestra mayo.
    Carbon::setTestNow('2026-05-31 10:00:00');

    expect(Mes::pedido('2026-04')->primerDia()->toDateString())->toBe('2026-04-01')
        ->and(Mes::pedido('2026-04')->dias())->toHaveCount(30);
});

test('a leap february has 29 days', function () {
    Carbon::setTestNow('2024-05-10 10:00:00');

    expect(Mes::pedido('2024-02')->dias())->toHaveCount(29);
});

test('what is not a month is the current month', function (?string $texto) {
    Carbon::setTestNow('2026-09-10 10:00:00');

    expect(Mes::pedido($texto)->etiqueta())->toBe('2026-09');
})->with([
    null,
    '',
    'cualquiera',
    // ⚠️ Éste es el que más importa: en el sistema viejo no falla, devuelve enero de 2027.
    '2026-13',
    '2026-00',
    '2026-9',
    '26-09',
    '2026-09-15',
]);

test('a month in the future is brought back to the current one', function () {
    Carbon::setTestNow('2026-09-10 10:00:00');

    expect(Mes::pedido('2026-10')->etiqueta())->toBe('2026-09')
        ->and(Mes::pedido('2030-01')->etiqueta())->toBe('2026-09');
});

test('the current month stops at today, and does not list days that have not happened', function () {
    Carbon::setTestNow('2026-09-10 15:30:00');

    $mes = Mes::pedido('2026-09');

    expect($mes->dias())->toHaveCount(10)
        ->and($mes->dias()[9]->toDateString())->toBe('2026-09-10')
        // El límite de arriba es abierto: el día siguiente a las 00:00, no hoy a las 23:59:59.
        ->and($mes->despuesDelUltimoDia()->toDateTimeString())->toBe('2026-09-11 00:00:00');
});

test('a finished month lists all of its days', function () {
    Carbon::setTestNow('2026-09-10 15:30:00');

    $mes = Mes::pedido('2026-08');

    expect($mes->dias())->toHaveCount(31)
        ->and($mes->despuesDelUltimoDia()->toDateTimeString())->toBe('2026-09-01 00:00:00');
});

test('the upper bound is open, so the last instant of the day is inside it', function () {
    // El sistema viejo consulta hasta «23:59:59» y una fecha guardada tiene microsegundos: un
    // pedido de las 23:59:59,5 no entra en ningún día del mes.
    Carbon::setTestNow('2026-09-10 15:30:00');

    $ultimoInstante = CarbonImmutable::parse('2026-08-31 23:59:59.999999');

    expect($ultimoInstante->lessThan(Mes::pedido('2026-08')->despuesDelUltimoDia()))->toBeTrue();
});

test('the neighbours of a month, and that there is nothing after the current one', function () {
    Carbon::setTestNow('2026-09-10 10:00:00');

    expect(Mes::pedido('2026-09')->anterior()->etiqueta())->toBe('2026-08')
        ->and(Mes::pedido('2026-09')->siguiente())->toBeNull()
        ->and(Mes::pedido('2026-08')->siguiente()?->etiqueta())->toBe('2026-09')
        ->and(Mes::pedido('2026-01')->anterior()->etiqueta())->toBe('2025-12');
});

test('stepping back from a long month does not skip the short one', function () {
    // El mismo desborde, del otro lado: al 31 de marzo restarle un mes da el 3 de marzo. `Mes`
    // guarda siempre el día 1, así que no puede pasar.
    Carbon::setTestNow('2026-05-10 10:00:00');

    expect(Mes::pedido('2026-03')->anterior()->etiqueta())->toBe('2026-02');
});

test('the name of the month is in spanish, for the title', function () {
    Carbon::setTestNow('2026-09-10 10:00:00');

    expect(Mes::pedido('2026-09')->nombre())->toBe('septiembre de 2026');
});
