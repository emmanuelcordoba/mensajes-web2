<?php

use App\Jobs\CancelarPedidoSinConfirmar;
use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\LogEstadoPedido;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Pedido;
use App\Models\Rol;
use Illuminate\Support\Facades\Queue;

/*
| Inicio — tercera parte: la lista de espera y su modal.
|
| Lo que hay que garantizar es que el pedido vaya al estado correcto según dónde está
| trabajando el cadete, que el saldo se controle contra el valor que se está tipeando, y que
| un comercio no pueda asignar nada: en el sistema viejo ese endpoint no preguntaba ni el rol
| ni de quién era el pedido.
*/

function unPedidoEnEspera(string $estado = Pedido::ESTADO_SIN_ASIGNAR, string $plataforma = Pedido::PLATAFORMA_WEB): Pedido
{
    return Pedido::factory()->create([
        'estado' => $estado,
        'plataforma_origen' => $plataforma,
        'direccion' => 'Balcarce 100',
        'valor' => '1000.00',
    ]);
}

function unCadeteParaAsignar(string $estado, string $modalidad = Cadete::COBRANZA_SEMANAL): Cadete
{
    return Cadete::factory()->create([
        'numero_movil' => 11,
        'estado' => $estado,
        'orden_cola' => 1000,
        'modalidad_cobranza' => $modalidad,
        'cobranza_saldo' => '5000.00',
    ]);
}

/** El porcentaje que se le descuenta al cadete de saldo por cada pedido. */
function elPorcentajeDeCobranza(string $porcentaje): void
{
    Configuracion::query()->updateOrCreate(
        ['nombre' => 'COBRANZA_SALDO_PORCENTAJE'],
        ['titulo' => 'Porcentaje de cobranza', 'tipo' => 'numero', 'valor' => $porcentaje],
    );
}

/** Lo mínimo que el modal manda: el formulario exige dirección y valor. */
function loQueMandaElModal(array $extra = []): array
{
    return array_merge([
        'direccion' => 'Balcarce 100',
        'valor' => '1000.00',
    ], $extra);
}

test('the office sees the waiting list and a shop does not', function () {
    unPedidoEnEspera();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina->has('espera', 1)->has('paraAsignar'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, Cliente::factory()->create()))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('espera', null)
            ->where('paraAsignar', null));
});

test('the waiting list is the same queue the orders screen shows', function () {
    // Los dos leen `scopeEsperandoCadete`: una regla, dos pantallas.
    unPedidoEnEspera(Pedido::ESTADO_SIN_ASIGNAR);
    unPedidoEnEspera(Pedido::ESTADO_CANCELADO);
    unPedidoEnEspera(Pedido::ESTADO_ACEPTADO);

    // Éstos no esperan a nadie.
    unPedidoEnEspera(Pedido::ESTADO_EN_CURSO);
    unPedidoEnEspera(Pedido::ESTADO_FINALIZADO);
    unPedidoEnEspera(Pedido::ESTADO_COTIZADO);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(function ($pagina) {
            $estados = array_column($pagina->toArray()['props']['espera'], 'estado');

            expect($estados)->toHaveCount(3)
                // Los resueltos arriba: es el orden del scope.
                ->and($estados[0])->toBe(Pedido::ESTADO_CANCELADO);
        });
});

test('the waiting list carries the row and not the whole order', function () {
    // ⚠️ El teléfono y el detalle salen sólo cuando alguien abre el modal, como la cola de
    // cadetes: es una pantalla que está abierta todo el día en una oficina.
    unPedidoEnEspera();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => expect(
            array_keys($pagina->toArray()['props']['espera'][0]),
        )->toBe([
            'id', 'numero', 'numero_corto', 'cliente', 'direccion', 'destino',
            'estado', 'plataforma', 'distintivo',
        ]));
});

test('the row carries who loaded the order, in two letters over their colour', function () {
    // ⚠️ Esto no es decoración: la lista la miran varias personas a la vez y el distintivo es
    // lo que le deja a cada una ver cuáles son sus pedidos sin leer nada.
    $quienCarga = usuarioCon(Rol::EMPLEADO);
    $quienCarga->update(['name' => 'Agustina', 'iniciales' => 'ab', 'color' => '#393ec6']);

    $pedido = unPedidoEnEspera();
    $pedido->user()->associate($quienCarga)->save();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('espera.0.distintivo.iniciales', 'ab')
            ->where('espera.0.distintivo.color', '#393ec6')
            ->where('espera.0.distintivo.nombre', 'Agustina')
            // El color del texto lo decide el servidor, por la luminancia del fondo.
            ->has('espera.0.distintivo.contraste'));
});

