<?php

use App\Models\HorarioAtencion;
use App\Models\Rol;
use Illuminate\Support\Carbon;

/*
| El horario en que la empresa atiende.
|
| Es una sola fila, y lo que esta pantalla tiene que garantizar es que siga siendo una.
*/

test('an employee reaches it and a shop does not', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/horario-de-atencion')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('horario-atencion/edit'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/horario-de-atencion')
        ->assertForbidden();
});

test('with no row yet, the screen opens anyway and the first save creates it', function () {
    // El panel viejo tenía «nuevo horario» aparte; acá la pantalla es la misma esté o no.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/horario-de-atencion')
        ->assertInertia(fn ($pagina) => $pagina->where('horario', null));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch('/panel/horario-de-atencion', [
            'desde' => '08:00',
            'hasta' => '20:00',
            'mensaje_horario' => 'Atendemos de 8 a 20.',
            'mensaje_confirmacion' => 'Tu pedido fue recibido.',
        ])
        ->assertRedirect();

    expect(HorarioAtencion::query()->count())->toBe(1);
});

test('saving twice never leaves two rows', function () {
    // ⚠️ Es lo que el panel viejo sí permitía: «nuevo horario» usado dos veces dejaba dos
    // filas, y la aplicación leía la primera. Acá no hay forma de tener una segunda.
    foreach (['08:00', '09:00', '10:00'] as $desde) {
        $this->actingAs(usuarioCon(Rol::EMPLEADO))
            ->patch('/panel/horario-de-atencion', [
                'desde' => $desde,
                'hasta' => '20:00',
                'mensaje_horario' => 'Un mensaje.',
                'mensaje_confirmacion' => 'Otro mensaje.',
            ]);
    }

    expect(HorarioAtencion::query()->count())->toBe(1)
        ->and(substr(HorarioAtencion::actual()->desde, 0, 5))->toBe('10:00');
});

test('an hour that is not an hour does not get in', function (string $desde) {
    // ⚠️ La columna es TIME; en el sistema viejo era texto y el panel no validaba el
    // formato, así que podía quedar cualquier cosa guardada ahí.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch('/panel/horario-de-atencion', [
            'desde' => $desde,
            'hasta' => '20:00',
            'mensaje_horario' => 'Un mensaje.',
            'mensaje_confirmacion' => 'Otro mensaje.',
        ])
        ->assertSessionHasErrors('desde');

    expect(HorarioAtencion::query()->count())->toBe(0);
})->with([
    'un texto' => 'a la mañana',
    'fuera de rango' => '25:00',
    'sin minutos' => '8',
]);

test('a shift that crosses midnight is allowed, and read right', function () {
    // ⚠️ De 22:00 a 02:00 no se evalúa con desde <= ahora <= hasta. Hoy no hay ninguno
    // así, pero el esquema no lo impide y cambiar el horario es esta pantalla.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch('/panel/horario-de-atencion', [
            'desde' => '22:00',
            'hasta' => '02:00',
            'mensaje_horario' => 'Atendemos de noche.',
            'mensaje_confirmacion' => 'Recibido.',
        ])
        ->assertSessionHasNoErrors();

    $horario = HorarioAtencion::actual();

    expect($horario->estaAbierto(Carbon::parse('23:30')))->toBeTrue()
        ->and($horario->estaAbierto(Carbon::parse('01:00')))->toBeTrue()
        ->and($horario->estaAbierto(Carbon::parse('12:00')))->toBeFalse();
});

test('the screen says whether it is open right now', function () {
    HorarioAtencion::factory()->create([
        'desde' => '00:00',
        'hasta' => '23:59',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/horario-de-atencion')
        ->assertInertia(fn ($pagina) => $pagina->where('horario.abierto_ahora', true));
});

test('a shop cannot save it either, posting straight at the route', function () {
    HorarioAtencion::factory()->create();

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->patch('/panel/horario-de-atencion', [
            'desde' => '00:00',
            'hasta' => '23:59',
            'mensaje_horario' => 'Siempre abierto.',
            'mensaje_confirmacion' => 'Listo.',
        ])
        ->assertForbidden();
});
