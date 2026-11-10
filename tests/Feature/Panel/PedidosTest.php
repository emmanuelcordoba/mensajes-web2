<?php

use App\Models\Cadete;
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

/*
| El criterio del listado, que es el de `/pedidos` del sistema viejo.
|
| ⚠️ ESTA PANTALLA NO ES LA LISTA DE ESPERA, y hasta el 2026-11-03 lo era: mostraba
| `esperandoCadete()`, o sea la tercera columna de Inicio. La cola estaba dos veces y el
| listado general no estaba en ninguna parte. Los tests de la cola viven en
| `ListaDeEsperaTest`; los de acá fijan lo otro.
*/

test('it lists the last month and leaves out the ones waiting to be assigned', function () {
    $finalizado = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['created_at' => now()->subDays(3)]);
    $enCurso = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)
        ->create(['created_at' => now()->subDay()]);

    // «Sin asignar» se atiende en la lista de espera de Inicio: si apareciera también
    // acá, la oficina lo vería dos veces y en dos órdenes distintos.
    Pedido::factory()->enEstado(Pedido::ESTADO_SIN_ASIGNAR)->create();

    // Y lo de hace dos meses queda fuera del rango por defecto.
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['created_at' => now()->subMonths(2)]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('pedidos/index')
            ->where('pedidos.total', 2)
            // Del más nuevo al más viejo, por fecha de alta.
            ->where('pedidos.data.0.id', $enCurso->id)
            ->where('pedidos.data.1.id', $finalizado->id)
        );
});

test('a range given by hand replaces the last month', function () {
    $dentro = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['created_at' => now()->subMonths(6)]);
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['created_at' => now()->subDay()]);

    $dia = $dentro->created_at->toDateString();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?desde='.$dia.'&hasta='.$dia)
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $dentro->id)
        );
});

test('a date that is not a date falls back to the last month instead of blowing up', function () {
    // El sistema viejo hace `Carbon::createFromFormat` sin red: `?desde=hola` es un 500.
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?desde=hola&hasta=1990-05-11')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('pedidos.total', 1));
});

test('it searches by client, and a search is not a filter on top of another', function () {
    $cliente = Cliente::factory()->create(['nombre' => 'Panadería La Esquina']);
    $suyo = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['cliente_id' => $cliente->id]);
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['cliente_id' => Cliente::factory()->create(['nombre' => 'Otra cosa'])->id]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?campo=cliente&buscar=esquina')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $suyo->id)
        );
});

test('an order with no client is searched by the name written on the row', function () {
    // Son dos mundos que no se pisan: el pedido que TIENE cliente se busca por el
    // nombre del cliente, y el que no, por el nombre suelto de la fila.
    $suelto = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['cliente_id' => null, 'nombre_cliente' => 'Doña Rosa']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?campo=cliente&buscar=rosa')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $suelto->id)
        );
});

test('searching by addresses looks at both ends of the trip', function () {
    $porOrigen = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['direccion' => 'Muñecas 500', 'destino' => 'otra']);
    $porDestino = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['direccion' => 'otra', 'destino' => 'Muñecas 900']);
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['direccion' => 'Corrientes 1', 'destino' => 'Salta 2']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?campo=direccion&buscar=muñecas')
        ->assertInertia(fn ($pagina) => $pagina->where('pedidos.total', 2));

    expect([$porOrigen->id, $porDestino->id])->toHaveCount(2);
});

test('it searches a cadete by name and by the number on the bike', function (string $buscado) {
    $cadete = Cadete::factory()->create([
        'nombres' => 'Rodrigo', 'apellidos' => 'Giménez', 'numero_movil' => 777,
    ]);
    $suyo = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['cadete_id' => $cadete->id]);
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create(['cadete_id' => null]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?campo=cadete&buscar='.$buscado)
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $suyo->id)
        );
})->with([
    'por el nombre' => 'rodrigo',
    'por el apellido' => 'giménez',
    'por el móvil' => '777',
]);

/*
| El filtro por estado.
|
| ⚠️ «Cancelado» ES la cancelación automática: el único lugar del sistema que escribe ese
| estado es `CancelarPedidoSinConfirmar`, el reloj de los cinco minutos, y en el sistema
| viejo era su único `Jobs\CancelarPedido`. Por eso no hay un filtro aparte para ella. Lo
| que sí hay que tener presente es que mira el estado de HOY: la cancelación devuelve el
| pedido a la lista de espera, así que del último mes de producción 760 pedidos pasaron por
| una y 756 están Finalizados. Este filtro trae los que quedaron cancelados.
*/

