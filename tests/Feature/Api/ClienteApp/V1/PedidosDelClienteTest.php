<?php

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserFoto;
use App\Services\FotosDePerfil;
use App\Services\PedidoDelClienteApp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
| Los pedidos de un cliente de la app: mirarlos, aceptar la cotización, cancelar, y cargar uno
| nuevo con su cotización.
|
| ⚠️ **Las dos escrituras del cliente son condicionadas**, por lo mismo que las del cadete: un
| teléfono repite. Y antes no miraban el estado: `destroy()` borraba el pedido En curso —con
| el cadete en la calle— y `aceptar()` lo movía desde cualquier estado, así que uno ya
| entregado volvía atrás. Es API-3, y la mitad de estos tests es eso.
*/

function unClienteConPedidos(array $extra = [], string $email = 'pedidos@example.test'): Cliente
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CLIENTE_APP], ['display_rol' => 'Cliente app']);

    $cuenta = User::factory()->create([
        'rol_id' => $rol->id,
        'email' => $email,
        'email_verified_at' => now(),
    ]);

    return Cliente::factory()->create(array_merge([
        'user_id' => $cuenta->id,
        'nombres' => 'Ana',
        'apellidos' => 'Gómez',
        'telefono' => '3815551234',
        'plataforma' => Cliente::PLATAFORMA_APP,
    ], $extra));
}

function unPedidoDelCliente(Cliente $cliente, string $estado, array $extra = []): Pedido
{
    return Pedido::factory()->create(array_merge([
        'cliente_id' => $cliente->id,
        'cadete_id' => null,
        'estado' => $estado,
        'plataforma_origen' => Pedido::PLATAFORMA_APP,
        'direccion' => 'San Luis 312, San Miguel de Tucumán',
        'destino' => 'Muñecas 500, San Miguel de Tucumán',
        'valor' => '3000.00',
        'garantia' => null,
    ], $extra));
}

function laTarifaDeLaApp(): void
{
    $valores = [
        'COSTO_MINIMO' => '3000',
        'COSTO_POR_KM' => '500',
        'COSTO_TARIFA_LARGA' => '600',
        'COSTO_RETORNO' => '1000',
        'COSTO_GARANTIA' => '200',
        'COBRANZA_SALDO_PORCENTAJE' => '18',
        'TIEMPO_DE_DEMORA' => '30 a 45 minutos',
    ];

    foreach ($valores as $nombre => $valor) {
        Configuracion::query()->updateOrCreate(
            ['nombre' => $nombre],
            [
                'titulo' => $nombre,
                'tipo' => $nombre === 'TIEMPO_DE_DEMORA' ? Configuracion::TIPO_TEXTO : Configuracion::TIPO_NUMERO,
                'valor' => $valor,
            ],
        );
    }
}

/** El cuerpo que manda `modal-confirmar.component.ts` al cargar un pedido. */
function elFormularioDeAlta(array $cambios = []): array
{
    return array_merge([
        'direccion_origen_calle_altura' => 'San Luis 312',
        'direccion_origen_depto' => null,
        'direccion_origen_localidad' => 'San Miguel de Tucumán',
        'direccion_destino_calle_altura' => 'Muñecas 500',
        'direccion_destino_depto' => null,
        'direccion_destino_localidad' => 'San Miguel de Tucumán',
        'costo' => '3000',
        'tipo_paquete' => 'Caja',
        'peso_paquete' => 'Hasta 5 kg',
        'retorno_origen' => false,
        'envia_dinero_monto' => null,
        'valor_declarado' => null,
        'telefono' => '3815551234',
        'detalle' => 'Timbre 3',
    ], $cambios);
}

// -------------------------------------------------------------------- el listado

