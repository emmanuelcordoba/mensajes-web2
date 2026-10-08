<?php

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\LogEstadoPedido;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;

/*
| Los pedidos desde la app del cadete.
|
| ⚠️ **Lo delicado acá es que un teléfono repite.** Se toca dos veces el botón, se corta la
| red y la app reintenta, o llega el mismo pedido duplicado. Las tres acciones son escrituras
| condicionadas justamente por eso, y la mitad de estos tests comprueba el segundo toque:
| COB-1 —finalizar dos veces cobraba el saldo dos veces— y API-4.
*/

function unCadeteConApp(array $extra = [], string $email = 'cadete-app@example.test'): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);

    $usuario = User::factory()->create(['rol_id' => $rol->id, 'email' => $email]);

    return Cadete::factory()->create(array_merge([
        'user_id' => $usuario->id,
        'numero_movil' => 55,
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'orden_cola' => 100,
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
        'cobranza_saldo' => '5000.00',
    ], $extra));
}

function unPedidoDe(Cadete $cadete, string $estado = Pedido::ESTADO_ASIGNADO, array $extra = []): Pedido
{
    return Pedido::factory()->create(array_merge([
        'cadete_id' => $cadete->id,
        'estado' => $estado,
        'plataforma_origen' => Pedido::PLATAFORMA_WEB,
        'direccion' => 'Balcarce 100',
        'destino' => 'Muñecas 500',
        'valor' => '3000.00',
        'garantia' => null,
    ], $extra));
}

function elPorcentajeDeSaldo(string $porcentaje): void
{
    Configuracion::query()->updateOrCreate(
        ['nombre' => 'COBRANZA_SALDO_PORCENTAJE'],
        ['titulo' => 'Porcentaje', 'tipo' => Configuracion::TIPO_NUMERO, 'valor' => $porcentaje],
    );
}

// ------------------------------------------------------------------------- el listado

test('the list comes in the two groups the app reads', function () {
    $cadete = unCadeteConApp();
    $asignado = unPedidoDe($cadete, Pedido::ESTADO_ASIGNADO);
    $enCurso = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO);
    unPedidoDe($cadete, Pedido::ESTADO_FINALIZADO);

    // ⚠️ La app hace `resp['pedidos']['asignados']`, aunque su propio tipo diga otra cosa.
    $datos = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos')
        ->assertOk()
        ->json('pedidos');

    expect(array_column($datos['asignados'], '_id'))->toBe([(string) $asignado->id])
        ->and(array_column($datos['aceptados'], '_id'))->toBe([(string) $enCurso->id]);
});

test('the list does not carry the shop or whoever loaded the order', function () {
    // ⚠️ El modelo viejo tiene `$with = ['user','cliente']`, así que en CADA consulta de
    // pedidos viajan embebidos el comercio entero y la cuenta del empleado. La app ni
    // siquiera declara un `user` en su interfaz: lo recibe y lo tira.
    $cadete = unCadeteConApp();
    unPedidoDe($cadete, Pedido::ESTADO_ASIGNADO, ['cliente_id' => Cliente::factory()->create()->id]);

    $fila = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos')
        ->json('pedidos.asignados.0');

    expect($fila)->not->toHaveKey('cliente')
        ->and($fila)->not->toHaveKey('cadete')
        ->and($fila)->not->toHaveKey('user');
});

test('a cadete only sees his own orders', function () {
    $mio = unCadeteConApp();
    $ajeno = unCadeteConApp(['numero_movil' => 56], 'otro@example.test');

    unPedidoDe($ajeno, Pedido::ESTADO_ASIGNADO);

    expect($this->actingAs($mio->user, 'sanctum')->getJson('/api/pedidos')->json('pedidos.asignados'))
        ->toBe([]);
});

// -------------------------------------------------------------------------- el detalle

