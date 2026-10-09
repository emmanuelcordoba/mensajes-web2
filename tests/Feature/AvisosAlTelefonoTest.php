<?php

use App\Jobs\AdvertenciaPedidoEnCurso;
use App\Jobs\CancelarPedidoSinConfirmar;
use App\Jobs\EnviarNotificacion;
use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\MotivoBloqueo;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Services\AsignacionDeCadete;
use App\Services\ConversacionConElCadete;
use App\Services\PedidoDelCadete;
use Illuminate\Support\Facades\Queue;

/*
| De dónde sale cada aviso al teléfono.
|
| El archivo de al lado —`NotificacionesAlTelefonoTest`— fija QUÉ se dice. Esto fija DESDE
| DÓNDE: los nueve lugares del sistema viejo que notifican, y que son los que un cadete o un
| cliente nota si faltan.
|
| ⚠️ Lo que se comprueba es que se encole el trabajo, no que llegue a Google: eso es el otro
| archivo. Acá importa que ningún camino se quede sin avisar.
*/

function unCadeteConTelefono(array $extra = [], string $email = 'avisos@example.test'): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);

    $usuario = User::factory()->create(['rol_id' => $rol->id, 'email' => $email]);

    return Cadete::factory()->create(array_merge([
        'user_id' => $usuario->id,
        'numero_movil' => 88,
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'orden_cola' => 10,
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
        'fcm_token' => 'token-del-cadete',
    ], $extra));
}

function conLaTarifaMinima(): void
{
    foreach (['COBRANZA_SALDO_PORCENTAJE' => '18', 'COSTO_MINIMO' => '3000'] as $nombre => $valor) {
        Configuracion::query()->updateOrCreate(
            ['nombre' => $nombre],
            ['titulo' => $nombre, 'tipo' => Configuracion::TIPO_NUMERO, 'valor' => $valor],
        );
    }
}

/** Qué avisos se encolaron, en cuerpos, que es lo que distingue a uno de otro. */
function avisosEncolados(): array
{
    return array_map(
        fn (array $encolado): string => $encolado['job']->cuerpo,
        Queue::pushedJobs()[EnviarNotificacion::class] ?? [],
    );
}

beforeEach(function () {
    Queue::fake();
});

// -------------------------------------------------- al cadete que recibe un pedido

test('the cadete is told when an order lands on him, from the one place both screens share', function () {
    // ⚠️ En el sistema viejo ese aviso está escrito TRES veces —el alta de Inicio, el modal
    // de la lista de espera y la pantalla de asignar—, cada una con su propio `if` del token.
    $cadete = unCadeteConTelefono();
    $pedido = Pedido::factory()->create(['estado' => Pedido::ESTADO_SIN_ASIGNAR, 'cadete_id' => null]);

    (new AsignacionDeCadete)->guardar($pedido, $cadete, ['valor' => '3000'], null);

    expect(avisosEncolados())->toBe(['Te acaban de asignar un nuevo pedido']);
});

test('even the one at the counter is told, like the old system does', function () {
    // ⚠️ El `if` del token está AFUERA del `switch` de estados, así que le llega también al
    // que se lo lleva del mostrador y al que estaba inactivo.
    conLaTarifaMinima();

    $cadete = unCadeteConTelefono(['estado' => Cadete::ESTADO_ACTIVO_WEB]);
    $pedido = Pedido::factory()->create(['estado' => Pedido::ESTADO_SIN_ASIGNAR, 'cadete_id' => null]);

    (new AsignacionDeCadete)->guardar($pedido, $cadete, ['valor' => '3000'], null);

    expect(avisosEncolados())->toBe(['Te acaban de asignar un nuevo pedido'])
        ->and($pedido->fresh()->estado)->toBe(Pedido::ESTADO_FINALIZADO);
});

test('a cadete without a token gets nothing queued', function () {
    $cadete = unCadeteConTelefono(['fcm_token' => null]);
    $pedido = Pedido::factory()->create(['estado' => Pedido::ESTADO_SIN_ASIGNAR, 'cadete_id' => null]);

    (new AsignacionDeCadete)->guardar($pedido, $cadete, ['valor' => '3000'], null);

    Queue::assertNotPushed(EnviarNotificacion::class);
});

// ------------------------------------------------------------------- al cliente

test('the client of the app is told when his order gets a price', function () {
    $cliente = Cliente::factory()->create(['plataforma' => 'app', 'fcm_token' => 'token-del-cliente']);

    $pedido = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_SIN_ASIGNAR,
        'plataforma_origen' => Pedido::PLATAFORMA_APP,
        'cliente_id' => $cliente->id,
        'cadete_id' => null,
        'valor' => null,
    ]);

    (new AsignacionDeCadete)->guardar($pedido, null, ['valor' => '4500'], null);

    expect(avisosEncolados())->toBe(['Su pedido fue cotizado.'])
        ->and($pedido->fresh()->estado)->toBe(Pedido::ESTADO_COTIZADO);
});