test('without initials or without colour there is no badge to draw', function () {
    // ⚠️ Hacen falta las dos cosas, como el `@if` del panel viejo: unas iniciales sin color no
    // se distinguen de las de al lado, que es justo para lo que sirven. Medido sobre la base
    // de desarrollo: 35 cuentas tienen iniciales y 32 tienen color.
    $sinColor = usuarioCon(Rol::EMPLEADO);
    $sinColor->update(['iniciales' => 'xy', 'color' => null]);

    $conColor = usuarioCon(Rol::EMPLEADO);
    $conColor->update(['iniciales' => null, 'color' => '#ff0000']);

    foreach ([$sinColor, $conColor] as $usuario) {
        $pedido = unPedidoEnEspera();
        $pedido->user()->associate($usuario)->save();
    }

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(function ($pagina) {
            foreach ($pagina->toArray()['props']['espera'] as $fila) {
                expect($fila['distintivo'])->toBeNull();
            }
        });
});

test('the modal header says who loaded it, with the same badge as the row', function () {
    // La cabecera del modal y la fila tienen que decir lo mismo: es la misma función.
    $quienCarga = usuarioCon(Rol::EMPLEADO);
    $quienCarga->update(['name' => 'Agustina', 'iniciales' => 'ab', 'color' => '#393ec6']);

    $pedido = unPedidoEnEspera();
    $pedido->user()->associate($quienCarga)->save();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson("/panel/pedidos/{$pedido->id}/detalle")
        ->assertJsonPath('cargado_por', 'Agustina')
        ->assertJsonPath('distintivo.iniciales', 'ab')
        ->assertJsonPath('distintivo.color', '#393ec6');
});

test('an order nobody signed carries no badge, and does not pretend otherwise', function () {
    // ⚠️ Pasa de verdad: 12 de los 903.489 pedidos de la base de desarrollo no tienen quién
    // los cargó, y son justamente los que quedaron en la lista de espera.
    $pedido = unPedidoEnEspera();
    $pedido->user()->dissociate()->save();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina->where('espera.0.distintivo', null));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson("/panel/pedidos/{$pedido->id}/detalle")
        ->assertJsonPath('cargado_por', null)
        ->assertJsonPath('distintivo', null);
});

test('only the cadetes in the queue are offered to assign', function () {
    // ⚠️ El panel viejo se contradecía solo: el primer dibujado ofrecía los 1.768 cadetes no
    // bloqueados y cinco segundos después el ajax lo reemplazaba por los activos.
    unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB);
    Cadete::factory()->create(['numero_movil' => 22, 'estado' => Cadete::ESTADO_INACTIVO]);
    Cadete::factory()->create(['numero_movil' => 33, 'estado' => Cadete::ESTADO_CON_PEDIDO_APP, 'orden_cola' => 1]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => expect(
            array_column($pagina->toArray()['props']['paraAsignar'], 'movil'),
        )->toBe([11]));
});

test('a cadete taken from the counter finishes the order and leaves the queue', function () {
    // ⚠️ `activo-web` quiere decir que está en el mostrador: se lleva el pedido en la mano,
    // así que el pedido queda Finalizado y el cadete sale de la cola. No hay app que confirme.
    $pedido = unPedidoEnEspera();
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_FINALIZADO)
        ->and($pedido->fresh()->cadete_id)->toBe($cadete->id)
        ->and($cadete->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO);
});

test('a cadete working from the app gets five minutes to confirm', function () {
    Queue::fake();

    $pedido = unPedidoEnEspera();
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
        ]));

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_ASIGNADO)
        // El de la app sigue en la cola: el estado lo cambia él desde el teléfono.
        ->and($cadete->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP);

    Queue::assertPushed(CancelarPedidoSinConfirmar::class);
});