test('the state filter narrows the listing to that one state', function () {
    $cancelado = Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)->create();
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create();
    Pedido::factory()->enEstado(Pedido::ESTADO_RECHAZADO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?estado='.urlencode(Pedido::ESTADO_CANCELADO))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $cancelado->id)
            // Y vuelve elegido, para que el formulario no se olvide de lo que se pidió.
            ->where('filtros.estado', Pedido::ESTADO_CANCELADO)
        );
});

test('the filter keeps the date range instead of replacing it', function () {
    // Un filtro no es un criterio nuevo: es uno más sobre el del listado.
    Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)
        ->create(['created_at' => now()->subMonths(6)]);
    $dentro = Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)
        ->create(['created_at' => now()->subDay()]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?estado='.urlencode(Pedido::ESTADO_CANCELADO))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $dentro->id)
        );
});

test('a state that is not one of the seven is ignored instead of emptying the listing', function () {
    // La misma red que la de las fechas: `?estado=hola` devuelve el recorte entero y no
    // cero filas ni un 500.
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create();
    Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?estado=hola')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 2)
            ->where('filtros.estado', '')
        );
});

test('a state the listing does not show cannot be reached through the URL', function () {
    // «Sin asignar» existe, pero se atiende en la lista de espera de Inicio. Pedirlo por
    // la URL no lo trae: cae al recorte entero, que es justamente el que lo deja afuera.
    Pedido::factory()->enEstado(Pedido::ESTADO_SIN_ASIGNAR)->create();
    $finalizado = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos?estado='.urlencode(Pedido::ESTADO_SIN_ASIGNAR))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $finalizado->id)
        );
});

test('the seven states come from the server, so the filter cannot offer one the query drops', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('estados', [
                Pedido::ESTADO_COTIZADO,
                Pedido::ESTADO_ACEPTADO,
                Pedido::ESTADO_ASIGNADO,
                Pedido::ESTADO_EN_CURSO,
                Pedido::ESTADO_RECHAZADO,
                Pedido::ESTADO_CANCELADO,
                Pedido::ESTADO_FINALIZADO,
            ])
        );
});

test('the state filter does not reach a shop, which keeps seeing all of its orders', function () {
    // La rama del comercio no filtra por nada, así que un estado en la URL no le recorta
    // el listado: ve los suyos, como en el panel viejo.
    $comercio = Cliente::factory()->create();

    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['cliente_id' => $comercio->id]);
    Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)
        ->create(['cliente_id' => $comercio->id]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->get('/panel/pedidos?estado='.urlencode(Pedido::ESTADO_CANCELADO))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('pedidos.total', 2));
});

test('a shop sees all of its orders, with no cut by state or by date', function () {
    // ⚠️ El sistema viejo le muestra a un comercio TODOS sus pedidos: la rama del
    // `restringido` no filtra por estado ni por fecha. Un comercio que entra a ver un
    // pedido de hace tres meses lo encuentra.
    $comercio = Cliente::factory()->create();

    $viejo = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
        ->create(['cliente_id' => $comercio->id, 'created_at' => now()->subMonths(6)]);
    $sinAsignar = Pedido::factory()->enEstado(Pedido::ESTADO_SIN_ASIGNAR)
        ->create(['cliente_id' => $comercio->id]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->get('/panel/pedidos')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('pedidos.total', 2)
            // Y no le llega el formulario de búsqueda, como en el panel viejo.
            ->where('puede_buscar', false)
        );

    expect([$viejo->id, $sinAsignar->id])->toHaveCount(2);
});

test('the office does get the search form', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('puede_buscar', true));
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
    // En un estado que el listado muestra: el del factory es «Sin asignar», que no entra.
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create(['valor' => '1234.56']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('pedidos.data.0.valor', '1234.56'));
});

test('the listing does not run a query per row for the client name', function () {
    Cliente::factory()->count(5)->create()->each(
        fn (Cliente $cliente) => Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)
            ->create(['cliente_id' => $cliente->id]),
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
