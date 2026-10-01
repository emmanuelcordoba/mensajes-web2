<?php

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;

/*
| La primera pantalla del panel. Lo que se prueba acá es sobre todo SEC-7, que tiene
| dos mitades: el middleware decide quién entra a la ruta y la policy decide a qué
| fila llega. El listado usa una tercera forma —un filtro en la consulta—, y las tres
| tienen que coincidir.
*/

test('a user with no role gets 403, not a 500', function () {
    // Hay 20 usuarios activos sin rol_id en producción, y el middleware viejo leía una
    // propiedad de null hasta que se arregló.
    $this->actingAs(User::factory()->create())
        ->get('/panel/pedidos')
        ->assertForbidden();
});

test('a role that is not a panel role gets 403 too', function () {
    // `cadete`, `cliente_app` y `cliente_api` existen en la tabla y no entran al panel.
    $this->actingAs(usuarioCon('cadete'))
        ->get('/panel/pedidos')
        ->assertForbidden();
});

test('a guest is sent to log in', function () {
    $this->get('/panel/pedidos')->assertRedirect(route('login'));
});

test('the queue shows what is waiting, in the order the office works in', function () {
    $viejo = Pedido::factory()->enEstado(Pedido::ESTADO_SIN_ASIGNAR)
        ->create(['updated_at' => now()->subHours(3)]);
    $nuevo = Pedido::factory()->enEstado(Pedido::ESTADO_ACEPTADO)
        ->create(['updated_at' => now()->subHour()]);
    $cancelado = Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)
        ->create(['updated_at' => now()->subHours(5)]);

    // En curso no espera a nadie, y cotizado espera al cliente: ninguno entra.
    Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create();
    Pedido::factory()->enEstado(Pedido::ESTADO_COTIZADO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('pedidos/index')
            ->where('pedidos.total', 3)
            ->where('pedidos.data.0.id', $cancelado->id)
            ->where('pedidos.data.1.id', $viejo->id)
            ->where('pedidos.data.2.id', $nuevo->id)
        );
});

test('a shop only sees its own orders in the listing', function () {
    // SEC-7 por el lado del listado: no alcanza con comprobar fila por fila, porque el
    // listado no pregunta por cada una.
    $comercio = Cliente::factory()->create();
    $otro = Cliente::factory()->create();

    $propio = Pedido::factory()->create(['cliente_id' => $comercio->id]);
    Pedido::factory()->create(['cliente_id' => $otro->id]);
    Pedido::factory()->create(['cliente_id' => null]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->get('/panel/pedidos')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $propio->id)
        );
});

test('a shop with no shop assigned sees none, not all', function () {
    Pedido::factory()->count(3)->create(['cliente_id' => Cliente::factory()]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/pedidos')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('pedidos.total', 0));
});

test('the listing filter and the per-row rule agree on every order', function () {
    // Son dos implementaciones de la misma regla —una en SQL y otra en PHP— y si se
    // separan vuelve SEC-7 por uno de los dos lados. Esta prueba las compara pedido
    // por pedido.
    $comercio = Cliente::factory()->create();
    $otro = Cliente::factory()->create();

    $pedidos = collect([
        Pedido::factory()->create(['cliente_id' => $comercio->id]),
        Pedido::factory()->create(['cliente_id' => $otro->id]),
        Pedido::factory()->create(['cliente_id' => null]),
    ]);

    $usuarios = [
        usuarioCon(Rol::ADMIN),
        usuarioCon(Rol::EMPLEADO),
        usuarioCon(Rol::RESTRINGIDO, $comercio),
        usuarioCon(Rol::RESTRINGIDO),
    ];

    foreach ($usuarios as $usuario) {
        $porLaConsulta = Pedido::query()->alcanzablesPor($usuario)->pluck('id')->sort()->values();
        $porLaRegla = $pedidos->filter(fn (Pedido $p): bool => $usuario->alcanzaPedido($p))
            ->pluck('id')->sort()->values();

        expect($porLaConsulta->all())->toBe($porLaRegla->all());
    }
});

test('the money in the listing stays a string, so no cents are lost on the way out', function () {
    Pedido::factory()->create(['valor' => '1234.56']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('pedidos.data.0.valor', '1234.56'));
});

test('the listing does not run a query per row for the client name', function () {
    Cliente::factory()->count(5)->create()->each(
        fn (Cliente $cliente) => Pedido::factory()->create(['cliente_id' => $cliente->id]),
    );

    $usuario = usuarioCon(Rol::EMPLEADO);

    // El modelo viejo resolvía esto con $with en TODA consulta, que es el otro extremo.
    DB::enableQueryLog();

    $this->actingAs($usuario)->get('/panel/pedidos')->assertOk();

    $consultas = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "clientes"'))
        ->count();

    DB::disableQueryLog();

    // Una sola para los cinco clientes.
    expect($consultas)->toBe(1);
});