test('the clock cancels an order that was never confirmed, and leaves the rest alone', function () {
    $sinConfirmar = unPedidoEnEspera(Pedido::ESTADO_ASIGNADO);
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_APP);
    $sinConfirmar->cadete()->associate($cadete)->save();

    (new CancelarPedidoSinConfirmar($sinConfirmar))->handle();

    expect($sinConfirmar->fresh()->estado)->toBe(Pedido::ESTADO_CANCELADO)
        ->and($sinConfirmar->fresh()->cadete_id)->toBeNull();

    // ⚠️ El que el cadete sí aceptó no se toca. El trabajo se ejecuta igual —no hay forma de
    // desencolarlo— y esa comprobación es lo que lo hace inofensivo.
    $aceptado = unPedidoEnEspera(Pedido::ESTADO_EN_CURSO);
    $aceptado->cadete()->associate($cadete)->save();

    (new CancelarPedidoSinConfirmar($aceptado))->handle();

    expect($aceptado->fresh()->estado)->toBe(Pedido::ESTADO_EN_CURSO)
        ->and($aceptado->fresh()->cadete_id)->toBe($cadete->id);
});

test('the cadete who paid by balance is charged, and only once', function () {
    elPorcentajeDeCobranza('10');

    $pedido = unPedidoEnEspera();
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB, Cadete::COBRANZA_SALDO);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
            'valor' => '2000.00',
        ]));

    // El 10% de los 2.000 que se acaban de tipear, no de los 1.000 guardados.
    expect($cadete->fresh()->cobranza_saldo)->toBe('4800.00');

    $movimiento = MovimientoCobranzaSaldo::query()->where('pedido_id', $pedido->id)->first();

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->monto)->toBe('200.00')
        // ⚠️ PANEL-13: quién lo hizo se escribe siempre.
        ->and($movimiento->user_id)->not->toBeNull();
});

test('a cadete without enough balance is refused, and told how much is missing', function () {
    // ⚠️ El sistema viejo contestaba «Cadete con saldo insuficiente.» y había que ir a otra
    // pantalla a ver de cuánto estamos hablando.
    elPorcentajeDeCobranza('10');

    $pedido = unPedidoEnEspera();
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB, Cadete::COBRANZA_SALDO);
    $cadete->update(['cobranza_saldo' => '50.00']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
            'valor' => '2000.00',
        ]))
        ->assertSessionHasErrors('cadete_id');

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_SIN_ASIGNAR)
        ->and($pedido->fresh()->cadete_id)->toBeNull()
        ->and($cadete->fresh()->cobranza_saldo)->toBe('50.00');
});

test('the balance is measured against the price being typed, not the saved one', function () {
    // El control viejo ya hacía esto y es lo que hay que conservar: el pedido vale 1.000
    // guardado, se tipea 20.000, y el saldo alcanza para el primero pero no para el segundo.
    elPorcentajeDeCobranza('10');

    $pedido = unPedidoEnEspera();
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB, Cadete::COBRANZA_SALDO);
    $cadete->update(['cobranza_saldo' => '150.00']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
            'valor' => '20000.00',
        ]))
        ->assertSessionHasErrors('cadete_id');
});

test('a cadete who pays weekly is never stopped by the balance', function () {
    $pedido = unPedidoEnEspera();
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB, Cadete::COBRANZA_SEMANAL);
    $cadete->update(['cobranza_saldo' => '0.00']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
        ]))
        ->assertSessionHasNoErrors();

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_FINALIZADO)
        ->and($cadete->fresh()->cobranza_saldo)->toBe('0.00');
});

test('the fields the modal edits are saved, and nothing else is', function () {
    // ⚠️ En el sistema viejo esto era `update($request->all())` sobre un $fillable que
    // incluye `estado`, `plataforma_origen`, `user_id` y `cliente_id`.
    $pedido = unPedidoEnEspera();
    $otroCliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'direccion' => 'Muñecas 500',
            'destino' => 'San Martín 200',
            'responsable' => 'Quien recibe',
            'telefono' => '3815551234',
            'detalle' => 'Dos cajas',
            'retorno_origen' => '1',
            'garantia' => '300.50',
            // Lo que no se puede tocar desde acá.
            'estado' => Pedido::ESTADO_FINALIZADO,
            'cliente_id' => $otroCliente->id,
            'plataforma_origen' => Pedido::PLATAFORMA_API,
        ]));

    $guardado = $pedido->fresh();

    expect($guardado->direccion)->toBe('Muñecas 500')
        ->and($guardado->destino)->toBe('San Martín 200')
        ->and($guardado->responsable)->toBe('Quien recibe')
        ->and($guardado->telefono)->toBe('3815551234')
        ->and($guardado->detalle)->toBe('Dos cajas')
        ->and($guardado->retorno_origen)->toBeTrue()
        ->and($guardado->garantia)->toBe('300.50')
        // Sin cadete el estado no se toca, y lo que vino de más se descartó.
        ->and($guardado->estado)->toBe(Pedido::ESTADO_SIN_ASIGNAR)
        ->and($guardado->cliente_id)->not->toBe($otroCliente->id)
        ->and($guardado->plataforma_origen)->toBe(Pedido::PLATAFORMA_WEB);
});

