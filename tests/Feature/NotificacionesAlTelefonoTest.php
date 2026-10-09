<?php

use App\Jobs\EnviarNotificacion;
use App\Models\MotivoBloqueo;
use App\Services\AccesoAFirebase;
use App\Services\NotificacionesAlTelefono;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
| Lo que el sistema le dice al teléfono de alguien.
|
| ⚠️ **Los textos son los del sistema viejo, palabra por palabra.** Son los que la gente
| viene leyendo en la mano desde años. Este archivo los fija: cambiar «Te acaban de asignar
| un nuevo pedido» por otra redacción es una decisión, no un refactor.
|
| ⚠️ Y `data` NO se manda, igual que allá, aunque cada aviso tenga su `type`. El porqué —y lo
| que cambiaría prenderlo— está en `EnviarNotificacion` y en `config/services.php`.
*/

/** @return array{string, string, string|null} */
function loQueSeEncolo(): array
{
    $encolados = Queue::pushedJobs()[EnviarNotificacion::class] ?? [];

    expect($encolados)->toHaveCount(1);

    $trabajo = $encolados[0]['job'];

    return [$trabajo->titulo, $trabajo->cuerpo, $trabajo->tipo];
}

beforeEach(function () {
    Queue::fake();
});

// -------------------------------------------------------------- los textos

test('the words are the ones from the old system', function (string $metodo, array $argumentos, string $titulo, string $cuerpo, ?string $tipo) {
    NotificacionesAlTelefono::{$metodo}('token-del-telefono', ...$argumentos);

    expect(loQueSeEncolo())->toBe([$titulo, $cuerpo, $tipo]);
})->with([
    'le asignaron un pedido' => [
        'nuevoPedido', [], 'Nuevo pedido', 'Te acaban de asignar un nuevo pedido', 'nuevo-pedido',
    ],
    'le escribieron' => [
        'nuevoMensaje', [], 'Nuevo mensaje', 'Tienes un nuevo mensaje.', 'nuevo-mensaje',
    ],
    'lo desbloquearon' => [
        'desbloqueado', [], 'Desbloqueado', 'Te acaban de desbloquear.', 'desbloqueado',
    ],
    'tiene un pedido sin finalizar' => [
        'pedidoSinFinalizar', [], 'Pedido sin finalizar', 'Finalice el pedido antes de que sea bloqueado.', null,
    ],
    'perdió el pedido' => [
        'pedidoPerdido', [], 'Atención', 'Pedido perdido por no haberlo confirmado en un lapso de 5 minutos.', null,
    ],
    'al cliente, cotizado' => [
        'pedidoCotizado', [], 'Pedido actualizado', 'Su pedido fue cotizado.', 'pedido-actualizado',
    ],
    'al cliente, en camino' => [
        'pedidoEnCamino', [], 'Pedido actualizado', 'Su pedido está en camino.', 'pedido-actualizado',
    ],
    'al cliente, entregado' => [
        'pedidoFinalizado', [], 'Pedido finalizado', 'Su pedido fue realizado.', 'pedido-finalizado',
    ],
]);

test('each reason for blocking has its own words', function () {
    NotificacionesAlTelefono::bloqueado('token', MotivoBloqueo::FALTA_DE_PAGO);

    expect(loQueSeEncolo())->toBe([
        'Bloqueado',
        'Por motivo de falta de pago, pasar pronto por administración.',
        'bloqueado-'.MotivoBloqueo::FALTA_DE_PAGO,
    ]);
});

test('with a claim, the other words', function () {
    NotificacionesAlTelefono::bloqueado('token', MotivoBloqueo::RECLAMO_PEDIDO);

    expect(loQueSeEncolo()[1])->toBe('Por motivo de reclamo en pedido, pasar pronto por administración.');
});

test('with another reason, the body is what the operator typed', function () {
    // ⚠️ Por eso el campo está limitado a 120 caracteres en el formulario del panel: entra
    // en una notificación.
    NotificacionesAlTelefono::bloqueado('token', MotivoBloqueo::OTRO_MOTIVO, 'Pasá por la oficina el lunes.');

    expect(loQueSeEncolo()[1])->toBe('Pasá por la oficina el lunes.');
});

