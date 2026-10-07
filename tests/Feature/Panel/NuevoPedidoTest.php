<?php

use App\Jobs\CancelarPedidoSinConfirmar;
use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\LogEstadoPedido;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/*
| Inicio — segunda parte: cargar un pedido.
|
| Lo que hay que garantizar es que el pedido quede a nombre de quien corresponde, que el
| departamento entre en la dirección sin perder nada, que el estado dependa de dónde está
| trabajando el cadete —la misma regla que el modal de la lista de espera—, y que una cuenta
| de comercio no pueda poner el precio ni elegir el cadete.
*/

/** Lo mínimo que la oficina manda: el formulario exige dirección o cliente, y el precio. */
function loQueMandaElAlta(array $extra = []): array
{
    return array_merge([
        'direccion' => 'Balcarce 100',
        'destino' => 'Muñecas 500',
        'valor' => '3000.00',
    ], $extra);
}

function unCadeteDelAlta(string $estado, string $modalidad = Cadete::COBRANZA_SEMANAL): Cadete
{
    return Cadete::factory()->create([
        'numero_movil' => 21,
        'estado' => $estado,
        'orden_cola' => 1000,
        'modalidad_cobranza' => $modalidad,
        'cobranza_saldo' => '5000.00',
    ]);
}

function elPorcentaje(string $porcentaje): void
{
    Configuracion::query()->updateOrCreate(
        ['nombre' => 'COBRANZA_SALDO_PORCENTAJE'],
        ['titulo' => 'Porcentaje de cobranza', 'tipo' => 'numero', 'valor' => $porcentaje],
    );
}

/** Un comercio con su cuenta, que es la única forma de que un `restringido` cargue algo. */
function unComercioConCuenta(): array
{
    $comercio = Cliente::factory()->create(['nombre' => 'Farmacia del Pueblo']);

    return [$comercio, usuarioCon(Rol::RESTRINGIDO, $comercio)];
}

// ---------------------------------------------------------------------------- la pantalla

test('the middle column is drawn for the office, with its tools', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('deLaOficina', true)
            // El mapa y el autocompletado necesitan la clave, y la cola el selector.
            ->has('mapa')
            ->has('paraAsignar'));
});

test('a shop gets the form without the price, the cadete or the map', function () {
    // ⚠️ En el panel viejo TODA la sección de JavaScript está dentro de
    // `@if(tieneRol(['admin','empleado']))`, así que un comercio no tiene autocompletado,
    // ni cotización, ni mapa, ni selector de cadete, ni el botón de alta de clientes.
    [, $cuenta] = unComercioConCuenta();

    $this->actingAs($cuenta)
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('deLaOficina', false)
            ->where('mapa', null)
            ->where('cola', null)
            ->where('espera', null)
            ->where('paraAsignar', null));
});

// -------------------------------------------------------------------------------- el alta

test('the office loads an order and it waits for a cadete', function () {
    $empleado = usuarioCon(Rol::EMPLEADO);

    $this->actingAs($empleado)
        ->post('/panel/pedidos', loQueMandaElAlta())
        ->assertRedirect();

    $pedido = Pedido::query()->sole();

    expect($pedido->estado)->toBe(Pedido::ESTADO_SIN_ASIGNAR)
        ->and($pedido->plataforma_origen)->toBe(Pedido::PLATAFORMA_WEB)
        ->and($pedido->direccion)->toBe('Balcarce 100')
        ->and($pedido->destino)->toBe('Muñecas 500')
        // ⚠️ Quién lo cargó se guarda siempre: es de donde sale el distintivo de dos letras
        // de la lista de espera, y en producción está en el 100% de los pedidos web.
        ->and($pedido->user_id)->toBe($empleado->id)
        // El número lo pone la secuencia de PostgreSQL, y hay que poder leerlo para imprimir
        // el comprobante. Ver el trait LeeLoQueEscribeLaBase.
        ->and($pedido->numero)->not->toBeNull();
});

test('loading an order leaves a line in the history', function () {
    $empleado = usuarioCon(Rol::EMPLEADO);

    $this->actingAs($empleado)->post('/panel/pedidos', loQueMandaElAlta());

    $log = LogEstadoPedido::query()->sole();

    expect($log->pedido_id)->toBe(Pedido::query()->sole()->id)
        ->and($log->plataforma_origen)->toBe(LogEstadoPedido::WEB)
        ->and($log->user_id)->toBe($empleado->id);
});