test('the detail carries the shop and the cadete, with the shape the app reads', function () {
    $cadete = unCadeteConApp();
    $comercio = Cliente::factory()->create(['nombre' => 'Farmacia del Centro']);
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO, ['cliente_id' => $comercio->id]);

    $datos = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/'.$pedido->id)
        ->assertOk()
        ->json('pedido');

    expect($datos['_id'])->toBe((string) $pedido->id)
        // La app escribe `pedido.cliente ? pedido.cliente.nombre : pedido.nombre_cliente`.
        ->and($datos['cliente']['nombre'])->toBe('Farmacia del Centro')
        ->and($datos['cliente']['_id'])->toBeString()
        // Y `pedido.cadete?.numero_movil`.
        ->and($datos['cadete']['numero_movil'])->toBe('55')
        ->and($datos['retorno_origen'])->toBeBool()
        ->and($datos['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

test('a person as a client still has a name to show', function () {
    // ⚠️ El sistema viejo emite `nombre` a secas, que en los clientes cargados con nombre y
    // apellido por separado viene vacío: el cadete veía el pedido sin a quién va.
    $cadete = unCadeteConApp();
    $persona = Cliente::factory()->create(['nombre' => null, 'nombres' => 'Ana', 'apellidos' => 'Pérez']);
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO, ['cliente_id' => $persona->id]);

    expect($this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/'.$pedido->id)
        ->json('pedido.cliente.nombre'))
        ->not->toBeEmpty();
});

test('somebody else order answers 404 and not 403', function () {
    // ⚠️ Antes lo leía cualquier token de la API, con los datos del comercio adentro
    // (SEC-10). Y la negativa es 404 porque un 403 confirma que el pedido existe.
    $mio = unCadeteConApp();
    $ajeno = unCadeteConApp(['numero_movil' => 56], 'otro@example.test');
    $pedido = unPedidoDe($ajeno);

    $this->actingAs($mio->user, 'sanctum')
        ->getJson('/api/pedidos/'.$pedido->id)
        ->assertNotFound();
});

// -------------------------------------------------------------------------- aceptar

test('accepting takes the order and leaves the cadete with it', function () {
    $cadete = unCadeteConApp();
    $pedido = unPedidoDe($cadete);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/aceptar?id='.$pedido->id)
        ->assertOk()
        ->assertJsonPath('pedido.estado', Pedido::ESTADO_EN_CURSO);

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_CON_PEDIDO_APP)
        ->and(LogEstadoPedido::query()->where('pedido_id', $pedido->id)->count())->toBe(1);
});

test('accepting twice answers the same and does not log twice', function () {
    // ⚠️ API-4: antes cada aceptar repetido volvía a programar el aviso y a notificar al
    // cliente. Desde el teléfono las dos pulsaciones son la misma intención.
    $cadete = unCadeteConApp();
    $pedido = unPedidoDe($cadete);

    $primera = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/pedidos/aceptar?id='.$pedido->id)->assertOk();
    $segunda = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/pedidos/aceptar?id='.$pedido->id)->assertOk();

    expect($segunda->json('pedido.estado'))->toBe($primera->json('pedido.estado'))
        ->and(LogEstadoPedido::query()->where('pedido_id', $pedido->id)->count())->toBe(1);
});

test('an order that is not assigned cannot be accepted', function () {
    // ⚠️ Antes un pedido Finalizado o Cancelado volvía a En curso.
    $cadete = unCadeteConApp();

    foreach ([Pedido::ESTADO_FINALIZADO, Pedido::ESTADO_CANCELADO] as $estado) {
        $pedido = unPedidoDe($cadete, $estado);

        $this->actingAs($cadete->user, 'sanctum')
            ->getJson('/api/pedidos/aceptar?id='.$pedido->id)
            ->assertStatus(400)
            // La app lee `error.error.error` para el texto del alerta.
            ->assertJsonPath('error', 'No puede aceptar el pedido.');

        expect($pedido->fresh()->estado)->toBe($estado);
    }
});

// -------------------------------------------------------------------------- rechazar

test('rejecting sends the order back to the waiting list without a cadete', function () {
    $cadete = unCadeteConApp();
    $pedido = unPedidoDe($cadete);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/rechazar?id='.$pedido->id)
        ->assertOk();

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_RECHAZADO)
        ->and($pedido->fresh()->cadete_id)->toBeNull()
        // ⚠️ El registro dice quién lo soltó: el cadete se captura ANTES de soltarlo,
        // porque después el pedido ya no lo sabe.
        ->and(LogEstadoPedido::query()->where('pedido_id', $pedido->id)->first()?->cadete_id)
        ->toBe($cadete->id);
});

test('an order in progress cannot be rejected', function () {
    // ⚠️ Antes se podía soltar un pedido En curso o Finalizado, o sea devolver algo ya
    // entregado.
    $cadete = unCadeteConApp();
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/rechazar?id='.$pedido->id)
        ->assertStatus(400);

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_EN_CURSO)
        ->and($pedido->fresh()->cadete_id)->toBe($cadete->id);
});

// ------------------------------------------------------------------------- finalizar

test('finishing closes the order and puts the cadete back at the end of the queue', function () {
    $cadete = unCadeteConApp(['estado' => Cadete::ESTADO_CON_PEDIDO_APP, 'orden_cola' => 1]);
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/finalizar?id='.$pedido->id)
        ->assertOk()
        ->assertJsonPath('pedido.estado', Pedido::ESTADO_FINALIZADO);

    // ⚠️ Vuelve a la cola y va al FINAL: ya hizo un viaje, le toca a los que esperan.
    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP)
        ->and($cadete->fresh()->orden_cola)->toBeGreaterThan(1);
});