test('a counter order has no client app to tell', function () {
    $cliente = Cliente::factory()->create(['fcm_token' => 'token-del-comercio']);

    $pedido = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_SIN_ASIGNAR,
        'plataforma_origen' => Pedido::PLATAFORMA_WEB,
        'cliente_id' => $cliente->id,
        'cadete_id' => null,
    ]);

    (new AsignacionDeCadete)->guardar($pedido, null, ['valor' => '4500'], null);

    Queue::assertNotPushed(EnviarNotificacion::class);
});

test('an order whose client was let go does not blow up when it is quoted', function () {
    // ⚠️ El sistema viejo hace `$pedido->cliente->fcm_token` sin mirar, y `Cliente` usa
    // SoftDeletes: con el comercio dado de baja eso es un 500.
    $cliente = Cliente::factory()->create(['plataforma' => 'app', 'fcm_token' => 'token']);

    $pedido = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_SIN_ASIGNAR,
        'plataforma_origen' => Pedido::PLATAFORMA_APP,
        'cliente_id' => $cliente->id,
        'cadete_id' => null,
        'valor' => null,
    ]);

    $cliente->delete();

    (new AsignacionDeCadete)->guardar($pedido, null, ['valor' => '4500'], null);

    // El cliente borrado conserva su token en la fila, así que el aviso sale igual: lo que
    // se comprueba es que no revienta.
    expect($pedido->fresh()->estado)->toBe(Pedido::ESTADO_COTIZADO);
});

test('the client is told when the cadete takes the order and when he delivers it', function () {
    $cliente = Cliente::factory()->create(['plataforma' => 'app', 'fcm_token' => 'token-del-cliente']);
    $cadete = unCadeteConTelefono();

    $pedido = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_ASIGNADO,
        'plataforma_origen' => Pedido::PLATAFORMA_APP,
        'cliente_id' => $cliente->id,
        'cadete_id' => $cadete->id,
        'valor' => '3000.00',
    ]);

    $servicio = new PedidoDelCadete;

    expect($servicio->aceptar($pedido, $cadete))->toBeTrue()
        ->and(avisosEncolados())->toBe(['Su pedido está en camino.']);

    expect($servicio->finalizar($pedido->fresh(), $cadete->fresh()))->toBeTrue()
        ->and(avisosEncolados())->toBe(['Su pedido está en camino.', 'Su pedido fue realizado.']);
});

test('the second tap does not tell the client twice', function () {
    // ⚠️ Es la mitad de API-4: dos aceptar volvían a notificar al cliente y a reprogramar
    // el aviso de la hora.
    $cliente = Cliente::factory()->create(['plataforma' => 'app', 'fcm_token' => 'token-del-cliente']);
    $cadete = unCadeteConTelefono();

    $pedido = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_ASIGNADO,
        'plataforma_origen' => Pedido::PLATAFORMA_APP,
        'cliente_id' => $cliente->id,
        'cadete_id' => $cadete->id,
        'valor' => '3000.00',
    ]);

    $servicio = new PedidoDelCadete;

    $servicio->aceptar($pedido, $cadete);
    $servicio->aceptar($pedido->fresh(), $cadete->fresh());

    expect(avisosEncolados())->toBe(['Su pedido está en camino.']);
    Queue::assertPushed(AdvertenciaPedidoEnCurso::class, 1);
});

// --------------------------------------------------- el aviso del pedido en curso

test('taking an order schedules the warning for an hour later', function () {
    $cadete = unCadeteConTelefono();

    $pedido = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_ASIGNADO,
        'cadete_id' => $cadete->id,
        'valor' => '3000.00',
    ]);

    (new PedidoDelCadete)->aceptar($pedido, $cadete);

    Queue::assertPushed(AdvertenciaPedidoEnCurso::class, 1);
});

test('a cadete with no token gets no warning scheduled, since it would only notify', function () {
    $cadete = unCadeteConTelefono(['fcm_token' => null]);

    $pedido = Pedido::factory()->create([
        'estado' => Pedido::ESTADO_ASIGNADO,
        'cadete_id' => $cadete->id,
        'valor' => '3000.00',
    ]);

    (new PedidoDelCadete)->aceptar($pedido, $cadete);

    Queue::assertNotPushed(AdvertenciaPedidoEnCurso::class);
});