test('the order can be noted with just the client, before knowing the address', function () {
    // El sistema viejo pide `cliente` O `direccion`: se anota el pedido con el número del
    // comercio y el resto se completa después.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', ['cliente' => 'Kiosco de la esquina', 'valor' => '3000'])
        ->assertRedirect();

    expect(Pedido::query()->sole()->nombre_cliente)->toBe('Kiosco de la esquina');
});

test('without a client and without an address it is refused', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', ['valor' => '3000'])
        ->assertSessionHasErrors(['direccion', 'cliente']);

    expect(Pedido::query()->count())->toBe(0);
});

test('the office has to put a price', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', ['direccion' => 'Balcarce 100'])
        ->assertSessionHasErrors('valor');
});

// -------------------------------------------------------------------------- de quién es

test('an order chosen from the list belongs to that shop', function () {
    $comercio = Cliente::factory()->create(['nombre' => 'Farmacia del Centro']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cliente_id' => $comercio->id]));

    $pedido = Pedido::query()->sole();

    expect($pedido->cliente_id)->toBe($comercio->id)
        ->and($pedido->nombre_cliente)->toBeNull();
});

test('a name written by hand is linked when it is the only one with that name', function () {
    // ⚠️ El sistema viejo tenía un `getByNombre` con `first()`: con dos comercios del mismo
    // nombre, el pedido se le colgaba al primero que devolviera la base. Ver DATA-8.
    $comercio = Cliente::factory()->create(['nombre' => 'Farmacia Única']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cliente' => 'farmacia única']));

    expect(Pedido::query()->sole()->cliente_id)->toBe($comercio->id);
});

test('an ambiguous name is not linked to anybody, and the text is kept', function () {
    Cliente::factory()->create(['nombre' => 'Farmacia']);
    Cliente::factory()->create(['nombre' => 'Farmacia']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cliente' => 'Farmacia']));

    $pedido = Pedido::query()->sole();

    expect($pedido->cliente_id)->toBeNull()
        ->and($pedido->nombre_cliente)->toBe('Farmacia');
});

test('an order for somebody who is not a client keeps the name loose', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cliente' => 'Juan por la ventanilla']));

    $pedido = Pedido::query()->sole();

    expect($pedido->cliente_id)->toBeNull()
        ->and($pedido->nombre_cliente)->toBe('Juan por la ventanilla');
});

// --------------------------------------------------------------------- el departamento

test('the flat number goes inside the address, after the street', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))->post('/panel/pedidos', loQueMandaElAlta([
        'direccion' => 'Balcarce 100',
        'direccion_detalle' => '4 B',
        'destino' => 'Muñecas 500',
        'destino_detalle' => 'of 12',
    ]));

    $pedido = Pedido::query()->sole();

    expect($pedido->direccion)->toBe('Balcarce 100 4 B')
        ->and($pedido->destino)->toBe('Muñecas 500 of 12');
});

test('a google address keeps every part, and the old system lost the province', function () {
    // ⚠️ **Esto es lo que hay que no romper.** El sistema viejo hace
    // `explode(', ', $direccion)` y después `"{$partes[0]} {$detalle}, {$partes[1]}"`: con
    // dos partes anda, pero el formato que escribe Google tiene TRES —calle, ciudad,
    // provincia— y la tercera se perdía. O sea que poner un departamento en una dirección
    // elegida del autocompletado le borraba la provincia.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))->post('/panel/pedidos', loQueMandaElAlta([
        'direccion' => 'Balcarce 100, San Miguel de Tucumán, Tucumán',
        'direccion_detalle' => '4 B',
    ]));

    expect(Pedido::query()->sole()->direccion)
        ->toBe('Balcarce 100 4 B, San Miguel de Tucumán, Tucumán');
});

test('without a flat number the address is left exactly as it was written', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))->post('/panel/pedidos', loQueMandaElAlta([
        'direccion' => 'Balcarce 100, San Miguel de Tucumán, Tucumán',
        'direccion_detalle' => '',
    ]));

    expect(Pedido::query()->sole()->direccion)
        ->toBe('Balcarce 100, San Miguel de Tucumán, Tucumán');
});

test('the flat number stops at ten characters, as the old rule says', function () {
    // El campo del formulario viejo tenía `maxlength="255"` y la regla `max:10`, así que se
    // podía escribir lo que después se rechazaba. Acá el campo también frena en 10.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['direccion_detalle' => 'piso 4 depto B']))
        ->assertSessionHasErrors('direccion_detalle');
});

