<?php

use App\Support\Momento;
use Illuminate\Support\Carbon;

/*
| Cómo el panel muestra un momento.
|
| Esto existe por un desajuste de hidratación: la pantalla de un pedido mandaba la
| fecha en ISO y la formateaba en React, y el servidor escribía 23:37 donde el
| navegador escribía 20:37 — las tres horas entre UTC y America/Argentina/Tucuman.
| Ningún test de PHP lo veía, porque la hora la armaba el navegador. Ahora la arma el
| servidor y se puede probar.
*/

test('the time is formatted in the application timezone, not UTC', function () {
    // El mismo instante, escrito en UTC. En Tucumán son tres horas menos.
    $momento = Carbon::parse('2026-09-23T23:37:00Z');

    expect(Momento::fechaYHora($momento))->toBe('23/09/2026 20:37');
});

test('the hour is 24-hour, so 13:45 is not the same as 01:45', function () {
    // ⚠️ La pantalla vieja usaba format('d-m-Y h:i:s'), y `h` es la hora de 12 SIN
    // am/pm: todas las horas de ese historial son ambiguas.
    $tarde = Carbon::parse('2026-09-23 13:45:00', config('app.timezone'));
    $madrugada = Carbon::parse('2026-09-23 01:45:00', config('app.timezone'));

    expect(Momento::fechaYHora($tarde))->toBe('23/09/2026 13:45')
        ->and(Momento::fechaYHora($madrugada))->toBe('23/09/2026 01:45');
});

test('a missing moment gives null instead of a made-up date', function () {
    expect(Momento::fechaYHora(null))->toBeNull()
        ->and(Momento::hace(null))->toBeNull();
});

test('how long ago, in words', function (int $minutos, string $esperado) {
    Carbon::setTestNow('2026-09-23 12:00:00');

    expect(Momento::hace(now()->subMinutes($minutos)))->toBe($esperado);

    Carbon::setTestNow();
})->with([
    'justo ahora' => [0, 'ahora'],
    'medio minuto' => [0, 'ahora'],
    'un minuto' => [1, 'hace 1 min'],
    'cuarenta minutos' => [40, 'hace 40 min'],
    'el límite de la hora' => [59, 'hace 59 min'],
    'una hora' => [60, 'hace 1 h'],
    'el límite del día' => [60 * 23, 'hace 23 h'],
    'un día' => [60 * 24, 'hace 1 d'],
    'tres días' => [60 * 24 * 3, 'hace 3 d'],
]);