test('the list brings his own orders, the last moved first', function () {
    $cliente = unClienteConPedidos();
    $otro = unClienteConPedidos(['telefono' => '3810000000'], 'otro@example.test');

    $viejo = unPedidoDelCliente($cliente, Pedido::ESTADO_FINALIZADO);
    $viejo->forceFill(['updated_at' => Carbon::now()->subDays(3)])->save();

    $nuevo = unPedidoDelCliente($cliente, Pedido::ESTADO_COTIZADO);
    $nuevo->forceFill(['updated_at' => Carbon::now()])->save();

    unPedidoDelCliente($otro, Pedido::ESTADO_COTIZADO);

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getpedidos')
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('pedidos'))->toHaveCount(2)
        ->and($respuesta->json('pedidos.0._id'))->toBe((string) $nuevo->id)
        ->and($respuesta->json('pedidos.1._id'))->toBe((string) $viejo->id);
});

test('the list carries the fields the screen prints and not the one who loaded it', function () {
    $cliente = unClienteConPedidos();
    unPedidoDelCliente($cliente, Pedido::ESTADO_COTIZADO);

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getpedidos')
        ->assertOk();

    // `listado.page.html` imprime numero, created_at, estado y valor.
    expect($respuesta->json('pedidos.0'))
        ->toHaveKeys(['_id', 'numero', 'estado', 'valor', 'created_at'])
        // ⚠️ Quién cargó el pedido: si lo cargó el panel es un empleado, con su email. La
        // app no lo lee. Ver SEC-14.
        ->and($respuesta->json('pedidos.0'))->not->toHaveKey('user')
        // Sin los dos ceros de atrás, como todo el dinero del proyecto.
        ->and($respuesta->json('pedidos.0.valor'))->toBe('3000');
});

test('the state travels as the word the screens compare against', function () {
    // ⚠️ `pedido.page.ts:32` y `listado.page.ts:46` hacen `=== 'Sin asignar'`, `=== 'En
    // curso'` y compañía. No es un código interno que se pueda renombrar.
    $cliente = unClienteConPedidos();
    unPedidoDelCliente($cliente, Pedido::ESTADO_EN_CURSO);

    $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getpedidos')
        ->assertOk()
        ->assertJsonPath('pedidos.0.estado', 'En curso');
});

// ---------------------------------------------------------------------- el detalle

test('the detail brings the cadete only while the order is on the way', function () {
    Storage::fake(FotosDePerfil::disco());

    $cliente = unClienteConPedidos();

    $rolCadete = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $cuentaDelCadete = User::factory()->create(['rol_id' => $rolCadete->id, 'email' => 'cad@example.test']);

    Storage::disk(FotosDePerfil::disco())->put(FotosDePerfil::CARPETA.'/c.jpg', 'bytes');
    UserFoto::query()->create([
        'user_id' => $cuentaDelCadete->id,
        'ruta_archivo' => FotosDePerfil::CARPETA.'/c.jpg',
    ]);

    $cadete = Cadete::factory()->create([
        'user_id' => $cuentaDelCadete->id,
        'numero_movil' => 77,
        'nombres' => 'Juan',
        'apellidos' => 'Pérez',
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
    ]);

    $enCurso = unPedidoDelCliente($cliente, Pedido::ESTADO_EN_CURSO, ['cadete_id' => $cadete->id]);
    $asignado = unPedidoDelCliente($cliente, Pedido::ESTADO_ASIGNADO, ['cadete_id' => $cadete->id]);

    $conCadete = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/pedidos/'.$enCurso->id)
        ->assertOk();

    // `pedido.page.html:90-99` lee estos cuatro, y la foto va a un `<img [src]>`.
    expect($conCadete->json('pedido.cadete.nombres'))->toBe('Juan')
        ->and($conCadete->json('pedido.cadete.apellidos'))->toBe('Pérez')
        ->and($conCadete->json('pedido.cadete.numero_movil'))->toBe(77)
        ->and($conCadete->json('pedido.cadete.user.foto'))->toBeString()
        // ⚠️ El sistema viejo mandaba el cadete entero: DNI, teléfono, dirección, saldo,
        // deuda y el token de notificaciones. Ver SEC-14.
        ->and($conCadete->json('pedido.cadete'))->not->toHaveKey('dni')
        ->and($conCadete->json('pedido.cadete'))->not->toHaveKey('telefono')
        ->and($conCadete->json('pedido.cadete'))->not->toHaveKey('cobranza_saldo');

    // ⚠️ Mientras está asignado y sin confirmar, quién lo tiene no es asunto del cliente: la
    // pantalla sólo lo dibuja «En curso».
    $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/pedidos/'.$asignado->id)
        ->assertOk()
        ->assertJsonPath('pedido.cadete', null);
});