test('with another order in progress the cadete keeps it', function () {
    $cadete = unCadeteConApp(['estado' => Cadete::ESTADO_CON_PEDIDO_APP]);
    $uno = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO);
    unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO);

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/pedidos/finalizar?id='.$uno->id)->assertOk();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_CON_PEDIDO_APP);
});

test('finishing charges the balance once, and twice does not charge twice', function () {
    // ⚠️ **COB-1, y es plata.** Si llegan dos finalizar del mismo pedido —un doble toque, o
    // la app reintentando cuando se corta la red— cada uno cobraba el pedido.
    elPorcentajeDeSaldo('10');

    $cadete = unCadeteConApp([
        'estado' => Cadete::ESTADO_CON_PEDIDO_APP,
        'modalidad_cobranza' => Cadete::COBRANZA_SALDO,
        'cobranza_saldo' => '5000.00',
    ]);
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO, ['valor' => '1000.00']);

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/pedidos/finalizar?id='.$pedido->id)->assertOk();
    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/pedidos/finalizar?id='.$pedido->id)->assertOk();

    expect(MovimientoCobranzaSaldo::query()->where('pedido_id', $pedido->id)->count())->toBe(1)
        ->and($cadete->fresh()->cobranza_saldo)->toBe('4900.00')
        ->and(LogEstadoPedido::query()->where('pedido_id', $pedido->id)->count())->toBe(1);
});

test('a cadete who pays weekly is not charged', function () {
    elPorcentajeDeSaldo('10');

    $cadete = unCadeteConApp(['estado' => Cadete::ESTADO_CON_PEDIDO_APP]);
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO);

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/pedidos/finalizar?id='.$pedido->id)->assertOk();

    expect(MovimientoCobranzaSaldo::query()->count())->toBe(0);
});

// ------------------------------------------------------------- lo que no se puede tocar

test('a cadete cannot touch somebody else order with any of the three buttons', function () {
    $mio = unCadeteConApp();
    $ajeno = unCadeteConApp(['numero_movil' => 56], 'otro@example.test');
    $pedido = unPedidoDe($ajeno);

    foreach (['aceptar', 'rechazar', 'finalizar'] as $boton) {
        $this->actingAs($mio->user, 'sanctum')
            ->getJson("/api/pedidos/{$boton}?id={$pedido->id}")
            ->assertStatus(400);
    }

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_ASIGNADO)
        ->and($pedido->fresh()->cadete_id)->toBe($ajeno->id);
});

test('an id that is not a number is refused instead of breaking the query', function () {
    // ⚠️ Sobre Postgres un id que no es entero hace fallar la consulta con un 500, y la app
    // manda lo que tenga guardado. Es la misma trampa que apareció en el panel.
    $cadete = unCadeteConApp();

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/aceptar?id=cualquiera')
        ->assertStatus(400);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/aceptar')
        ->assertStatus(400);
});

test('the three buttons need the cadete role, not just a token', function () {
    $cliente = usuarioCon(Rol::CLIENTE_APP);

    foreach (['pedidos', 'pedidos/aceptar?id=1', 'pedidos/rechazar?id=1', 'pedidos/finalizar?id=1'] as $ruta) {
        $this->actingAs($cliente, 'sanctum')->getJson('/api/'.$ruta)->assertForbidden();
    }
});

// ---------------------------------------------------------------------------- la plata

test('money is written the way the old system wrote it', function () {
    // ⚠️ La app hace `$ {{ pedido.valor }}` y `'$ '+pedido.garantia`: lo que salga de acá es
    // lo que el cadete lee. En MongoDB son floats y PHP escribe «3000»; la columna NUMERIC
    // llega «3000.00».
    $cadete = unCadeteConApp();
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO, ['valor' => '3000.00', 'garantia' => '3150.50']);

    $datos = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/'.$pedido->id)
        ->json('pedido');

    expect($datos['valor'])->toBe('3000')
        ->and($datos['garantia'])->toBe('3150.5');
});

test('an order without a guarantee says so instead of showing a zero', function () {
    // La app escribe `pedido.garantia ? '$ '+pedido.garantia : 'No'`, así que null es lo
    // que hace que diga «No».
    $cadete = unCadeteConApp();
    $pedido = unPedidoDe($cadete, Pedido::ESTADO_EN_CURSO, ['garantia' => null]);

    expect($this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/pedidos/'.$pedido->id)
        ->json('pedido.garantia'))
        ->toBeNull();
});
