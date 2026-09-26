<?php

use App\Models\Cadete;
use App\Models\Configuracion;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| La cobranza por saldo, que es donde está COB-1: 438 cobros dobles por $233.243,64
| que el sistema viejo no podía ni impedir ni ver, porque no guardaba qué pedido
| había originado cada descuento.
|
| Las escrituras rechazadas van en DB::transaction(), que abre un savepoint.
*/

beforeEach(function () {
    Configuracion::factory()->numero('COBRANZA_SALDO_PORCENTAJE', '18')->create();
});

test('the amount is computed with decimals, not with floats', function () {
    $pedido = Pedido::factory()->create(['valor' => '1950.00']);

    // El sistema viejo hacía $this->valor * ($porcentaje/100) en punto flotante,
    // sobre la deuda de una persona.
    expect($pedido->montoDeCobranzaSaldo())->toBe('351.00');

    // Un valor que en float daría un arrastre de centavos.
    expect(Pedido::factory()->create(['valor' => '1000.10'])->montoDeCobranzaSaldo())
        ->toBe('180.01');

    // Un pedido sin valor no cuesta nada.
    expect(Pedido::factory()->create(['valor' => null])->montoDeCobranzaSaldo())->toBe('0.00');
});

test('finalising an order discounts the balance and records who did it', function () {
    $cadete = Cadete::factory()->porSaldo('5000.00')->create();
    $pedido = Pedido::factory()->create(['valor' => '1950.00', 'cadete_id' => $cadete->id]);
    $empleado = User::factory()->create();

    $movimiento = $cadete->cobrarSaldoPedido($pedido, $empleado->id);

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->monto)->toBe('351.00')
        ->and($movimiento->esDescuento())->toBeTrue()
        // saldo_parcial es el saldo ANTES del movimiento.
        ->and($movimiento->saldo_parcial)->toBe('5000.00')
        ->and($movimiento->pedido_id)->toBe($pedido->id)
        // PANEL-13: user_id se descartaba en silencio en los 12.827 históricos.
        ->and($movimiento->user_id)->toBe($empleado->id)
        ->and($cadete->fresh()->cobranza_saldo)->toBe('4649.00');
});

test('the same order cannot be charged twice, which is what COB-1 measured', function () {
    $cadete = Cadete::factory()->porSaldo('5000.00')->create();
    $pedido = Pedido::factory()->create(['valor' => '1950.00', 'cadete_id' => $cadete->id]);

    $cadete->cobrarSaldoPedido($pedido);

    // El índice único parcial cobranza_mov_un_descuento_por_pedido deja UN descuento
    // por pedido. Sin esta columna, COB-1 tuvo que reconstruir los cobros dobles por
    // cercanía en el tiempo.
    expect(fn () => DB::transaction(fn () => $cadete->cobrarSaldoPedido($pedido)))
        ->toThrow(QueryException::class);

    // Y el saldo no se movió dos veces.
    expect($cadete->fresh()->cobranza_saldo)->toBe('4649.00')
        ->and(MovimientoCobranzaSaldo::query()->count())->toBe(1);
});

test('a cadete on weekly collection is not charged at all', function () {
    $cadete = Cadete::factory()->create(['modalidad_cobranza' => Cadete::COBRANZA_SEMANAL]);
    $pedido = Pedido::factory()->create(['valor' => '1950.00', 'cadete_id' => $cadete->id]);

    expect($cadete->cobrarSaldoPedido($pedido))->toBeNull()
        ->and(MovimientoCobranzaSaldo::query()->count())->toBe(0);
});

test('several top-ups may exist with no order, because their pedido_id is null', function () {
    $cadete = Cadete::factory()->porSaldo()->create();

    // El índice es parcial: los NULL no chocan entre sí, y por eso las cargas y los
    // movimientos migrados conviven.
    MovimientoCobranzaSaldo::factory()->count(3)->create(['cadete_id' => $cadete->id]);

    expect(MovimientoCobranzaSaldo::query()->whereNull('pedido_id')->count())->toBe(3);
});

test('only the two known movement types are accepted', function () {
    expect(fn () => DB::transaction(
        fn () => MovimientoCobranzaSaldo::factory()->create(['tipo' => 'Ajuste'])
    ))->toThrow(QueryException::class);
});

test('the sign lives in monto_positivo, never in monto', function () {
    $carga = MovimientoCobranzaSaldo::factory()->create(['monto' => '1000.00']);

    expect($carga->monto_positivo)->toBeTrue()
        ->and($carga->esDescuento())->toBeFalse()
        ->and($carga->monto)->toBe('1000.00');
});

test('a cadete movements come back most recent first', function () {
    $cadete = Cadete::factory()->porSaldo()->create();

    $viejo = MovimientoCobranzaSaldo::factory()->create([
        'cadete_id' => $cadete->id, 'created_at' => now()->subDays(2),
    ]);
    $nuevo = MovimientoCobranzaSaldo::factory()->create([
        'cadete_id' => $cadete->id, 'created_at' => now(),
    ]);

    expect($cadete->movimientosCobranzaSaldo()->masRecientesPrimero()->pluck('id')->all())
        ->toBe([$nuevo->id, $viejo->id]);
});