test('somebody else order answers 404 and not 403', function () {
    // ⚠️ El binding resuelve cualquier pedido por id sin mirar de quién es, y un 403
    // confirmaría que existe. Los ids son correlativos.
    $cliente = unClienteConPedidos();
    $otro = unClienteConPedidos(['telefono' => '3810000000'], 'otro@example.test');

    $ajeno = unPedidoDelCliente($otro, Pedido::ESTADO_COTIZADO);

    foreach ([
        ['getJson', '/api/clientesapp/pedidos/'.$ajeno->id],
        ['postJson', '/api/clientesapp/pedidos/'.$ajeno->id.'/aceptar'],
        ['deleteJson', '/api/clientesapp/pedidos/'.$ajeno->id],
    ] as [$metodo, $url]) {
        $this->actingAs($cliente->user, 'sanctum')
            ->{$metodo}($url)
            ->assertStatus(404)
            ->assertJsonPath('error', 'No se encontró el pedido solicitado.');
    }

    expect($ajeno->fresh())->not->toBeNull()
        ->and($ajeno->fresh()->estado)->toBe(Pedido::ESTADO_COTIZADO);
});

// ---------------------------------------------------------------------- aceptar

test('the client accepts the quote and the change is logged', function () {
    $cliente = unClienteConPedidos();
    $pedido = unPedidoDelCliente($cliente, Pedido::ESTADO_COTIZADO);

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/pedidos/'.$pedido->id.'/aceptar')
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_ACEPTADO)
        ->and(LogEstadoPedido::query()->where('pedido_id', $pedido->id)->count())->toBe(1);
});

test('the second tap answers the same as the first', function () {
    // Desde «Aceptado» es el mismo paso repetido: el cliente ya había confirmado.
    $cliente = unClienteConPedidos();
    $pedido = unPedidoDelCliente($cliente, Pedido::ESTADO_COTIZADO);

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/pedidos/'.$pedido->id.'/aceptar')->assertOk();
    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/pedidos/'.$pedido->id.'/aceptar')->assertOk();

    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_ACEPTADO);
});

test('an order already on the way cannot be moved back to accepted', function () {
    // ⚠️ Es API-3: antes CUALQUIER estado pasaba a «Aceptado», así que un pedido En curso o
    // Finalizado volvía atrás.
    $cliente = unClienteConPedidos();

    foreach ([Pedido::ESTADO_EN_CURSO, Pedido::ESTADO_FINALIZADO, Pedido::ESTADO_SIN_ASIGNAR] as $estado) {
        $pedido = unPedidoDelCliente($cliente, $estado);

        $this->actingAs($cliente->user, 'sanctum')
            ->postJson('/api/clientesapp/pedidos/'.$pedido->id.'/aceptar')
            ->assertStatus(422)
            ->assertJsonPath('error', 'El pedido ya no se puede aceptar.');

        expect($pedido->fresh()->estado)->toBe($estado);
    }
});

// ---------------------------------------------------------------------- cancelar

test('the client cancels an order that has no cadete yet', function () {
    $cliente = unClienteConPedidos();

    foreach (PedidoDelClienteApp::SE_PUEDE_CANCELAR as $estado) {
        $pedido = unPedidoDelCliente($cliente, $estado);

        $this->actingAs($cliente->user, 'sanctum')
            ->deleteJson('/api/clientesapp/pedidos/'.$pedido->id)
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Borrado lógico: desaparece del listado y la fila queda.
        expect(Pedido::query()->find($pedido->id))->toBeNull()
            ->and(Pedido::withTrashed()->find($pedido->id))->not->toBeNull();
    }
});