// --------------------------------------------------------------------- y el cadete

test('a cadete taken from the counter finishes the order and leaves the queue', function () {
    $cadete = unCadeteDelAlta(Cadete::ESTADO_ACTIVO_WEB);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cadete_id' => $cadete->id]));

    $pedido = Pedido::query()->sole();

    expect($pedido->estado)->toBe(Pedido::ESTADO_FINALIZADO)
        ->and($pedido->cadete_id)->toBe($cadete->id)
        ->and($cadete->refresh()->estado)->toBe(Cadete::ESTADO_INACTIVO);
});

test('a cadete working from the app gets five minutes to confirm', function () {
    Queue::fake();

    $cadete = unCadeteDelAlta(Cadete::ESTADO_ACTIVO_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cadete_id' => $cadete->id]));

    expect(Pedido::query()->sole()->estado)->toBe(Pedido::ESTADO_ASIGNADO)
        // Sigue en la cola: todavía no se llevó nada.
        ->and($cadete->refresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP);

    Queue::assertPushed(CancelarPedidoSinConfirmar::class);
});

test('a cadete who was not in the queue gets the order finished and is not charged', function () {
    // ⚠️ Es la rama `default` del switch viejo, y con el selector nuevo es alcanzable a
    // propósito: se puede buscar un cadete que no está en la cola y darle el pedido. Lo
    // que NO pasa es cobrarle el saldo, porque no vino del mostrador.
    elPorcentaje('10');

    $cadete = unCadeteDelAlta(Cadete::ESTADO_INACTIVO, Cadete::COBRANZA_SALDO);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cadete_id' => $cadete->id]));

    expect(Pedido::query()->sole()->estado)->toBe(Pedido::ESTADO_FINALIZADO)
        ->and(MovimientoCobranzaSaldo::query()->count())->toBe(0)
        ->and($cadete->refresh()->cobranza_saldo)->toBe('5000.00');
});

test('the cadete who pays by balance is charged when he comes from the counter', function () {
    elPorcentaje('10');

    $cadete = unCadeteDelAlta(Cadete::ESTADO_ACTIVO_WEB, Cadete::COBRANZA_SALDO);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cadete_id' => $cadete->id, 'valor' => '1000.00']));

    $movimiento = MovimientoCobranzaSaldo::query()->sole();

    expect($movimiento->monto)->toBe('100.00')
        ->and($movimiento->pedido_id)->toBe(Pedido::query()->sole()->id)
        ->and($movimiento->monto_positivo)->toBeFalse()
        ->and($cadete->refresh()->cobranza_saldo)->toBe('4900.00');
});

test('a cadete without enough balance is refused, and told how much is missing', function () {
    // La misma función que el selector consulta: una regla, dos lectores.
    elPorcentaje('50');

    $cadete = unCadeteDelAlta(Cadete::ESTADO_ACTIVO_WEB, Cadete::COBRANZA_SALDO);
    $cadete->update(['cobranza_saldo' => '100.00']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/pedidos', loQueMandaElAlta(['cadete_id' => $cadete->id, 'valor' => '1000.00']))
        ->assertSessionHasErrors('cadete_id');

    expect(Pedido::query()->count())->toBe(0)
        ->and(session('errors')->first('cadete_id'))->toContain('500.00');
});

test('the alta and the modal send the order to the same state', function () {
    // ⚠️ El sistema viejo tiene DOS copias del mismo switch —`PedidoController@store` y
    // `Ajax\PedidoController@actualizar`— y ya se separaron entre sí. Acá es una sola
    // función y este test lo fija: el mismo cadete, los dos caminos, el mismo resultado.
    $empleado = usuarioCon(Rol::EMPLEADO);

    $porElAlta = unCadeteDelAlta(Cadete::ESTADO_ACTIVO_WEB);
    $this->actingAs($empleado)->post('/panel/pedidos', loQueMandaElAlta(['cadete_id' => $porElAlta->id]));
    $delAlta = Pedido::query()->sole();

    $porElModal = Cadete::factory()->create([
        'numero_movil' => 22,
        'estado' => Cadete::ESTADO_ACTIVO_WEB,
        'orden_cola' => 1001,
    ]);
    $enEspera = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_SIN_ASIGNAR,
        'plataforma_origen' => Pedido::PLATAFORMA_WEB,
        'direccion' => 'Balcarce 100',
        'valor' => '3000.00',
    ]);

    $this->actingAs($empleado)->patch("/panel/pedidos/{$enEspera->id}/asignacion", [
        'direccion' => 'Balcarce 100',
        'valor' => '3000.00',
        'cadete_id' => $porElModal->id,
    ]);

    expect($enEspera->refresh()->estado)->toBe($delAlta->estado)
        ->and($porElModal->refresh()->estado)->toBe($porElAlta->refresh()->estado);
});

