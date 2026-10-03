<?php

use App\Models\Cadete;
use App\Models\MotivoBloqueo;
use App\Models\Rol;
use App\Models\User;

/*
| La cola, y el mostrador por donde se entra a ella.
|
| Lo que esta pantalla tiene que garantizar: que entre el cadete y no cualquiera que sepa
| un número de móvil, que no entre quien no puede —bloqueado, con la cuota vencida, o
| trabajando desde la app—, y que quien ya está en la cola no pierda su lugar por volver a
| intentar.
*/

/** Un cadete inactivo con su cuenta y una contraseña conocida. */
function unCadeteDeLaCola(array $atributos = [], string $password = 'la-del-cadete-123'): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $cuenta = User::factory()->create(['rol_id' => $rol->id, 'password' => $password]);

    return Cadete::factory()->create(array_merge(
        ['estado' => Cadete::ESTADO_INACTIVO, 'orden_cola' => null],
        $atributos,
        ['user_id' => $cuenta->id],
    ));
}

test('an employee reaches the counter and a shop does not', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cola')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('cola/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/cadetes/cola')
        ->assertForbidden();
});

test('the queue is listed in arrival order, with its positions', function () {
    Cadete::factory()->enLaCola(now()->subMinutes(5)->getTimestamp())->create(['numero_movil' => 11]);
    Cadete::factory()->enLaCola(now()->subMinute()->getTimestamp())->create(['numero_movil' => 22]);
    Cadete::factory()->create(['estado' => Cadete::ESTADO_INACTIVO]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cola')
        ->assertInertia(function ($pagina) {
            $cola = $pagina->toArray()['props']['cola'];

            expect($cola)->toHaveCount(2)
                ->and($cola[0]['movil'])->toBe(11)
                ->and($cola[0]['posicion'])->toBe(1)
                ->and($cola[1]['movil'])->toBe(22)
                ->and($cola[1]['posicion'])->toBe(2);
        });
});

test('the queue does not carry what the counter does not need', function () {
    // ⚠️ Esta pantalla la mira cualquiera que pase por el mostrador: la ubicación en vivo,
    // el fcm_token y los cinco montos de cobranza no tienen nada que hacer acá.
    Cadete::factory()->enLaCola()->create([
        'fcm_token' => 'un-token',
        'ubicacion_lat' => -26.8,
        'monto_deuda' => '500.00',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cola')
        ->assertInertia(fn ($pagina) => expect(
            array_keys($pagina->toArray()['props']['cola'][0]),
        )->toBe(['posicion', 'movil', 'vehiculo', 'en_la_app']));
});

test('with its mobile and its password the cadete gets in', function () {
    $cadete = unCadeteDeLaCola(['numero_movil' => 44]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cola')
        ->post('/panel/cadetes/cola', [
            'numero_movil' => 44,
            'password' => 'la-del-cadete-123',
        ])
        ->assertRedirect('/panel/cadetes/cola')
        ->assertSessionHas('success');

    $cadete->refresh();

    expect($cadete->estado)->toBe(Cadete::ESTADO_ACTIVO_WEB)
        ->and($cadete->orden_cola)->not->toBeNull();
});

test('the wrong password does not get in, and neither does a mobile that is not there', function (array $cuerpo, string $campo) {
    unCadeteDeLaCola(['numero_movil' => 44]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cola')
        ->post('/panel/cadetes/cola', $cuerpo)
        ->assertSessionHasErrors($campo);

    expect(Cadete::query()->where('estado', Cadete::ESTADO_ACTIVO_WEB)->count())->toBe(0);
})->with([
    'contraseña equivocada' => [['numero_movil' => 44, 'password' => 'otra-cosa'], 'password'],
    'móvil que no existe' => [['numero_movil' => 999, 'password' => 'la-del-cadete-123'], 'numero_movil'],
]);

test('a cadete whose account is gone cannot get in either', function () {
    // Pasa aunque `cadetes.user_id` sea NOT NULL: `User` usa bajas lógicas, así que no hay
    // contra qué comparar la contraseña.
    $cadete = unCadeteDeLaCola(['numero_movil' => 44]);
    $cadete->user?->delete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cola')
        ->post('/panel/cadetes/cola', ['numero_movil' => 44, 'password' => 'la-del-cadete-123'])
        ->assertSessionHasErrors('password');
});

test('a blocked cadete is refused, and told to go to the office', function () {
    $cadete = unCadeteDeLaCola(['numero_movil' => 44]);
    $cadete->user?->update(['bloqueado' => MotivoBloqueo::FALTA_DE_PAGO]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cola')
        ->post('/panel/cadetes/cola', ['numero_movil' => 44, 'password' => 'la-del-cadete-123'])
        ->assertSessionHas('danger', fn (string $motivo): bool => str_contains($motivo, 'bloqueado'));

    expect($cadete->refresh()->estado)->toBe(Cadete::ESTADO_INACTIVO);
});

test('an overdue fee is refused, but the day it expires still gets in', function (string $vencimiento, bool $entra) {
    // ⚠️ El día del vencimiento todavía entra. El sistema viejo comparaba
    // `endOfDay()->lessThan(today())`, que es lo mismo escrito con más pasos.
    $cadete = unCadeteDeLaCola(['numero_movil' => 44, 'fecha_vencimiento' => $vencimiento]);

    $respuesta = $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cola')
        ->post('/panel/cadetes/cola', ['numero_movil' => 44, 'password' => 'la-del-cadete-123']);

    if ($entra) {
        $respuesta->assertSessionHas('success');
    } else {
        $respuesta->assertSessionHas('danger', fn (string $motivo): bool => str_contains($motivo, 'vencida'));
    }

    expect($cadete->refresh()->estado)->toBe(
        $entra ? Cadete::ESTADO_ACTIVO_WEB : Cadete::ESTADO_INACTIVO,
    );
})->with([
    'venció ayer' => [fn () => today()->subDay()->toDateString(), false],
    'vence hoy' => [fn () => today()->toDateString(), true],
    'vence mañana' => [fn () => today()->addDay()->toDateString(), true],
]);

test('one already working from the app is refused, with the order still in its hands', function (string $estado) {
    // ⚠️ El sistema viejo miraba sólo `activo-app`: un cadete con `con-pedido-app` entraba
    // por el mostrador, perdía ese estado y el pedido quedaba en curso con el cadete
    // puesto en la cola web.
    $cadete = unCadeteDeLaCola(['numero_movil' => 44, 'estado' => $estado]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cola')
        ->post('/panel/cadetes/cola', ['numero_movil' => 44, 'password' => 'la-del-cadete-123'])
        ->assertSessionHas('danger', fn (string $motivo): bool => str_contains($motivo, 'app'));

    expect($cadete->refresh()->estado)->toBe($estado);
})->with([
    'activo en la app' => Cadete::ESTADO_ACTIVO_APP,
    'con un pedido en la mano' => Cadete::ESTADO_CON_PEDIDO_APP,
]);

test('somebody already in the queue does not lose their place by trying again', function () {
    // ⚠️ Esto el sistema viejo no lo preguntaba: volvía a escribir `orden_cola` con la
    // hora de ahora, así que quien tocaba dos veces por las dudas se iba al FINAL de la
    // cola sin enterarse.
    $llegadaPrimero = now()->subMinutes(10)->getTimestamp();

    $cadete = unCadeteDeLaCola(['numero_movil' => 44]);
    $cadete->update(['estado' => Cadete::ESTADO_ACTIVO_WEB, 'orden_cola' => $llegadaPrimero]);

    Cadete::factory()->enLaCola(now()->subMinutes(5)->getTimestamp())->create(['numero_movil' => 55]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cola')
        ->post('/panel/cadetes/cola', ['numero_movil' => 44, 'password' => 'la-del-cadete-123'])
        ->assertSessionHas('danger', fn (string $motivo): bool => str_contains($motivo, 'puesto 1'));

    expect($cadete->refresh()->orden_cola)->toBe($llegadaPrimero)
        ->and($cadete->posicionEnCola())->toBe(1);
});

test('a shop cannot post at the counter either', function () {
    unCadeteDeLaCola(['numero_movil' => 44]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->post('/panel/cadetes/cola', ['numero_movil' => 44, 'password' => 'la-del-cadete-123'])
        ->assertForbidden();

    expect(Cadete::query()->where('estado', Cadete::ESTADO_ACTIVO_WEB)->count())->toBe(0);
});
