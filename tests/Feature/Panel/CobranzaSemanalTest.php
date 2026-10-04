<?php

use App\Models\Cadete;
use App\Models\MontoSemanal;
use App\Models\MotivoBloqueo;
use App\Models\Rol;
use App\Models\User;

/*
| La cobranza semanal.
|
| ⚠️ Esta modalidad está muerta: 521 cadetes abrieron un ciclo alguna vez, 10 registraron un
| pago, y el último fue el 2022-04-09. Se portó igual, y con ella su aritmética —PANEL-21—.
| El test que la congela está abajo y dice por qué: si alguien la arregla, que se entere de
| que cambió una cuenta, en vez de descubrirlo con la gente reclamando.
*/

/** Un cadete de modalidad semanal, con su cuenta. */
function unCadeteSemanal(array $atributos = []): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $cuenta = User::factory()->create(['rol_id' => $rol->id]);

    return Cadete::factory()->create(array_merge(
        ['modalidad_cobranza' => Cadete::COBRANZA_SEMANAL, 'tipo_vehiculo' => Cadete::VEHICULO_BICICLETA],
        $atributos,
        ['user_id' => $cuenta->id],
    ));
}

/** Las dos cuotas generales, que son dos filas y no se crean desde el panel. */
function lasCuotasGenerales(string $bici = '1000.00', string $moto = '1200.00'): void
{
    MontoSemanal::query()->updateOrCreate(['tipo_vehiculo' => Cadete::VEHICULO_BICICLETA], ['monto' => $bici]);
    MontoSemanal::query()->updateOrCreate(['tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA], ['monto' => $moto]);
}

test('an employee reaches the list and a shop does not', function () {
    lasCuotasGenerales();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/semanal')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('cobranzas/semanal/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/cadetes/cobranzas/semanal')
        ->assertForbidden();
});

test('it lists only the ones who collect weekly', function () {
    lasCuotasGenerales();
    unCadeteSemanal();
    Cadete::factory()->porSaldo()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/semanal')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['cadetes']['data'],
        )->toHaveCount(1));
});