test('an order with a cadete can no longer be cancelled', function () {
    // ⚠️ Es API-3: antes se borraba en cualquier estado, En curso y Finalizado incluidos, o
    // sea con el cadete en la calle.
    $cliente = unClienteConPedidos();

    foreach ([Pedido::ESTADO_ASIGNADO, Pedido::ESTADO_EN_CURSO, Pedido::ESTADO_FINALIZADO] as $estado) {
        $pedido = unPedidoDelCliente($cliente, $estado);

        $this->actingAs($cliente->user, 'sanctum')
            ->deleteJson('/api/clientesapp/pedidos/'.$pedido->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'El pedido ya no se puede cancelar.');

        expect(Pedido::query()->find($pedido->id))->not->toBeNull();
    }
});

// ------------------------------------------------------------------- cotizar (v2)

test('the quote uses the same tariff as the counter', function () {
    // ⚠️ En el sistema viejo esto llama a `Pedido::cotizar()`, el mismo método que el panel,
    // pero nadie garantizaba que siguieran coincidiendo. Acá es el mismo `Cotizador`, con
    // las pruebas cruzadas de `CotizadorTest` detrás.
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/v2/clientesapp/pedidos/cotizar?distancia=3000&retorno_origen=0')
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        // La app declara `costo: number` en su única interfaz y lo guarda para el alta.
        ->and($respuesta->json('costo'))->toBeString()
        ->and($respuesta->json('tiempo_de_demora'))->toBe('30 a 45 minutos');
});

test('the quote charges the return and the money carried', function () {
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    $simple = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/v2/clientesapp/pedidos/cotizar?distancia=3000&retorno_origen=0')
        ->json('costo');

    $conRetorno = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/v2/clientesapp/pedidos/cotizar?distancia=3000&retorno_origen=1')
        ->json('costo');

    $conPlata = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/v2/clientesapp/pedidos/cotizar?distancia=3000&retorno_origen=0&envia_dinero_monto=50000')
        ->json('costo');

    expect(bccomp($conRetorno, $simple, 2))->toBe(1)
        ->and(bccomp($conPlata, $simple, 2))->toBe(1);
});

test('a distance that is not a number does not reach the tariff', function () {
    // ⚠️ El viejo valida `numeric` y después hace `intval`, así que «abc» y -5 entraban y
    // cotizaban el mínimo.
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    foreach (['abc', '-5', '1.5'] as $malo) {
        $this->actingAs($cliente->user, 'sanctum')
            ->getJson('/api/v2/clientesapp/pedidos/cotizar?distancia='.$malo.'&retorno_origen=0')
            ->assertStatus(422);
    }
});

// ---------------------------------------------------------------- el alta (v2)

test('the client loads an order and it is born accepted', function () {
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/v2/clientesapp/pedidos', elFormularioDeAlta())
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue();

    $pedido = Pedido::query()->sole();

    expect($pedido->estado)->toBe(Pedido::ESTADO_ACEPTADO)
        ->and($pedido->plataforma_origen)->toBe(Pedido::PLATAFORMA_APP)
        ->and($pedido->cliente_id)->toBe($cliente->id)
        ->and($pedido->user_id)->toBe($cliente->user_id)
        // `numero` sale de `pedidos_numero_seq` por DEFAULT.
        ->and($pedido->numero)->toBeInt()
        ->and(LogEstadoPedido::query()->where('pedido_id', $pedido->id)->count())->toBe(1);
});