// ------------------------------------------------------------------------ el comercio

test('a shop loads the order for itself, whatever it sends', function () {
    // ⚠️ El comercio sale de la relación de la cuenta y no de lo que mande el formulario.
    // El panel viejo lo resuelve con un `merge()`; acá los campos que no le corresponden
    // los niega la validación y el suyo lo completa el servidor.
    [$comercio, $cuenta] = unComercioConCuenta();

    $this->actingAs($cuenta)->post('/panel/pedidos', [
        'direccion' => 'Balcarce 100',
        'destino' => 'Muñecas 500',
    ])->assertRedirect();

    $pedido = Pedido::query()->sole();

    expect($pedido->cliente_id)->toBe($comercio->id)
        ->and($pedido->nombre_cliente)->toBeNull()
        ->and($pedido->estado)->toBe(Pedido::ESTADO_SIN_ASIGNAR)
        ->and($pedido->valor)->toBeNull()
        ->and($pedido->user_id)->toBe($cuenta->id);
});

test('a shop has to say where the order goes from and to', function () {
    [, $cuenta] = unComercioConCuenta();

    $this->actingAs($cuenta)->post('/panel/pedidos', [])
        ->assertSessionHasErrors(['direccion', 'destino']);
});

test('a shop cannot put the price, choose the cadete or load it for another shop', function () {
    [, $cuenta] = unComercioConCuenta();
    $otro = Cliente::factory()->create();
    $cadete = unCadeteDelAlta(Cadete::ESTADO_ACTIVO_WEB);

    $this->actingAs($cuenta)->post('/panel/pedidos', [
        'direccion' => 'Balcarce 100',
        'destino' => 'Muñecas 500',
        'valor' => '1',
        'cadete_id' => $cadete->id,
        'cliente_id' => $otro->id,
    ])->assertSessionHasErrors(['valor', 'cadete_id', 'cliente_id']);

    expect(Pedido::query()->count())->toBe(0);
});

test('a shop without a shop assigned is told what is missing', function () {
    // ⚠️ El panel viejo lo dejaba intentarlo: los campos de cliente quedaban vacíos y
    // readonly, el pedido se guardaba sin comercio y nada se lo explicaba. Son 5 de las 7
    // cuentas `restringido` de producción las que están así.
    $cuenta = usuarioCon(Rol::RESTRINGIDO);

    $this->actingAs($cuenta)->post('/panel/pedidos', [
        'direccion' => 'Balcarce 100',
        'destino' => 'Muñecas 500',
    ])->assertSessionHasErrors('cliente');

    expect(Pedido::query()->count())->toBe(0);
});

test('a cadete cannot load an order from the panel', function () {
    $this->actingAs(usuarioCon(Rol::CADETE))
        ->post('/panel/pedidos', loQueMandaElAlta())
        ->assertForbidden();
});

// ------------------------------------------------------------------------- el cupón

test('the coupon prints what the old one printed', function () {
    $comercio = Cliente::factory()->create(['nombre' => 'Farmacia del Centro', 'numero' => 820]);
    $cadete = unCadeteDelAlta(Cadete::ESTADO_ACTIVO_WEB);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))->post('/panel/pedidos', loQueMandaElAlta([
        'cliente_id' => $comercio->id,
        'cadete_id' => $cadete->id,
        'telefono' => '3815550000',
        'responsable' => 'Pérez',
        'detalle' => 'Dejar en portería',
        'garantia' => '5000',
    ]));

    $pedido = Pedido::query()->sole();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/pedidos/{$pedido->id}/cupon")
        ->assertOk()
        ->assertSee('Cupón N° '.$pedido->numero)
        ->assertSee('N° 820 - Farmacia del Centro')
        ->assertSee('Móvil N° 21')
        ->assertSee('Balcarce 100')
        ->assertSee('Dejar en portería')
        // ⚠️ Sin los ceros de más: en el sistema viejo son floats y PHP escribe «3000». La
        // columna es NUMERIC y llega «3000.00», y el papel tiene que salir igual.
        ->assertSee('$ 3000')
        ->assertDontSee('3000.00')
        ->assertSee('$ 5000');
});

