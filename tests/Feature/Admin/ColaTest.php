<?php

use App\Jobs\CancelarPedidoSinConfirmar;
use App\Models\Cadete;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Services\EstadoDeLaCola;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| La pantalla de la cola, en /admin.
|
| Existe por el día del corte. El worker corría colgado de una consola, se cayó con
| `Lost connection` y se llevó dos trabajos sin ejecutarlos y sin anotarlos en
| `failed_jobs`. Un pedido quedó Asignado más de una hora sin su reloj de cinco minutos, y
| se descubrió consultando la base a mano.
|
| Lo que estos tests cuidan es que los dos números que delatan eso sigan significando lo
| que significan: «el más viejo esperando» y «pedidos sin reloj». Un contador de trabajos
| no habría avisado de nada —la cola tenía dos, que es una cola normal—.
*/

/** Un pedido Asignado a un cadete, con la hora de asignación que se quiera. */
function pedidoAsignadoHace(int $minutos): Pedido
{
    $pedido = Pedido::factory()
        ->enEstado(Pedido::ESTADO_ASIGNADO)
        ->create(['cadete_id' => Cadete::factory()]);

    // Directo a la tabla: `updated_at` se maneja solo y acá se necesita mentirle.
    DB::table('pedidos')
        ->where('id', $pedido->id)
        ->update(['updated_at' => now()->subMinutes($minutos)]);

    return $pedido->fresh();
}

test('an admin reaches the queue screen', function () {
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/admin/cola')
        ->assertSuccessful();
});

test('nobody else reaches the queue screen', function (?string $rol) {
    // ⚠️ El mismo recorte que el resto de /admin, y por el mismo motivo: acá hay botones
    // que reencolan y borran trabajos. Un empleado trabaja desde /panel.
    $user = $rol === null
        ? User::factory()->create(['rol_id' => null])
        : usuarioCon($rol);

    $this->actingAs($user)
        ->get('/admin/cola')
        ->assertForbidden();
})->with([
    'empleado' => Rol::EMPLEADO,
    'comercio' => Rol::RESTRINGIDO,
    'sin rol' => null,
]);

test('a deferred job that has not matured yet is not late', function () {
    // ⚠️ El reloj de los cinco minutos está esperando A PROPÓSITO. Si contara como atraso,
    // la alarma estaría encendida cada vez que alguien asigna un pedido y no diría nada.
    Queue::connection('database')->later(300, new CancelarPedidoSinConfirmar(pedidoAsignadoHace(0)));

    expect(app(EstadoDeLaCola::class)->segundosDelMasViejo())->toBeNull();
});

test('a job that is overdue is late, and says by how much', function () {
    Queue::connection('database')->push(new CancelarPedidoSinConfirmar(pedidoAsignadoHace(0)));

    DB::table('jobs')->update(['available_at' => now()->subMinutes(70)->getTimestamp()]);

    // Lo que se vio el día del corte: una hora, donde una cola sana mide segundos.
    expect(app(EstadoDeLaCola::class)->segundosDelMasViejo())->toBeGreaterThanOrEqual(4200);
});

test('the pedido comes from the model identifier and not from the job name', function () {
    // ⚠️ Éste es el test de una equivocación mía del 2026-11-08: buscar el id después de la
    // palabra «Pedido» lo encontraba dentro de «CancelarPedidoSinConfirmar», que también la
    // contiene, y devolvía un número que parecía un pedido y no lo era.
    $pedido = pedidoAsignadoHace(0);

    Queue::connection('database')->push(new CancelarPedidoSinConfirmar($pedido));

    expect(app(EstadoDeLaCola::class)->pendientes()->first()['pedidoId'])->toBe($pedido->id);
});

test('a pedido whose clock is still in the queue is not reported', function () {
    $pedido = pedidoAsignadoHace(10);

    Queue::connection('database')->push(new CancelarPedidoSinConfirmar($pedido));

    expect(app(EstadoDeLaCola::class)->pedidosSinReloj())->toBeEmpty();
});

test('a pedido that lost its clock is reported', function () {
    // El 959141: Asignado, pasados los cinco minutos, y sin nada en la cola que lo libere.
    $pedido = pedidoAsignadoHace(10);

    expect(app(EstadoDeLaCola::class)->pedidosSinReloj()->pluck('id')->all())
        ->toBe([$pedido->id]);
});

test('a pedido assigned a minute ago is not reported yet', function () {
    pedidoAsignadoHace(1);

    expect(app(EstadoDeLaCola::class)->pedidosSinReloj())->toBeEmpty();
});

test('a pedido assigned before the window is not reported', function () {
    // ⚠️ Sin esta ventana la pantalla no serviría: el ETL trajo ~206 pedidos en Asignado
    // del sistema viejo, de hace semanas, que nunca tuvieron un trabajo en esta cola.
    pedidoAsignadoHace(EstadoDeLaCola::HORAS_DE_VENTANA * 60 + 1);

    expect(app(EstadoDeLaCola::class)->pedidosSinReloj())->toBeEmpty();
});

test('forgetting a failed job takes it off the list', function () {
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => CancelarPedidoSinConfirmar::class]),
        'exception' => "RuntimeException: se cayó\n#0 en algún lado",
        'failed_at' => now(),
    ]);

    $estado = app(EstadoDeLaCola::class);

    expect($estado->fallados()->first()['motivo'])->toBe('RuntimeException: se cayó');

    $estado->olvidar($uuid);

    expect($estado->fallados())->toBeEmpty()
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