test('the addresses are built the way the printed slip reads them', function () {
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/v2/clientesapp/pedidos', elFormularioDeAlta([
            'direccion_origen_depto' => '3B',
        ]))
        ->assertOk();

    $pedido = Pedido::query()->sole();

    expect($pedido->direccion)->toBe('San Luis 312 3B, San Miguel de Tucumán')
        // ⚠️ El sistema viejo pregunta `has('direccion_destino_depto')`, y `has()` es
        // verdadero cuando la clave está aunque valga null: la app la manda siempre, así que
        // la dirección quedaba con un espacio de más antes de la coma.
        ->and($pedido->destino)->toBe('Muñecas 500, San Miguel de Tucumán');
});

test('the money fields land as text, with null where nothing came', function () {
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/v2/clientesapp/pedidos', elFormularioDeAlta([
            'costo' => '4500.5',
            'envia_dinero_monto' => '20000',
            'valor_declarado' => null,
        ]))
        ->assertOk();

    $pedido = Pedido::query()->sole();

    expect($pedido->valor)->toBe('4500.50')
        ->and($pedido->garantia)->toBe('20000.00')
        ->and($pedido->valor_declarado)->toBeNull();
});

test('the depto rules point at the fields the app actually sends', function () {
    // ⚠️ El sistema viejo valida `direccion_origen_detalle` y `direccion_destino_detalle`,
    // que nadie manda; la app manda `..._depto`, que es lo que el código después lee. O sea
    // que el depto entraba sin control de tipo ni de largo. Allá quedó anotado como «TODO:
    // Fixear en una V3».
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/v2/clientesapp/pedidos', elFormularioDeAlta([
            'direccion_origen_depto' => 'un departamento con un nombre larguísimo',
        ]))
        ->assertStatus(422);

    expect($respuesta->json('code'))->toBe(422)
        ->and($respuesta->json('error.direccion_origen_depto.0'))->toContain('departamento (A)');

    expect(Pedido::query()->count())->toBe(0);
});

test('the errors name the fields the way the person sees them', function () {
    laTarifaDeLaApp();

    $cliente = unClienteConPedidos();

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/v2/clientesapp/pedidos', elFormularioDeAlta([
            'direccion_destino_calle_altura' => '',
        ]))
        ->assertStatus(422);

    expect($respuesta->json('error.direccion_destino_calle_altura.0'))->toContain('calle y altura (B)');
});

// ------------------------------------------------------------------- la puerta

test('all six need a token and the client role', function () {
    $cliente = unClienteConPedidos();
    $pedido = unPedidoDelCliente($cliente, Pedido::ESTADO_COTIZADO);

    $this->getJson('/api/clientesapp/getpedidos')->assertUnauthorized();
    $this->getJson('/api/clientesapp/pedidos/'.$pedido->id)->assertUnauthorized();
    $this->postJson('/api/clientesapp/pedidos/'.$pedido->id.'/aceptar')->assertUnauthorized();
    $this->deleteJson('/api/clientesapp/pedidos/'.$pedido->id)->assertUnauthorized();
    $this->getJson('/api/v2/clientesapp/pedidos/cotizar?distancia=1000&retorno_origen=0')->assertUnauthorized();
    $this->postJson('/api/v2/clientesapp/pedidos', [])->assertUnauthorized();

    // ⚠️ El mismo guard lo usan las tres apps. Ver SEC-13.
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $cadete = User::factory()->create(['rol_id' => $rol->id, 'email' => 'c@example.test']);

    $this->actingAs($cadete, 'sanctum')->getJson('/api/clientesapp/getpedidos')->assertForbidden();
    $this->actingAs($cadete, 'sanctum')
        ->getJson('/api/v2/clientesapp/pedidos/cotizar?distancia=1000&retorno_origen=0')
        ->assertForbidden();
});

test('the dead v1 order creation is not published', function () {
    // ⚠️ Se borró del sistema viejo el 2026-08-11: el último pedido creado por esa vía fue
    // del 2025-01-30 mientras la v2 seguía activa. Ver DEP-2.
    $cliente = unClienteConPedidos();

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/pedidos', elFormularioDeAlta())
        ->assertNotFound();
});