test('an order from the api is editable, and that was a hole', function () {
    // ⚠️ Allá la edición corría sólo para `web`, así que en un pedido de la API todo lo que
    // el operador escribía se descartaba en silencio: se podía tipear el precio, apretar
    // Asignar, y el pedido quedaba asignado y sin valor. Es el camino de respaldo del alta
    // por API, cuando el mapa no responde y lo cotiza una persona.
    $pedido = unPedidoEnEspera(Pedido::ESTADO_SIN_ASIGNAR, Pedido::PLATAFORMA_API);
    $pedido->update(['valor' => null]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'valor' => '3500.00',
            'direccion' => 'Corrientes 44',
        ]));

    expect($pedido->fresh()->valor)->toBe('3500.00')
        ->and($pedido->fresh()->direccion)->toBe('Corrientes 44');
});

test('an order from the app keeps its fields and gets quoted when the price changes', function () {
    $pedido = unPedidoEnEspera(Pedido::ESTADO_SIN_ASIGNAR, Pedido::PLATAFORMA_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'direccion' => 'Esto no se guarda',
            'valor' => '4000.00',
        ]));

    $guardado = $pedido->fresh();

    expect($guardado->direccion)->toBe('Balcarce 100')
        ->and($guardado->valor)->toBe('4000.00')
        ->and($guardado->estado)->toBe(Pedido::ESTADO_COTIZADO);
});

test('an order from the app with the same price is not quoted again', function () {
    // ⚠️ `bccomp` y no `!=`: «1000.00» y «1000» son el mismo precio.
    $pedido = unPedidoEnEspera(Pedido::ESTADO_SIN_ASIGNAR, Pedido::PLATAFORMA_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'valor' => '1000',
        ]));

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_SIN_ASIGNAR);
});

test('every assignment leaves a line in the history', function () {
    $pedido = unPedidoEnEspera();
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
        ]));

    $log = LogEstadoPedido::query()->where('pedido_id', $pedido->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->estado)->toBe(Pedido::ESTADO_FINALIZADO)
        ->and($log->plataforma_origen)->toBe(LogEstadoPedido::WEB)
        ->and($log->user_id)->not->toBeNull();
});

test('an order without an address or a price is refused', function () {
    $pedido = unPedidoEnEspera();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", ['direccion' => '', 'valor' => ''])
        ->assertSessionHasErrors(['direccion', 'valor']);
});

test('a shop cannot assign a cadete, not even to its own order', function () {
    // El mismo recorte que el sistema viejo: `ajax/pedidos/actualizar` vive en el grupo
    // `rol:empleado,admin` y la lista de espera entera está dentro de un `@if` de oficina.
    // Que sea su propio pedido no cambia nada: asignar cadetes no es algo que haga un
    // comercio.
    $comercio = Cliente::factory()->create();
    $pedido = unPedidoEnEspera();
    $pedido->update(['cliente_id' => $comercio->id]);
    $cadete = unCadeteParaAsignar(Cadete::ESTADO_ACTIVO_WEB);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->patch("/panel/pedidos/{$pedido->id}/asignacion", loQueMandaElModal([
            'cadete_id' => $cadete->id,
        ]))
        ->assertForbidden();

    expect($pedido->fresh()->cadete_id)->toBeNull();
});

test('deleting an order from the modal is a soft delete', function () {
    // ⚠️ Borrado lógico, como el sistema viejo: así es como la oficina se deshace de los
    // pedidos que no llegan al final.
    $pedido = unPedidoEnEspera();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/pedidos/{$pedido->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Pedido::query()->find($pedido->id))->toBeNull()
        ->and(Pedido::withTrashed()->find($pedido->id))->not->toBeNull();
});

