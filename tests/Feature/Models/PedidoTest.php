<?php

use App\Models\Cliente;
use App\Models\Pedido;
use Illuminate\Database\QueryException;

/*
| El pedido. Lo que se prueba acá es sobre todo lo que el esquema nuevo impide:
| DATA-1 —los 11.295 números repetidos— y los estados sueltos.
*/

test('the number comes from a sequence, which is what DATA-1 was about', function () {
    $primero = Pedido::factory()->create();
    $segundo = Pedido::factory()->create();

    // El sistema viejo leía el máximo de casi un millón de documentos y tenía una
    // condición de carrera: 11.295 números repetidos entre 23.524 pedidos. Una
    // secuencia es atómica.
    expect($segundo->numero)->toBe($primero->numero + 1);
});

test('the order number is deliberately not unique, because the duplicates migrate', function () {
    $primero = Pedido::factory()->create();

    // ⚠️ A diferencia de clientes.numero, la columna NO es UNIQUE: los 23.524
    // pedidos con número repetido se migran tal cual. Esta prueba está para que
    // el día que alguien agregue el UNIQUE sepa qué se rompe.
    $repetido = Pedido::factory()->create(['numero' => $primero->numero]);

    expect($repetido->numero)->toBe($primero->numero);
});

test('only the eight known states are accepted', function () {
    foreach (Pedido::ESTADOS as $estado) {
        expect(Pedido::factory()->enEstado($estado)->create()->estado)->toBe($estado);
    }

    expect(fn () => Pedido::factory()->enEstado('Entregado')->create())
        ->toThrow(QueryException::class);
});

test('only the three known origins are accepted', function () {
    expect(fn () => Pedido::factory()->create(['plataforma_origen' => 'whatsapp']))
        ->toThrow(QueryException::class);
});

test('money keeps its cents, so it is not cast to a float', function () {
    $pedido = Pedido::factory()->create(['valor' => '1234.56', 'garantia' => '0.07']);

    // NUMERIC(12,2) llega como string a propósito: castearlo a float es donde
    // aparecen los centavos perdidos.
    expect($pedido->fresh()->valor)->toBeString()->toBe('1234.56')
        ->and($pedido->fresh()->garantia)->toBe('0.07');
});

test('the recipient can come from a linked client or from a loose name', function () {
    // El 57% de los pedidos no vincula un cliente: los carga el panel con el
    // nombre suelto. No es un error a normalizar.
    $delPanel = Pedido::factory()->create(['nombre_cliente' => 'Juan Gómez']);

    expect($delPanel->cliente_id)->toBeNull()
        ->and($delPanel->nombreDelCliente())->toBe('Juan Gómez');

    $cliente = Cliente::factory()->create(['nombre' => 'Rotisería El Buen Sabor']);
    $deLaApp = Pedido::factory()->create(['cliente_id' => $cliente->id, 'nombre_cliente' => null]);

    expect($deLaApp->load('cliente')->nombreDelCliente())->toBe('Rotisería El Buen Sabor');
});

test('the client may only cancel while no cadete is involved', function () {
    // API-3: la app aceptaba y cancelaba un pedido en cualquier estado.
    foreach (Pedido::ESTADOS_QUE_EL_CLIENTE_PUEDE_CANCELAR as $estado) {
        expect(Pedido::factory()->enEstado($estado)->make()->loPuedeCancelarElCliente())->toBeTrue();
    }

    foreach ([Pedido::ESTADO_ASIGNADO, Pedido::ESTADO_EN_CURSO, Pedido::ESTADO_FINALIZADO] as $estado) {
        expect(Pedido::factory()->enEstado($estado)->make()->loPuedeCancelarElCliente())->toBeFalse();
    }
});

test('the panel queue puts the resolved ones first and the oldest waiting one next', function () {
    $viejo = Pedido::factory()->enEstado(Pedido::ESTADO_SIN_ASIGNAR)
        ->create(['updated_at' => now()->subHours(3)]);
    $nuevo = Pedido::factory()->enEstado(Pedido::ESTADO_ACEPTADO)
        ->create(['updated_at' => now()->subHour()]);
    $cancelado = Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)
        ->create(['updated_at' => now()->subHours(5)]);
    $rechazado = Pedido::factory()->enEstado(Pedido::ESTADO_RECHAZADO)
        ->create(['updated_at' => now()->subHours(2)]);

    // Cotizado espera al cliente, no a un cadete: queda afuera, igual que en el
    // sistema viejo.
    $cotizado = Pedido::factory()->enEstado(Pedido::ESTADO_COTIZADO)->create();
    $enCurso = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create();

    $cola = Pedido::query()->esperandoCadete()->pluck('id')->all();

    expect($cola)->toBe([
        // Los resueltos primero, el más reciente arriba.
        $rechazado->id,
        $cancelado->id,
        // Después los que esperan, el más viejo primero: es el que hay que atender.
        $viejo->id,
        $nuevo->id,
    ])
        ->and($cola)->not->toContain($cotizado->id)
        ->and($cola)->not->toContain($enCurso->id);
});

test('a soft deleted order stays out of the way but keeps its number', function () {
    $pedido = Pedido::factory()->create();
    $numero = $pedido->numero;

    $pedido->delete();

    expect(Pedido::query()->count())->toBe(0)
        ->and(Pedido::withTrashed()->first()->numero)->toBe($numero);
});
