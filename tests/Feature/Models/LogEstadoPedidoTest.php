<?php

use App\Models\Cadete;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| El historial de estados de un pedido. Es la tabla más grande del sistema: 3.637.456
| filas.
*/

test('the platform vocabulary is not the one pedidos uses', function () {
    // Acá son cuatro y allá tres: el log distingue de cuál de las dos apps vino y el
    // pedido no. Los dos CHECK son distintos a propósito.
    expect(LogEstadoPedido::PLATAFORMAS)->toBe(['web', 'api', 'cliente-app', 'cadete-app']);

    foreach (LogEstadoPedido::PLATAFORMAS as $plataforma) {
        expect(LogEstadoPedido::factory()->create(['plataforma_origen' => $plataforma])->plataforma_origen)
            ->toBe($plataforma);
    }

    // 'app' es válido en pedidos y no acá.
    expect(fn () => DB::transaction(
        fn () => LogEstadoPedido::factory()->create(['plataforma_origen' => 'app'])
    ))->toThrow(QueryException::class);
});

test('each state has its own sentence, and an unknown one gets none', function () {
    expect(LogEstadoPedido::mensajePara(Pedido::ESTADO_ASIGNADO, 52))
        ->toBe('El pedido fue asignado al móvil 52.')
        ->and(LogEstadoPedido::mensajePara(Pedido::ESTADO_SIN_ASIGNAR, null))
        ->toBe('El pedido fue creado sin cadete.')
        // Cuando el cadete ya fue desasociado —al rechazarlo— no hay móvil que
        // nombrar, y el sistema viejo escribía «desconocido».
        ->and(LogEstadoPedido::mensajePara(Pedido::ESTADO_RECHAZADO, null))
        ->toBe('El pedido fue rechazado por el móvil desconocido.')
        // Un estado que no conoce no inventa una frase.
        ->and(LogEstadoPedido::mensajePara('Entregado', 52))->toBeNull();
});

test('registering a change writes one log with the state and the mobile number', function () {
    $cadete = Cadete::factory()->create(['numero_movil' => 52]);
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_ASIGNADO)->create(['cadete_id' => $cadete->id]);
    $empleado = User::factory()->create();

    $log = LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB, userId: $empleado->id);

    expect($log)->not->toBeNull()
        ->and($log->estado)->toBe(Pedido::ESTADO_ASIGNADO)
        ->and($log->mensaje)->toBe('El pedido fue asignado al móvil 52.')
        ->and($log->cadete_id)->toBe($cadete->id)
        ->and($log->user_id)->toBe($empleado->id);
});

test('the same state twice in a row is not recorded twice', function () {
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_SIN_ASIGNAR)->create();

    expect(LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB))->not->toBeNull()
        ->and(LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB))->toBeNull()
        ->and(LogEstadoPedido::query()->count())->toBe(1);

    // Pero un estado distinto sí.
    $pedido->update(['estado' => Pedido::ESTADO_CANCELADO]);

    expect(LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB))->not->toBeNull()
        ->and(LogEstadoPedido::query()->count())->toBe(2);
});

test('the user comes from the caller, not from the session', function () {
    // El método viejo hacía associate(Auth::user()), lo que ataba el registro a que
    // hubiera una sesión: desde un comando o una tarea programada quedaba en null sin
    // que nadie lo decidiera.
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)->create();

    $log = LogEstadoPedido::registrar($pedido, LogEstadoPedido::WEB);

    expect($log->user_id)->toBeNull()
        ->and($log->mensaje)->toBe('El pedido fue cancelado automáticamente.');
});

test('a cadete passed explicitly wins over the one the order still points at', function () {
    // Hace falta al rechazar: el cadete ya fue desasociado del pedido, pero el log
    // tiene que decir quién lo rechazó.
    $quienRechaza = Cadete::factory()->create(['numero_movil' => 77]);
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_RECHAZADO)->create(['cadete_id' => null]);

    $log = LogEstadoPedido::registrar($pedido, LogEstadoPedido::CADETE_APP, $quienRechaza);

    expect($log->cadete_id)->toBe($quienRechaza->id)
        ->and($log->mensaje)->toBe('El pedido fue rechazado por el móvil 77.');
});

test('only the eight known states are accepted here too', function () {
    expect(fn () => DB::transaction(
        fn () => LogEstadoPedido::factory()->create(['estado' => 'Entregado'])
    ))->toThrow(QueryException::class);
});