test('a cadete with no cycle is sent straight to opening one', function () {
    lasCuotasGenerales();
    $cadete = unCadeteSemanal(['fecha_inicio' => null]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/cobranzas/semanal/{$cadete->id}")
        ->assertRedirect("/panel/cadetes/cobranzas/semanal/{$cadete->id}/ciclo");
});

test('the proposed fee is the one of its vehicle, or its own', function () {
    lasCuotasGenerales('1000.00', '1200.00');

    $general = unCadeteSemanal([
        'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
        'tiene_monto_semanal_personal' => false,
        'fecha_inicio' => null,
    ]);

    $propio = unCadeteSemanal([
        'tiene_monto_semanal_personal' => true,
        'monto_semanal' => '777.00',
        'fecha_inicio' => null,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/cobranzas/semanal/{$general->id}/ciclo")
        ->assertInertia(fn ($pagina) => $pagina->where('propuesta.monto_semanal', '1200.00'));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/cobranzas/semanal/{$propio->id}/ciclo")
        ->assertInertia(fn ($pagina) => $pagina->where('propuesta.monto_semanal', '777.00'));
});

test('opening the first cycle leaves the fee as the debt, and unblocks the account', function () {
    lasCuotasGenerales();
    $cadete = unCadeteSemanal(['fecha_inicio' => null]);
    $cadete->user?->update(['bloqueado' => MotivoBloqueo::FALTA_DE_PAGO]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cobranzas/semanal/{$cadete->id}/ciclo", [
            'fecha_inicio' => today()->toDateString(),
            'fecha_vencimiento' => today()->addWeek()->toDateString(),
            'monto_semanal' => '1000',
            'monto_pagado_efectivo' => '0',
            'monto_pagado_tickets' => '0',
        ])
        ->assertRedirect("/panel/cadetes/cobranzas/semanal/{$cadete->id}");

    $cadete->refresh();

    expect($cadete->monto_semanal)->toBe('1000.00')
        ->and($cadete->monto_deuda)->toBe('1000.00')
        // Abrir un ciclo es reconocer que la cobranza arranca al día, como en el panel
        // viejo: quien estaba bloqueado por falta de pago deja de estarlo.
        ->and($cadete->user?->fresh()?->bloqueado)->toBeNull();
});

test('registering a payment discounts it from the debt, with its cents', function () {
    lasCuotasGenerales();
    $cadete = unCadeteSemanal([
        'fecha_inicio' => today()->subDays(2),
        'fecha_vencimiento' => today()->addDays(5),
        'monto_semanal' => '1000.00',
        'monto_pagado_efectivo' => '0.00',
        'monto_pagado_tickets' => '0.00',
        'monto_deuda' => '1000.00',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from("/panel/cadetes/cobranzas/semanal/{$cadete->id}")
        ->post("/panel/cadetes/cobranzas/semanal/{$cadete->id}/pagos", [
            'fecha_vencimiento' => today()->addDays(5)->toDateString(),
            'monto_efectivo' => '600.50',
            'monto_tickets' => '120',
        ])
        ->assertRedirect("/panel/cadetes/cobranzas/semanal/{$cadete->id}");

    $cadete->refresh();

    expect($cadete->monto_pagado_efectivo)->toBe('600.50')
        ->and($cadete->monto_pagado_tickets)->toBe('120.00')
        ->and($cadete->monto_deuda)->toBe('279.50');
});

test('the fee used for the debt is the stored one, not the one in the body', function () {
    // ⚠️ Es PANEL-9, ya arreglado en el sistema viejo: el formulario mandaba
    // `monto_semanal` como campo de sólo lectura, el método lo usaba para la cuenta y nunca
    // lo guardaba, así que daba bien por accidente. `readonly` es del navegador.
    lasCuotasGenerales();
    $cadete = unCadeteSemanal([
        'fecha_inicio' => today(),
        'fecha_vencimiento' => today()->addWeek(),
        'monto_semanal' => '1000.00',
        'monto_pagado_efectivo' => '0.00',
        'monto_pagado_tickets' => '0.00',
        'monto_deuda' => '1000.00',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cobranzas/semanal/{$cadete->id}/pagos", [
            'fecha_vencimiento' => today()->addWeek()->toDateString(),
            'monto_efectivo' => '100',
            'monto_tickets' => '0',
            // Lo que no se mira.
            'monto_semanal' => '99999',
        ]);

    $cadete->refresh();

    expect($cadete->monto_semanal)->toBe('1000.00')
        ->and($cadete->monto_deuda)->toBe('900.00');
});

test('recognising a week recomputes the debt with the accumulated payments', function () {
    // ⚠️⚠️ ESTE TEST CONGELA UN DEFECTO, A PROPÓSITO. Ver PANEL-21.
    //
    // El formulario de «reconocer semana» viene con los pagos ACUMULADOS, y el método
    // calcula deuda = cuota − efectivo − tickets. Así, el cadete que pagó su semana
    // completa abre la siguiente con deuda 0, y así todas las siguientes.
    //
    // Se portó tal cual por decisión, con la cobranza semanal muerta desde 2022. Si alguien
    // la arregla —que la deuda se arrastre y los pagos vuelvan a 0—, este test falla, y eso
    // es lo que tiene que pasar: que el cambio sea una decisión y no un descubrimiento.
    lasCuotasGenerales();
    $cadete = unCadeteSemanal([
        'fecha_inicio' => today()->subWeek(),
        'fecha_vencimiento' => today(),
        'monto_semanal' => '1000.00',
        'monto_pagado_efectivo' => '1000.00',
        'monto_pagado_tickets' => '0.00',
        'monto_deuda' => '0.00',
    ]);

    // Lo que manda la pantalla al reconocer: los pagos acumulados, tal cual.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cobranzas/semanal/{$cadete->id}/ciclo", [
            'fecha_inicio' => today()->toDateString(),
            'fecha_vencimiento' => today()->addWeek()->toDateString(),
            'monto_semanal' => '1000',
            'monto_pagado_efectivo' => '1000',
            'monto_pagado_tickets' => '0',
        ]);

    // Debería deber la semana nueva. Debe cero.
    expect($cadete->refresh()->monto_deuda)->toBe('0.00');
});

test('the screen arrives at that one pre-filled with the accumulated payments', function () {
    // La otra mitad de PANEL-21: no es que la pantalla mande cualquier cosa, es que llega
    // llena con lo ya pagado. Si esto cambia, el defecto de arriba deja de dispararse.
    lasCuotasGenerales();
    $cadete = unCadeteSemanal([
        'fecha_inicio' => today()->subWeek(),
        'monto_semanal' => '1000.00',
        'monto_pagado_efectivo' => '1000.00',
        'monto_pagado_tickets' => '50.00',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/cobranzas/semanal/{$cadete->id}/ciclo")
        ->assertInertia(fn ($pagina) => $pagina
            ->where('es_el_primero', false)
            ->where('propuesta.monto_pagado_efectivo', '1000.00')
            ->where('propuesta.monto_pagado_tickets', '50.00'));
});

test('an impossible due date does not get in any more', function (string $inicio, string $vencimiento) {
    // ⚠️ Esto SÍ se corrigió al portar, y no es la aritmética de PANEL-21: el validador
    // viejo sólo exigía `before:fecha_vencimiento`, y por eso hay 15 cadetes con
    // vencimientos en 2027, 2029 y 3030. La pantalla del mostrador lee esa fecha para
    // decidir si alguien puede ponerse en la cola.
    lasCuotasGenerales();
    $cadete = unCadeteSemanal(['fecha_inicio' => null]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cobranzas/semanal/{$cadete->id}/ciclo", [
            'fecha_inicio' => $inicio,
            'fecha_vencimiento' => $vencimiento,
            'monto_semanal' => '1000',
            'monto_pagado_efectivo' => '0',
            'monto_pagado_tickets' => '0',
        ])
        ->assertSessionHasErrors();

    expect($cadete->refresh()->fecha_inicio)->toBeNull();
})->with([
    'el año 3030' => ['3030-07-28', '3030-08-04'],
    'vencimiento dentro de mil años' => [fn () => today()->toDateString(), '3030-08-04'],
    'inicio de hace cinco años' => ['2021-04-17', '2021-04-24'],
    'vence antes de empezar' => [fn () => today()->toDateString(), fn () => today()->subDay()->toDateString()],
]);

test('extending gives one more day and unblocks', function () {
    lasCuotasGenerales();
    $cadete = unCadeteSemanal([
        'fecha_inicio' => today()->subWeek(),
        'fecha_vencimiento' => today()->subDay(),
    ]);
    $cadete->user?->update(['bloqueado' => MotivoBloqueo::FALTA_DE_PAGO]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from("/panel/cadetes/cobranzas/semanal/{$cadete->id}")
        ->patch("/panel/cadetes/cobranzas/semanal/{$cadete->id}/vencimiento")
        ->assertRedirect("/panel/cadetes/cobranzas/semanal/{$cadete->id}");

    expect($cadete->refresh()->fecha_vencimiento?->toDateString())->toBe(today()->toDateString())
        ->and($cadete->user?->fresh()?->bloqueado)->toBeNull();
});

test('the two general fees are edited, never created', function () {
    lasCuotasGenerales('1000.00', '1200.00');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cobranzas/semanal')
        ->patch('/panel/cadetes/cobranzas/semanal/cuotas', [
            'montos' => [
                ['tipo_vehiculo' => Cadete::VEHICULO_BICICLETA, 'monto' => '1500'],
                ['tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA, 'monto' => '1800'],
            ],
        ])
        ->assertRedirect('/panel/cadetes/cobranzas/semanal');

    expect(MontoSemanal::query()->count())->toBe(2)
        ->and(MontoSemanal::para(Cadete::VEHICULO_BICICLETA))->toBe('1500.00')
        ->and(MontoSemanal::para(Cadete::VEHICULO_MOTOCICLETA))->toBe('1800.00');
});

test('a fee of zero is refused', function () {
    lasCuotasGenerales('1000.00', '1200.00');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch('/panel/cadetes/cobranzas/semanal/cuotas', [
            'montos' => [
                ['tipo_vehiculo' => Cadete::VEHICULO_BICICLETA, 'monto' => '0'],
                ['tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA, 'monto' => '1800'],
            ],
        ])
        ->assertSessionHasErrors('montos.0.monto');

    expect(MontoSemanal::para(Cadete::VEHICULO_BICICLETA))->toBe('1000.00');
});

test('a shop cannot write at any of the routes', function () {
    lasCuotasGenerales();
    $cadete = unCadeteSemanal(['fecha_inicio' => today(), 'fecha_vencimiento' => today()->addWeek()]);
    $comercio = usuarioCon(Rol::RESTRINGIDO);

    $this->actingAs($comercio)->get("/panel/cadetes/cobranzas/semanal/{$cadete->id}")->assertForbidden();
    $this->actingAs($comercio)->patch("/panel/cadetes/cobranzas/semanal/{$cadete->id}/vencimiento")->assertForbidden();
    $this->actingAs($comercio)->patch('/panel/cadetes/cobranzas/semanal/cuotas', [])->assertForbidden();
});