test('the coupon of an order with no cadete says so, instead of leaving a hole', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))->post('/panel/pedidos', loQueMandaElAlta());

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos/'.Pedido::query()->sole()->id.'/cupon')
        ->assertOk()
        ->assertSee('Móvil: sin asignar');
});

test('a shop cannot print somebody else coupon, and gets a 404', function () {
    // ⚠️ 404 y no 403: un 403 le confirmaría que el pedido existe. Es la mitad de SEC-7 que
    // el listado no cubre, y en el sistema viejo `pdf/pedido` ya lo hace con
    // `alcanzaPedido()`.
    [, $cuenta] = unComercioConCuenta();

    $deOtro = Pedido::factory()->create(['cliente_id' => Cliente::factory()->create()->id]);

    $this->actingAs($cuenta)->get("/panel/pedidos/{$deOtro->id}/cupon")->assertNotFound();
});

test('after loading an order the screen can print it, and only then', function () {
    $empleado = usuarioCon(Rol::EMPLEADO);

    // Antes de cargar nada no hay nada que imprimir, como el `@if(session('pedido'))` viejo.
    $this->actingAs($empleado)
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina->where('ultimoPedido', null));

    $this->actingAs($empleado)->post('/panel/pedidos', loQueMandaElAlta());

    $this->actingAs($empleado)
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('ultimoPedido.id', Pedido::query()->sole()->id));
});

// ------------------------------------------------------------------------- la tarifa

test('the tariff route answers what the trip costs', function () {
    foreach ([
        'COSTO_MINIMO' => '3000',
        'COSTO_POR_KM' => '900',
        'COSTO_POR_KM_5KM' => '1000',
        'COSTO_RETORNO' => '5',
        'COSTO_RETORNO_5KM' => '9',
        'COSTO_GARANTIA' => '9',
    ] as $nombre => $valor) {
        Configuracion::query()->updateOrCreate(
            ['nombre' => $nombre],
            ['titulo' => $nombre, 'tipo' => 'numero', 'valor' => $valor],
        );
    }

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson('/panel/tarifa?distancia=7000&retorno_origen=1')
        ->assertOk()
        ->assertJson(['costo' => '7630.00']);
});

test('the tariff route refuses a distance that is not a number', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson('/panel/tarifa?distancia=lejos&retorno_origen=0')
        ->assertStatus(422);
});

// ---------------------------------------------------------------------- los buscadores

test('the client search finds by number and by name, and carries only five fields', function () {
    Cliente::factory()->create(['nombre' => 'Farmacia del Centro', 'numero' => 820]);
    Cliente::factory()->create(['nombre' => 'Kiosco', 'numero' => 821]);

    $empleado = usuarioCon(Rol::EMPLEADO);

    $porNumero = $this->actingAs($empleado)->getJson('/panel/clientes/buscar?q=820')->assertOk();

    expect($porNumero->json())->toHaveCount(1);
    expect($porNumero->json('0.nombre'))->toBe('Farmacia del Centro');
    // ⚠️ El endpoint viejo contestaba el documento completo del comercio —token de la app
    // incluido— a cualquiera que supiera un número. Ver SEC-5.
    expect(array_keys($porNumero->json('0')))
        ->toBe(['id', 'numero', 'nombre', 'direccion', 'telefono', 'responsable']);

    $porNombre = $this->actingAs($empleado)->getJson('/panel/clientes/buscar?q=farmacia')->assertOk();

    expect($porNombre->json())->toHaveCount(1);
});

test('the client search answers nothing when nothing was typed', function () {
    Cliente::factory()->count(3)->create();

    // ⚠️ No se manda el padrón entero: son 11.057 comercios y 679 KB, que es justo lo que
    // hace el panel viejo en cada carga de la pantalla.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson('/panel/clientes/buscar?q=')
        ->assertOk()
        ->assertExactJson([]);
});

test('the cadete search leaves out the blocked ones', function () {
    // ⚠️ **Esto es la única parte del comportamiento viejo que no se replica, y a propósito.**
    // Allá `recargarCadetesActivos()` llena el selector con `Cadete::all()`: 220 cadetes, de
    // los que 86 tienen el usuario bloqueado. O sea que se le podía asignar un pedido a
    // alguien bloqueado.
    $libre = Cadete::factory()->create(['numero_movil' => 31, 'apellidos' => 'Gómez']);
    $bloqueado = Cadete::factory()->create(['numero_movil' => 32, 'apellidos' => 'Gómez']);
    $bloqueado->user?->update(['bloqueado' => 'otro-motivo']);

    $encontrados = $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson('/panel/cadetes/buscar?q=gómez')
        ->assertOk();

    expect(array_column($encontrados->json(), 'id'))->toBe([$libre->id]);
});