test('a shop cannot delete somebody else order', function () {
    $pedido = unPedidoEnEspera();

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, Cliente::factory()->create()))
        ->delete("/panel/pedidos/{$pedido->id}")
        // 404 y no 403: un comercio puede probar ids, y un 403 le confirma que existe.
        ->assertNotFound();

    expect(Pedido::query()->find($pedido->id))->not->toBeNull();
});

test('the detail of an order comes as json, with what the modal draws', function () {
    $pedido = unPedidoEnEspera();
    $pedido->update(['telefono' => '3815550000', 'responsable' => 'Quien recibe']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson("/panel/pedidos/{$pedido->id}/detalle")
        ->assertSuccessful()
        ->assertJsonPath('id', $pedido->id)
        ->assertJsonPath('telefono', '3815550000')
        ->assertJsonPath('responsable', 'Quien recibe')
        // Los de la web se editan; los de la app no.
        ->assertJsonPath('editable', true);

    $deLaApp = unPedidoEnEspera(Pedido::ESTADO_SIN_ASIGNAR, Pedido::PLATAFORMA_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson("/panel/pedidos/{$deLaApp->id}/detalle")
        ->assertJsonPath('editable', false);
});

test('a shop cannot ask for the detail of an order, not even its own', function () {
    // ⚠️ 403 y no 404: acá no niega la policy sino el middleware de rol, que contesta antes
    // de mirar qué pedido es, así que no dice nada sobre si existe. Es el mismo recorte del
    // sistema viejo, donde `ajax/pedidos/getById` vive en el grupo `rol:empleado,admin`.
    $comercio = Cliente::factory()->create();
    $ajeno = unPedidoEnEspera();
    $propio = unPedidoEnEspera();
    $propio->update(['cliente_id' => $comercio->id]);

    foreach ([$ajeno, $propio] as $pedido) {
        $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
            ->getJson("/panel/pedidos/{$pedido->id}/detalle")
            ->assertForbidden();
    }
});

test('the waiting list is filtered by reach, and that changes nothing for the office', function () {
    // ⚠️ **Esto es lo que hay que no romper.** El panel viejo arma esta columna con
    // `Pedido::getSinCadete()`, que NO filtra por cliente, y se la muestra sólo a la oficina.
    // Acá la consulta pasa por `alcanzablesPor` —en este sistema los listados de pedidos
    // filtran en la consulta y no con la policy, porque una policy responde por una fila, ver
    // SEC-7—, y ese scope filtra únicamente al rol `restringido`. O sea que para admin y
    // empleado tiene que dar exactamente lo mismo que no filtrar.
    //
    // Se comparan los ids y no las cantidades: dos cantidades pueden coincidir por casualidad.
    // Medido además contra la base de desarrollo: los mismos 5 pedidos con el scope y sin él.
    $deUnComercio = Cliente::factory()->create();
    $deOtro = Cliente::factory()->create();

    unPedidoEnEspera()->update(['cliente_id' => $deUnComercio->id]);
    unPedidoEnEspera()->update(['cliente_id' => $deOtro->id]);
    unPedidoEnEspera()->update(['cliente_id' => null]);

    $sinFiltrar = Pedido::query()->esperandoCadete()->pluck('id')->all();

    expect($sinFiltrar)->toHaveCount(3);

    foreach ([Rol::ADMIN, Rol::EMPLEADO] as $rol) {
        $this->actingAs(usuarioCon($rol))
            ->get('/panel')
            ->assertInertia(fn ($pagina) => expect(
                array_column($pagina->toArray()['props']['espera'], 'id'),
            )->toBe($sinFiltrar, "el rol {$rol} ve otra cosa"));
    }
});

test('showing the waiting list is a rule about orders, not about cadetes', function () {
    // ⚠️ La primera versión dibujaba esta columna cuando el usuario podía ver CADETES, que da
    // la misma respuesta por el motivo equivocado: el día que cambie quién puede ver cadetes
    // cambiaría también quién ve los pedidos de la oficina, sin que nadie lo decida.
    foreach ([Rol::ADMIN, Rol::EMPLEADO] as $rol) {
        expect(usuarioCon($rol)->can('verListaDeEspera', Pedido::class))->toBeTrue();
    }

    expect(usuarioCon(Rol::RESTRINGIDO, Cliente::factory()->create())
        ->can('verListaDeEspera', Pedido::class))->toBeFalse();
});