test('a block with another reason and no text still says something', function () {
    // No se llega desde el panel —el validador lo exige— pero una notificación en blanco es
    // peor que el texto de siempre.
    NotificacionesAlTelefono::bloqueado('token', MotivoBloqueo::OTRO_MOTIVO, null);

    expect(loQueSeEncolo()[1])->toBe('Pasar pronto por administración.');
});

// ------------------------------------------------------ a quién no se le avisa

test('without a token there is nobody to notify, and nothing is queued', function () {
    // ⚠️ En el sistema viejo este `if` está escrito en los nueve lugares que notifican, y en
    // uno de ellos mira una variable y la llamada usa otra. Preguntar en un solo lado es lo
    // que lo saca de los nueve.
    NotificacionesAlTelefono::nuevoPedido(null);
    NotificacionesAlTelefono::nuevoMensaje('');
    NotificacionesAlTelefono::nuevoMensaje('   ');

    Queue::assertNothingPushed();
});

// ------------------------------------------------------- cómo sale a la red

test('the body is the one FCM v1 expects, and it does not carry data', function () {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/x/messages/1'])]);
    Cache::put(AccesoAFirebase::CLAVE_DEL_TOKEN, 'un-access-token', now()->addMinutes(10));
    // ⚠️ El interruptor se fija acá y no se hereda del entorno: lo que este test comprueba
    // es la forma del cuerpo con el interruptor apagado, que es la del sistema viejo. Sin
    // esta línea, el test pasa o falla según lo que cada uno tenga en su `.env`.
    config([
        'services.firebase.proyecto' => 'nuggo-mensajescadeteria',
        'services.firebase.enviar_el_tipo' => false,
    ]);

    (new EnviarNotificacion('token-del-telefono', 'Nuevo pedido', 'Te toca', 'nuevo-pedido'))->handle();

    Http::assertSent(function ($peticion): bool {
        $cuerpo = $peticion->data();

        return $peticion->url() === 'https://fcm.googleapis.com/v1/projects/nuggo-mensajescadeteria/messages:send'
            && $peticion->hasHeader('Authorization', 'Bearer un-access-token')
            && $cuerpo['message']['token'] === 'token-del-telefono'
            && $cuerpo['message']['notification'] === ['title' => 'Nuevo pedido', 'body' => 'Te toca']
            // ⚠️ El sistema viejo arma `['token', 'notification']` y nada más: el `type` que
            // cada sitio escribe nunca sale del servidor.
            && ! array_key_exists('data', $cuerpo['message']);
    });
});

test('the switch is what puts the type on the wire', function () {
    Http::fake(['fcm.googleapis.com/*' => Http::response([])]);
    Cache::put(AccesoAFirebase::CLAVE_DEL_TOKEN, 'un-access-token', now()->addMinutes(10));
    config([
        'services.firebase.proyecto' => 'proyecto',
        'services.firebase.enviar_el_tipo' => true,
    ]);

    (new EnviarNotificacion('token', 'Nuevo pedido', 'Te toca', 'nuevo-pedido'))->handle();

    Http::assertSent(fn ($peticion): bool => $peticion->data()['message']['data'] === ['type' => 'nuevo-pedido']);
});

test('without Firebase configured nothing is sent, and it is written down', function () {
    Http::fake();
    Log::spy();
    Cache::flush();
    config(['services.firebase.proyecto' => null, 'services.firebase.credenciales' => null]);

    (new EnviarNotificacion('token', 'Nuevo pedido', 'Te toca'))->handle();

    Http::assertNothingSent();

    // ⚠️ Sin esto, una instalación sin credenciales se traga todas las notificaciones en
    // silencio y nadie se entera hasta que un cadete pregunta por qué no le avisan.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'Firebase no está configurado'))
        ->once();
});

test('the warning about the missing configuration is once an hour, not once per notification', function () {
    Http::fake();
    Log::spy();
    Cache::flush();
    config(['services.firebase.proyecto' => null, 'services.firebase.credenciales' => null]);

    foreach (range(1, 5) as $ignorado) {
        (new EnviarNotificacion('token', 'Nuevo pedido', 'Te toca'))->handle();
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'Firebase no está configurado'))
        ->once();
});

