<?php

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\Rol;

/*
| La pantalla de un pedido.
|
| Es la mitad de SEC-7 que el listado no cubre: el listado no trae lo que no
| corresponde, y acá llega un id por la URL y hay que preguntar por esa fila. Sin
| esto, un comercio veía el pedido de cualquier otro escribiendo el número.
|
| La negativa es 404 y no 403 a propósito: un 403 le confirma a quien prueba ids que
| ese pedido existe. Es la decisión que ya había tomado el sistema viejo.
*/

test('an employee reaches any order', function () {
    $pedido = Pedido::factory()->create(['numero' => 947745]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('pedidos/show')
            ->where('pedido.id', $pedido->id)
            ->where('pedido.numero', 947745)
        );
});

test('a shop reaches its own order', function () {
    $comercio = Cliente::factory()->create();
    $pedido = Pedido::factory()->create(['cliente_id' => $comercio->id]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertSuccessful();
});

test('a shop gets 404 on another shop order, not 403', function () {
    // ⚠️ El caso central. 403 diría «existe y no es tuyo»; 404 no dice nada, y un
    // comercio puede probar ids a mano.
    $comercio = Cliente::factory()->create();
    $otro = Cliente::factory()->create();
    $ajeno = Pedido::factory()->create(['cliente_id' => $otro->id]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->get("/panel/pedidos/{$ajeno->id}")
        ->assertNotFound();
});

test('a shop with no shop assigned gets 404, not every order', function () {
    $pedido = Pedido::factory()->create(['cliente_id' => Cliente::factory()]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertNotFound();
});

test('a deleted order is not reachable either', function () {
    // Importa más de lo que parece: la oficina se deshace de los pedidos que no
    // llegan al final borrándolos, así que casi todos los estados intermedios de la
    // copia están entre los borrados.
    $pedido = Pedido::factory()->create();
    $pedido->delete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertNotFound();
});

test('a role that is not a panel role does not get in', function () {
    $pedido = Pedido::factory()->create();

    $this->actingAs(usuarioCon('cadete'))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertForbidden();
});

test('a guest is sent to log in', function () {
    $pedido = Pedido::factory()->create();

    $this->get("/panel/pedidos/{$pedido->id}")->assertRedirect(route('login'));
});

test('the listing filter and the detail rule agree on every order', function () {
    // El mismo contrato que ya tiene el listado, del otro lado: lo que el listado
    // muestra es exactamente lo que el detalle deja abrir. Si las dos reglas se
    // separan, una de las dos miente.
    $comercio = Cliente::factory()->create();
    $otro = Cliente::factory()->create();

    $pedidos = collect([
        Pedido::factory()->create(['cliente_id' => $comercio->id]),
        Pedido::factory()->create(['cliente_id' => $otro->id]),
        Pedido::factory()->create(['cliente_id' => null]),
    ]);

    foreach ([Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO] as $rol) {
        $user = usuarioCon($rol, $rol === Rol::RESTRINGIDO ? $comercio : null);
        $alcanzables = Pedido::query()->alcanzablesPor($user)->pluck('id')->all();

        foreach ($pedidos as $pedido) {
            $esperado = in_array($pedido->id, $alcanzables, true);

            $this->actingAs($user)
                ->get("/panel/pedidos/{$pedido->id}")
                ->assertStatus($esperado ? 200 : 404);
        }
    }
});

test('the history comes oldest first, which is the order it happened in', function () {
    $pedido = Pedido::factory()->create(['estado' => Pedido::ESTADO_FINALIZADO]);

    // Se crean al revés para que el orden no salga del orden de inserción.
    $ultimo = LogEstadoPedido::factory()->create([
        'pedido_id' => $pedido->id,
        'estado' => Pedido::ESTADO_FINALIZADO,
        'created_at' => now()->subMinutes(5),
    ]);
    $primero = LogEstadoPedido::factory()->create([
        'pedido_id' => $pedido->id,
        'estado' => Pedido::ESTADO_SIN_ASIGNAR,
        'created_at' => now()->subHours(2),
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedido.logs.0.id', $primero->id)
            ->where('pedido.logs.1.id', $ultimo->id)
        );
});

test('an order with no history still shows its state', function () {
    // Pasa de verdad: el registro de estados es posterior a muchos pedidos viejos.
    $pedido = Pedido::factory()->create(['estado' => Pedido::ESTADO_FINALIZADO]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedido.estado', Pedido::ESTADO_FINALIZADO)
            ->count('pedido.logs', 0)
        );
});

test('the money stays a string, so no cents are lost on the way out', function () {
    $pedido = Pedido::factory()->create([
        'valor' => '1234.56',
        'garantia' => '99.90',
        'valor_declarado' => '10000.00',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedido.valor', '1234.56')
            ->where('pedido.garantia', '99.90')
            ->where('pedido.valor_declarado', '10000.00')
        );
});

test('the cadete travels as a movil number and the client as a name', function () {
    $cliente = Cliente::factory()->create(['numero' => 4101]);
    $cadete = Cadete::factory()->create(['numero_movil' => 68]);
    $pedido = Pedido::factory()->create([
        'cliente_id' => $cliente->id,
        'cadete_id' => $cadete->id,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/pedidos/{$pedido->id}")
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedido.movil', 68)
            ->where('pedido.cliente_numero', 4101)
            ->where('pedido.cliente', $cliente->nombre_mostrado)
        );
});

test('the detail does not run a query per log', function () {
    $pedido = Pedido::factory()->create();
    LogEstadoPedido::factory()->count(20)->create(['pedido_id' => $pedido->id]);

    $user = usuarioCon(Rol::EMPLEADO);

    DB::enableQueryLog();

    $this->actingAs($user)->get("/panel/pedidos/{$pedido->id}")->assertSuccessful();

    // Se cuenta por tabla y no el total: un total deja un número arbitrario que nadie
    // sabe defender cuando sube. Los 20 logs tienen que salir en una sola consulta.
    $consultas = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "logs_estados_pedidos"'))
        ->count();

    DB::disableQueryLog();

    expect($consultas)->toBe(1);
});
