<?php

use App\Models\ActividadCadete;
use App\Models\Cadete;

/*
| La actividad del cadete en la app, y sobre todo PANEL-17: que el tramo se cierre
| por CUALQUIER camino que lo saque de la app, no sólo por uno.
*/

test('an open stretch is one with no end', function () {
    expect(ActividadCadete::factory()->abierta()->create()->estaAbierta())->toBeTrue()
        ->and(ActividadCadete::factory()->create()->estaAbierta())->toBeFalse();
});

test('closing a stretch records how long it lasted, in seconds', function () {
    $actividad = ActividadCadete::factory()->abierta()->create(['inicio' => now()->subMinutes(90)]);

    $actividad->cerrar();

    expect($actividad->estaAbierta())->toBeFalse()
        ->and($actividad->duracion)->toBe(5_400);
});

test('leaving the app closes the open stretch, by any path', function () {
    // PANEL-17: el fin se escribía sólo en cambiarEstado() y sólo desde
    // `activo-app`, así que con un pedido en curso, al cerrar sesión o al sacarlo la
    // central de la cola, el tramo quedaba abierto para siempre.
    foreach ([Cadete::ESTADO_ACTIVO_APP, Cadete::ESTADO_CON_PEDIDO_APP] as $desde) {
        $cadete = Cadete::factory()->enEstado($desde)->create();
        $actividad = $cadete->actividades()->create(['inicio' => now()->subHour()]);

        $cadete->estado = Cadete::ESTADO_INACTIVO;
        $cadete->save();

        expect($actividad->fresh()->estaAbierta())->toBeFalse()
            ->and($actividad->fresh()->duracion)->toBeGreaterThan(3_500);
    }
});

test('moving between two states inside the app leaves the stretch open', function () {
    $cadete = Cadete::factory()->enEstado(Cadete::ESTADO_ACTIVO_APP)->create();
    $actividad = $cadete->actividades()->create(['inicio' => now()->subHour()]);

    // Tomar un pedido no lo saca de la app: el tramo sigue siendo el mismo.
    $cadete->estado = Cadete::ESTADO_CON_PEDIDO_APP;
    $cadete->save();

    expect($actividad->fresh()->estaAbierta())->toBeTrue();
});

test('coming from outside the app closes nothing', function () {
    // Un cadete en la web nunca abrió un tramo de actividad de app.
    $cadete = Cadete::factory()->enEstado(Cadete::ESTADO_ACTIVO_WEB)->create();
    $actividad = $cadete->actividades()->create(['inicio' => now()->subHour()]);

    $cadete->estado = Cadete::ESTADO_INACTIVO;
    $cadete->save();

    expect($actividad->fresh()->estaAbierta())->toBeTrue();
});

test('the one that gets closed is the open one, not the last one created', function () {
    // El sistema viejo buscaba el último por fecha sin filtrar por `fin`, así que
    // podía volver a cerrar uno ya cerrado y dejar el abierto de verdad sin tocar.
    $cadete = Cadete::factory()->enEstado(Cadete::ESTADO_ACTIVO_APP)->create();

    $abierta = $cadete->actividades()->create(['inicio' => now()->subHours(5)]);
    $yaCerrada = $cadete->actividades()->create([
        'inicio' => now()->subHour(),
        'fin' => now()->subMinutes(30),
        'duracion' => 1_800,
    ]);

    $cadete->estado = Cadete::ESTADO_INACTIVO;
    $cadete->save();

    expect($abierta->fresh()->estaAbierta())->toBeFalse()
        ->and($yaCerrada->fresh()->duracion)->toBe(1_800);
});

test('saving a cadete without touching the state closes nothing', function () {
    $cadete = Cadete::factory()->enEstado(Cadete::ESTADO_ACTIVO_APP)->create();
    $actividad = $cadete->actividades()->create(['inicio' => now()->subHour()]);

    $cadete->update(['direccion' => 'Otra dirección 456']);

    expect($actividad->fresh()->estaAbierta())->toBeTrue();
});