test('a token Firebase refuses is written down instead of disappearing', function () {
    Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404)]);
    Cache::put(AccesoAFirebase::CLAVE_DEL_TOKEN, 'un-access-token', now()->addMinutes(10));
    config(['services.firebase.proyecto' => 'proyecto']);
    Log::spy();

    (new EnviarNotificacion('token-vencido', 'Nuevo pedido', 'Te toca'))->handle();

    // ⚠️ El sistema viejo sólo mira si curl falló, así que un token vencido se pierde sin
    // dejar rastro. Esto no cambia qué se manda: deja de ser invisible.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje, array $contexto): bool => $mensaje === 'Firebase rechazó la notificación'
            && $contexto['estado'] === 404
            && $contexto['error'] === 'UNREGISTERED')
        ->once();
});

test('the phone token never reaches a log', function () {
    Http::fake(['fcm.googleapis.com/*' => fn () => throw new RuntimeException('se cortó la red')]);
    Cache::put(AccesoAFirebase::CLAVE_DEL_TOKEN, 'un-access-token', now()->addMinutes(10));
    config(['services.firebase.proyecto' => 'proyecto']);
    Log::spy();

    (new EnviarNotificacion('EL-TOKEN-DEL-TELEFONO', 'Nuevo pedido', 'Te toca'))->handle();

    // ⚠️ Identifica el teléfono de una persona. El sistema viejo ya tenía ese cuidado.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje, array $contexto): bool => ! str_contains(
            $mensaje.json_encode($contexto),
            'EL-TOKEN-DEL-TELEFONO',
        ))
        ->once();
});

// ------------------------------------------------------------- el access token

test('the access token is asked once and reused', function () {
    // ⚠️ El sistema viejo pide uno nuevo en CADA notificación: una ida y vuelta a Google por
    // cada aviso, firmando un JWT cada vez, para un token que dura una hora.
    Cache::flush();
    Cache::put(AccesoAFirebase::CLAVE_DEL_TOKEN, 'cacheado', now()->addMinutes(10));

    expect(AccesoAFirebase::token())->toBe('cacheado')
        ->and(AccesoAFirebase::token())->toBe('cacheado');
});

test('a failure to get the token is not cached', function () {
    // Un problema de red de un segundo no puede dejar las notificaciones apagadas 50 minutos.
    Cache::flush();
    config(['services.firebase.credenciales' => null]);

    expect(AccesoAFirebase::token())->toBeNull()
        ->and(Cache::get(AccesoAFirebase::CLAVE_DEL_TOKEN))->toBeNull();
});

test('credentials can be the whole json or a path, and a broken one is refused quietly', function () {
    Cache::flush();
    Log::spy();

    // Lo que hace falta donde no hay disco propio al que subir un archivo.
    config(['services.firebase.credenciales' => json_encode([
        'type' => 'service_account',
        'client_email' => 'x@y.iam.gserviceaccount.com',
        'private_key' => '-----BEGIN PRIVATE KEY-----\nno es una clave\n-----END PRIVATE KEY-----\n',
    ])]);
    config(['services.firebase.proyecto' => 'proyecto']);

    expect(AccesoAFirebase::estaConfigurado())->toBeTrue();

    // Y un JSON que no tiene lo que hace falta no explota: se dice y no se manda.
    config(['services.firebase.credenciales' => '{"type":"service_account"}']);

    expect(AccesoAFirebase::estaConfigurado())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'No se pudo leer'))
        ->atLeast()->once();
});

test('what is read from the credentials never reaches a log', function () {
    Cache::flush();
    Log::spy();

    config(['services.firebase.credenciales' => '{"private_key":"LA-CLAVE-PRIVADA"}']);

    // `token()` y no `estaConfigurado()`: ése pregunta primero por el proyecto y corta,
    // así que no llegaría a leer las credenciales.
    AccesoAFirebase::token();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje, ...$resto): bool => ! str_contains(
            $mensaje.json_encode($resto),
            'LA-CLAVE-PRIVADA',
        ))
        ->atLeast()->once();
});
