<?php

use App\Models\Cadete;
use App\Models\Configuracion;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Models\Rol;
use Illuminate\Database\UniqueConstraintViolationException;

/*
| La cobranza por saldo.
|
| Es plata, así que lo que esta pantalla tiene que garantizar es aritmético: que un monto
| no pase por float, que una carga escriba el movimiento Y el saldo o ninguno de los dos,
| que quede firmada, y que nadie cambie de modalidad dejando plata fuera de todo listado.
*/

/** El porcentaje y el costo mínimo con los que se calcula el saldo mínimo. */
function configuracionDeSaldo(string $porcentaje = '50', string $costoMinimo = '1000'): void
{
    Configuracion::query()->updateOrCreate(
        ['nombre' => 'COBRANZA_SALDO_PORCENTAJE'],
        ['titulo' => 'Porcentaje de saldo', 'tipo' => Configuracion::TIPO_NUMERO, 'valor' => $porcentaje],
    );

    Configuracion::query()->updateOrCreate(
        ['nombre' => 'COSTO_MINIMO'],
        ['titulo' => 'Costo mínimo', 'tipo' => Configuracion::TIPO_NUMERO, 'valor' => $costoMinimo],
    );
}

test('an employee reaches the list and a shop does not', function () {
    configuracionDeSaldo();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/saldo')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('cobranzas/saldo/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/cadetes/cobranzas/saldo')
        ->assertForbidden();
});

test('by default it lists the ones who collect by balance', function () {
    configuracionDeSaldo();
    Cadete::factory()->porSaldo()->count(2)->create();
    Cadete::factory()->create(['modalidad_cobranza' => Cadete::COBRANZA_SEMANAL]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/saldo')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['cadetes']['data'],
        )->toHaveCount(2));

    // ⚠️ Los de modalidad semanal se pueden listar a propósito: pasar a alguien de una
    // modalidad a la otra no se podía hacer desde ninguna pantalla del sistema nuevo
    // —PANEL-8—, y acá es donde esa decisión tiene sentido.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/saldo?modalidad=semanal')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['cadetes']['data'],
        )->toHaveCount(1));
});

test('the amounts travel as text, with their cents', function () {
    // ⚠️ Son NUMERIC(12,2). Castearlos a float es donde se pierden los centavos de la
    // deuda de una persona, y es la disciplina de todo el sistema.
    configuracionDeSaldo();
    Cadete::factory()->porSaldo('1234.56')->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/saldo')
        ->assertInertia(function ($pagina) {
            $saldo = $pagina->toArray()['props']['cadetes']['data'][0]['saldo'];

            expect($saldo)->toBeString()->toBe('1234.56');
        });
});

test('loading balance writes the movement and the new balance', function () {
    configuracionDeSaldo();
    $cadete = Cadete::factory()->porSaldo('100.50')->create();
    $empleado = usuarioCon(Rol::EMPLEADO);

    $this->actingAs($empleado)
        ->from("/panel/cadetes/cobranzas/saldo/{$cadete->id}")
        ->post("/panel/cadetes/cobranzas/saldo/{$cadete->id}/cargas", ['monto' => 5000])
        ->assertRedirect("/panel/cadetes/cobranzas/saldo/{$cadete->id}")
        ->assertSessionHas('success');

    $movimiento = MovimientoCobranzaSaldo::query()->sole();

    expect($cadete->refresh()->cobranza_saldo)->toBe('5100.50')
        ->and($movimiento->monto)->toBe('5000.00')
        ->and($movimiento->monto_positivo)->toBeTrue()
        // El saldo ANTES del movimiento, que es como lo guarda la tabla.
        ->and($movimiento->saldo_parcial)->toBe('100.50')
        ->and($movimiento->tipo)->toBe(MovimientoCobranzaSaldo::CARGA_SALDO)
        // ⚠️ PANEL-13: en el sistema viejo `user_id` no estaba en el `fillable` y la
        // asignación masiva lo descartaba, así que ninguno de los 12.827 movimientos
        // migrados dice quién lo hizo. Los que escribe este sistema, sí.
        ->and($movimiento->user_id)->toBe($empleado->id);
});