test('the warning only fires if the order is still his and still in progress', function () {
    $cadete = unCadeteConTelefono();
    $otro = unCadeteConTelefono(['numero_movil' => 89], 'otro-aviso@example.test');

    $enCurso = Pedido::factory()->create(['estado' => Pedido::ESTADO_EN_CURSO, 'cadete_id' => $cadete->id]);
    $finalizado = Pedido::factory()->create(['estado' => Pedido::ESTADO_FINALIZADO, 'cadete_id' => $cadete->id]);
    $deOtro = Pedido::factory()->create(['estado' => Pedido::ESTADO_EN_CURSO, 'cadete_id' => $otro->id]);

    (new AdvertenciaPedidoEnCurso($finalizado, $cadete))->handle();
    (new AdvertenciaPedidoEnCurso($deOtro, $cadete))->handle();

    Queue::assertNotPushed(EnviarNotificacion::class);

    (new AdvertenciaPedidoEnCurso($enCurso, $cadete))->handle();

    expect(avisosEncolados())->toBe(['Finalice el pedido antes de que sea bloqueado.']);
});

// ------------------------------------------------ el que pierde el pedido

test('the cadete who lost the order is told why', function () {
    $cadete = unCadeteConTelefono();
    $pedido = Pedido::factory()->create(['estado' => Pedido::ESTADO_ASIGNADO, 'cadete_id' => $cadete->id]);

    (new CancelarPedidoSinConfirmar($pedido))->handle();

    expect(avisosEncolados())->toBe(['Pedido perdido por no haberlo confirmado en un lapso de 5 minutos.'])
        ->and($pedido->fresh()->estado)->toBe(Pedido::ESTADO_CANCELADO);
});

test('an order the cadete did take tells nobody', function () {
    $cadete = unCadeteConTelefono();
    $pedido = Pedido::factory()->create(['estado' => Pedido::ESTADO_EN_CURSO, 'cadete_id' => $cadete->id]);

    (new CancelarPedidoSinConfirmar($pedido))->handle();

    Queue::assertNotPushed(EnviarNotificacion::class);
});

// --------------------------------------------------------------- el chat

test('the cadete is told when the central writes to him', function () {
    $cadete = unCadeteConTelefono();

    $rol = Rol::query()->firstOrCreate(['rol' => Rol::EMPLEADO], ['display_rol' => 'Empleado']);
    $central = User::factory()->create(['rol_id' => $rol->id]);

    (new ConversacionConElCadete)->escribeLaCentral($cadete, $central, 'Pasá por el local');

    expect(avisosEncolados())->toBe(['Tienes un nuevo mensaje.']);
});

test('what the cadete writes does not notify himself', function () {
    $cadete = unCadeteConTelefono();

    (new ConversacionConElCadete)->escribeElCadete($cadete, $cadete->user, 'Voy en camino');

    Queue::assertNotPushed(EnviarNotificacion::class);
});

// ---------------------------------------------------------- bloqueo y desbloqueo

test('blocking tells the cadete, with the reason, and takes him out of the queue', function () {
    $cadete = unCadeteConTelefono();

    $rol = Rol::query()->firstOrCreate(['rol' => Rol::ADMIN], ['display_rol' => 'Admin']);
    $admin = User::factory()->create(['rol_id' => $rol->id]);

    $this->actingAs($admin)
        ->patch(route('panel.cadetes.bloquear', $cadete), ['motivo' => MotivoBloqueo::FALTA_DE_PAGO])
        ->assertRedirect();

    expect(avisosEncolados())->toBe(['Por motivo de falta de pago, pasar pronto por administración.'])
        // ⚠️ El bloqueo viejo lo pone inactivo junto con el `bloqueado` de la cuenta, y acá
        // eso faltaba: `IngresoALaCola` sólo niega la ENTRADA, así que un cadete bloqueado
        // mientras estaba activo se quedaba en la cola y el mostrador podía darle pedidos.
        ->and($cadete->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO)
        ->and($cadete->fresh()->user->estaBloqueado())->toBeTrue();
});

test('unblocking tells him too, and does not put him back in the queue', function () {
    $cadete = unCadeteConTelefono(['estado' => Cadete::ESTADO_INACTIVO]);
    $cadete->user->bloqueado = MotivoBloqueo::FALTA_DE_PAGO;
    $cadete->user->save();

    $rol = Rol::query()->firstOrCreate(['rol' => Rol::ADMIN], ['display_rol' => 'Admin']);
    $admin = User::factory()->create(['rol_id' => $rol->id]);

    $this->actingAs($admin)
        ->delete(route('panel.cadetes.desbloquear', $cadete))
        ->assertRedirect();

    expect(avisosEncolados())->toBe(['Te acaban de desbloquear.'])
        // A la cola vuelve desde su app, o lo pone la central. Como en el sistema viejo.
        ->and($cadete->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO);
});