test('the cadete search says whether each one is in the queue', function () {
    $enLaCola = Cadete::factory()->create([
        'numero_movil' => 41,
        'apellidos' => 'Luna',
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'orden_cola' => 1,
    ]);
    $afuera = Cadete::factory()->create([
        'numero_movil' => 42,
        'apellidos' => 'Luna',
        'estado' => Cadete::ESTADO_INACTIVO,
    ]);

    $encontrados = $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson('/panel/cadetes/buscar?q=luna')
        ->assertOk()
        ->json();

    $porId = array_column($encontrados, null, 'id');

    expect($porId[$enLaCola->id]['en_la_cola'])->toBeTrue()
        ->and($porId[$enLaCola->id]['en_la_app'])->toBeTrue()
        ->and($porId[$afuera->id]['en_la_cola'])->toBeFalse();
});

test('a shop cannot search cadetes or clients', function () {
    [, $cuenta] = unComercioConCuenta();

    $this->actingAs($cuenta)->getJson('/panel/cadetes/buscar?q=luna')->assertForbidden();
    $this->actingAs($cuenta)->getJson('/panel/clientes/buscar?q=farmacia')->assertForbidden();
});

// ------------------------------------------------------------------ el alta rápida

test('the quick client form creates the shop and answers it, to fill the order', function () {
    $creado = $this->actingAs(usuarioCon(Rol::EMPLEADO))->postJson('/panel/clientes/rapido', [
        'nombre' => 'Kiosco nuevo',
        'direccion' => 'Laprida 50',
        'telefono' => '3815551234',
    ])->assertOk();

    $cliente = Cliente::query()->sole();

    expect($creado->json('id'))->toBe($cliente->id)
        // El número lo pone la secuencia, y el formulario lo necesita para completar el campo.
        ->and($creado->json('numero'))->toBe($cliente->numero)
        ->and($creado->json('nombre'))->toBe('Kiosco nuevo')
        ->and($creado->json('direccion'))->toBe('Laprida 50');
});

test('the quick client form refuses what the clients screen refuses', function () {
    // ⚠️ La misma validación que el alta de la pantalla de Clientes: en el sistema viejo son
    // dos altas separadas, cada una con su propia lista de reglas.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->postJson('/panel/clientes/rapido', ['nombre' => 'Sin lo demás'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['direccion', 'telefono']);

    expect(Cliente::query()->count())->toBe(0);
});

test('the quick client form does not take a number: the sequence assigns it', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->postJson('/panel/clientes/rapido', [
            'numero' => 9999,
            'nombre' => 'Kiosco',
            'direccion' => 'Laprida 50',
            'telefono' => '3815551234',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('numero');
});

test('a shop cannot create clients', function () {
    [, $cuenta] = unComercioConCuenta();

    $this->actingAs($cuenta)->postJson('/panel/clientes/rapido', [
        'nombre' => 'Kiosco',
        'direccion' => 'Laprida 50',
        'telefono' => '3815551234',
    ])->assertForbidden();
});

// ------------------------------------------------------------------- lo que no se manda

test('the alta ignores the fields the form does not have', function () {
    // ⚠️ El sistema viejo hace `new Pedido($request->all())` sobre un `$fillable` que
    // incluye `estado`, `plataforma_origen` y `user_id`: con un request armado a mano se
    // podía crear un pedido ya finalizado y firmado por otra persona.
    $otro = User::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))->post('/panel/pedidos', loQueMandaElAlta([
        'estado' => Pedido::ESTADO_FINALIZADO,
        'plataforma_origen' => Pedido::PLATAFORMA_APP,
        'user_id' => $otro->id,
        'numero' => 1,
        'gastronomia' => true,
    ]));

    $pedido = Pedido::query()->sole();

    expect($pedido->estado)->toBe(Pedido::ESTADO_SIN_ASIGNAR)
        ->and($pedido->plataforma_origen)->toBe(Pedido::PLATAFORMA_WEB)
        ->and($pedido->user_id)->not->toBe($otro->id)
        ->and($pedido->gastronomia)->toBeFalse();
});