test('a load below the minimum is refused', function () {
    // Con 50% y un costo mínimo de 1000, el mínimo es 500 redondeado a la centena
    // siguiente: cargar menos deja al cadete igual de frenado que antes de pagar.
    configuracionDeSaldo();
    $cadete = Cadete::factory()->porSaldo('0')->create();

    expect(Cadete::saldoMinimo())->toBe(500.0);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from("/panel/cadetes/cobranzas/saldo/{$cadete->id}")
        ->post("/panel/cadetes/cobranzas/saldo/{$cadete->id}/cargas", ['monto' => 499])
        ->assertSessionHasErrors('monto');

    expect(MovimientoCobranzaSaldo::query()->count())->toBe(0)
        ->and($cadete->refresh()->cobranza_saldo)->toBe('0.00');
});

test('a cadete who collects weekly cannot be loaded with balance', function () {
    // ⚠️ Escribiría un movimiento y un saldo que ninguna pantalla vuelve a mostrar, porque
    // el listado de saldo no lo lista: plata que entra y desaparece.
    configuracionDeSaldo();
    $cadete = Cadete::factory()->create(['modalidad_cobranza' => Cadete::COBRANZA_SEMANAL]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cobranzas/saldo/{$cadete->id}/cargas", ['monto' => 5000])
        ->assertForbidden();

    expect(MovimientoCobranzaSaldo::query()->count())->toBe(0);
});

test('the movements of a cadete are listed, newest first', function () {
    configuracionDeSaldo();
    $cadete = Cadete::factory()->porSaldo()->create();

    MovimientoCobranzaSaldo::factory()->create([
        'cadete_id' => $cadete->id,
        'created_at' => now()->subDay(),
        'monto' => '100.00',
    ]);
    MovimientoCobranzaSaldo::factory()->create([
        'cadete_id' => $cadete->id,
        'created_at' => now(),
        'monto' => '200.00',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/cobranzas/saldo/{$cadete->id}")
        ->assertInertia(function ($pagina) {
            $filas = $pagina->toArray()['props']['movimientos']['data'];

            expect($filas)->toHaveCount(2)
                ->and($filas[0]['monto'])->toBe('200.00')
                ->and($filas[1]['monto'])->toBe('100.00');
        });
});

test('changing the modality is refused while there is money in the middle', function (string $modalidad, array $columnas, string $enElMotivo) {
    // ⚠️ El `switch` del sistema viejo eran dos líneas —invertir y guardar— y no
    // preguntaba nada. Un cadete con saldo a favor que pasaba a semanal dejaba ese saldo
    // en su columna, fuera de todo listado: plata que nadie volvía a ver.
    configuracionDeSaldo();
    $cadete = Cadete::factory()->create(array_merge(['modalidad_cobranza' => $modalidad], $columnas));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/cobranzas/saldo?modalidad=todas')
        ->assertInertia(function ($pagina) use ($enElMotivo) {
            $fila = $pagina->toArray()['props']['cadetes']['data'][0];

            expect($fila['cambio']['permitido'])->toBeFalse()
                ->and($fila['cambio']['motivo'])->toContain($enElMotivo);
        });

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/cobranzas/saldo/{$cadete->id}/modalidad")
        ->assertForbidden();

    expect($cadete->refresh()->modalidad_cobranza)->toBe($modalidad);
})->with([
    'saldo a favor' => [Cadete::COBRANZA_SALDO, ['cobranza_saldo' => '1500.00'], 'a favor'],
    'saldo en contra' => [Cadete::COBRANZA_SALDO, ['cobranza_saldo' => '-20.00'], 'en contra'],
    'deuda semanal' => [Cadete::COBRANZA_SEMANAL, ['monto_deuda' => '800.00'], 'deuda'],
]);

test('with the account closed, the modality does change', function (string $desde, array $columnas, string $hasta) {
    configuracionDeSaldo();
    $cadete = Cadete::factory()->create(array_merge(['modalidad_cobranza' => $desde], $columnas));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cobranzas/saldo')
        ->patch("/panel/cadetes/cobranzas/saldo/{$cadete->id}/modalidad")
        ->assertRedirect('/panel/cadetes/cobranzas/saldo')
        ->assertSessionHas('success');

    expect($cadete->refresh()->modalidad_cobranza)->toBe($hasta);
})->with([
    'de saldo a semanal' => [Cadete::COBRANZA_SALDO, ['cobranza_saldo' => '0.00'], Cadete::COBRANZA_SEMANAL],
    'de semanal a saldo' => [Cadete::COBRANZA_SEMANAL, ['monto_deuda' => '0.00'], Cadete::COBRANZA_SALDO],
    'de semanal a saldo, sin ciclo abierto' => [Cadete::COBRANZA_SEMANAL, ['monto_deuda' => null], Cadete::COBRANZA_SALDO],
]);

test('the percentage is saved from here, and does not erase its own description', function () {
    // ⚠️ Es la configuración que la pantalla de Configuraciones dejó afuera a propósito.
    // Se guarda por la misma ruta y con el mismo validador que las otras ocho; como este
    // formulario no edita la descripción, el controlador no la toca.
    configuracionDeSaldo();

    $porcentaje = Configuracion::query()->where('nombre', 'COBRANZA_SALDO_PORCENTAJE')->sole();
    $porcentaje->update(['descripcion' => 'Lo que hay que tener para seguir tomando pedidos.']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/cadetes/cobranzas/saldo')
        ->patch("/panel/configuraciones/{$porcentaje->id}", ['valor' => '80'])
        ->assertRedirect('/panel/cadetes/cobranzas/saldo');

    $porcentaje->refresh();

    expect($porcentaje->valor)->toBe('80')
        ->and($porcentaje->descripcion)->toBe('Lo que hay que tener para seguir tomando pedidos.');
});

test('the percentage changes what the minimum balance is', function () {
    configuracionDeSaldo('50', '1000');
    expect(Cadete::saldoMinimo())->toBe(500.0);

    configuracionDeSaldo('120', '1000');
    expect(Cadete::saldoMinimo())->toBe(1200.0);
});

test('a shop cannot write at any of the routes', function () {
    configuracionDeSaldo();
    $cadete = Cadete::factory()->porSaldo()->create();
    $comercio = usuarioCon(Rol::RESTRINGIDO);

    $this->actingAs($comercio)->get("/panel/cadetes/cobranzas/saldo/{$cadete->id}")->assertForbidden();
    $this->actingAs($comercio)->post("/panel/cadetes/cobranzas/saldo/{$cadete->id}/cargas", ['monto' => 5000])->assertForbidden();
    $this->actingAs($comercio)->patch("/panel/cadetes/cobranzas/saldo/{$cadete->id}/modalidad")->assertForbidden();

    expect(MovimientoCobranzaSaldo::query()->count())->toBe(0);
});

test('a discount for an order cannot be written twice', function () {
    // ⚠️ No es de esta pantalla, pero es la defensa que hace que estos números signifiquen
    // algo: COB-1 midió 438 cobros dobles por $233.243,64 en el sistema viejo, que no
    // guardaba qué pedido originaba cada descuento. El índice único parcial deja uno solo.
    configuracionDeSaldo();
    $cadete = Cadete::factory()->porSaldo('10000.00')->create();
    $pedido = Pedido::factory()->create(['cadete_id' => $cadete->id]);

    $cadete->cobrarSaldoPedido($pedido);

    expect(fn () => $cadete->cobrarSaldoPedido($pedido))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(MovimientoCobranzaSaldo::query()->where('pedido_id', $pedido->id)->count())->toBe(1);
});
