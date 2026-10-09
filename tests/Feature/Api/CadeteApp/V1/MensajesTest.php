<?php

use App\Http\Requests\Api\CadeteApp\V1\EnviarMensajeDesdeLaAppRequest;
use App\Http\Resources\Api\CadeteApp\V1\MensajeDeLaApp;
use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\Rol;
use App\Models\User;
use App\Services\ConversacionConElCadete;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| El chat del cadete con la central.
|
| ⚠️ **ACÁ ESTÁ EL PUNTO MÁS FINO DE TODO EL CONTRATO DE LA APP.** La plantilla decide de qué
| lado de la pantalla va cada globo con `mensaje.cadete.user_id !== mensaje.user_id`, que es
| una comparación ESTRICTA entre dos ids. Si uno sale número y el otro texto nunca son
| iguales, y el cadete abre el chat y no encuentra nada de lo que escribió él. En MongoDB
| eran cadenas y nadie tuvo que pensarlo; con una clave serial de PostgreSQL hay que
| escribirlo y hay que sujetarlo.
*/

function unCadeteDelChat(array $extra = [], string $email = 'chat@example.test'): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);

    $usuario = User::factory()->create(['rol_id' => $rol->id, 'email' => $email]);

    return Cadete::factory()->create(array_merge([
        'user_id' => $usuario->id,
        'numero_movil' => 44,
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
    ], $extra));
}

/** Una cuenta de la central, que es la que contesta del otro lado. */
function laCentral(string $email = 'central@example.test'): User
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::EMPLEADO], ['display_rol' => 'Empleado']);

    return User::factory()->create(['rol_id' => $rol->id, 'email' => $email]);
}

/** Cómo decide la plantilla de la app de qué lado va el globo. */
function esDeLaCentral(array $mensaje): bool
{
    return $mensaje['cadete']['user_id'] !== $mensaje['user_id'];
}

// -------------------------------------------------------------- de qué lado va cada globo

test('the two ids that decide the side of each bubble are both strings', function () {
    $cadete = unCadeteDelChat();

    (new ConversacionConElCadete)->escribeElCadete($cadete, $cadete->user, 'Ya salgo');

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes')->assertOk();

    $mensaje = $respuesta->json('mensajes.0');

    // ⚠️ Los DOS, de un solo lugar. Uno número y el otro texto es la pantalla rota.
    expect($mensaje['user_id'])->toBeString()
        ->and($mensaje['cadete']['user_id'])->toBeString()
        ->and($mensaje['_id'])->toBeString()
        ->and($mensaje['cadete_id'])->toBeString()
        ->and($mensaje['cadete']['_id'])->toBeString();
});

test('each bubble lands on the side that wrote it', function () {
    $cadete = unCadeteDelChat();
    $central = laCentral();
    $servicio = new ConversacionConElCadete;

    $servicio->escribeLaCentral($cadete, $central, 'Pasá por el local');
    $servicio->escribeElCadete($cadete, $cadete->user, 'Voy en camino');

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes')->assertOk();

    $mensajes = $respuesta->json('mensajes');

    // Es la cuenta exacta que hace la plantilla, con los valores que salieron del JSON.
    expect(esDeLaCentral($mensajes[0]))->toBeTrue()
        ->and($mensajes[0]['mensaje'])->toBe('Pasá por el local')
        ->and(esDeLaCentral($mensajes[1]))->toBeFalse()
        ->and($mensajes[1]['mensaje'])->toBe('Voy en camino');
});

test('a bubble always knows which conversation it belongs to', function () {
    // ⚠️ `mensaje.cadete.user_id` sobre un `undefined` no es un globo del lado equivocado:
    // es un error de JavaScript que deja la pantalla a medio dibujar. Emitir un mensaje sin
    // su conversación no puede ser algo que pase en silencio.
    $cadete = unCadeteDelChat();
    $mensaje = (new ConversacionConElCadete)->escribeElCadete($cadete, $cadete->user, 'Hola');

    expect(fn () => (new MensajeDeLaApp($mensaje))
        ->toArray(request()))
        ->toThrow(RuntimeException::class);
});

// ------------------------------------------------------------------- la conversación

test('the conversation comes with the shape the app declares', function () {
    $cadete = unCadeteDelChat();

    (new ConversacionConElCadete)->escribeElCadete($cadete, $cadete->user, 'Hola');

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes')->assertOk();

    // `RespuestaMensajes` declara las dos claves, y el pie con el campo de escribir sólo se
    // dibuja si vino el cadete: `<ion-footer *ngIf="cadete">`.
    expect($respuesta->json('cadete._id'))->toBe((string) $cadete->id)
        ->and($respuesta->json('cadete.numero_movil'))->toBe('44')
        ->and($respuesta->json('mensajes.0'))
        ->toHaveKeys(['_id', 'mensaje', 'cadete_id', 'user_id', 'created_at', 'updated_at', 'cadete']);
});

test('the conversation does not repeat the whole cadete on every message', function () {
    $cadete = unCadeteDelChat();
    $servicio = new ConversacionConElCadete;

    foreach (range(1, 5) as $n) {
        $servicio->escribeElCadete($cadete, $cadete->user, 'mensaje '.$n);
    }

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes')->assertOk();

    // ⚠️ El modelo viejo declara `$with = ['cadete.user', 'user']`: cada mensaje arrastra el
    // cadete, la cuenta del cadete y la cuenta de quien escribió, y la plantilla lee de todo
    // eso UN campo. La conversación más larga son 351 mensajes y la app vuelve a pedir esto
    // cada 10 segundos con los datos del teléfono del cadete.
    expect($respuesta->json('mensajes.0.cadete'))->toHaveKeys(['_id', 'user_id'])
        ->and($respuesta->json('mensajes.0.cadete'))->toHaveCount(2)
        ->and($respuesta->json('mensajes.0'))->not->toHaveKey('user');
});

test('the conversation reads oldest first, even when that is not the order it was stored in', function () {
    // ⚠️ El sistema viejo no pide ningún orden, y sin `ORDER BY` el orden no está
    // especificado. En MongoDB coincidía con el de inserción y nadie tuvo que pensarlo.
    //
    // Acá el de inserción NO es el cronológico: el ETL cargó los mensajes en el orden en que
    // se los dio Mongo. Medido: 95 de las 444 conversaciones tienen mensajes cuyo id avanza
    // mientras la fecha retrocede, 200 pares. Esta conversación se arma igual —los ids en un
    // orden, las fechas en otro—, así que si alguien saca el `ORDER BY` el test cae.
    $cadete = unCadeteDelChat();
    $central = laCentral();

    // ⚠️ Con la factory y no con `create()`: `created_at` no está en el `#[Fillable]` del
    // modelo —y está bien que no esté—, así que la asignación masiva lo descarta en silencio
    // y los mensajes quedarían todos con la misma hora.
    foreach ([3, 1, 5, 2, 4] as $haceDias) {
        Mensaje::factory()->create([
            'cadete_id' => $cadete->id,
            'user_id' => $central->id,
            'mensaje' => 'hace '.$haceDias,
            'leido_web' => true,
            'leido_app' => false,
            'created_at' => Carbon::now()->subDays($haceDias),
        ]);
    }

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes')->assertOk();

    expect(array_column($respuesta->json('mensajes'), 'mensaje'))
        ->toBe(['hace 5', 'hace 4', 'hace 3', 'hace 2', 'hace 1']);
});

test('the unread ones also read in order, and not in the order they were stored', function () {
    $cadete = unCadeteDelChat();
    $central = laCentral();

    foreach ([2, 4, 1, 3] as $haceDias) {
        Mensaje::factory()->create([
            'cadete_id' => $cadete->id,
            'user_id' => $central->id,
            'mensaje' => 'hace '.$haceDias,
            'leido_web' => true,
            'leido_app' => false,
            'created_at' => Carbon::now()->subDays($haceDias),
        ]);
    }

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/mensajes/noleidos')
        ->assertOk();

    expect(array_column($respuesta->json('mensajes'), 'mensaje'))
        ->toBe(['hace 4', 'hace 3', 'hace 2', 'hace 1']);
});

test('two messages in the same second keep the order they were written in', function () {
    $this->freezeTime();

    $cadete = unCadeteDelChat();
    $central = laCentral();
    $servicio = new ConversacionConElCadete;

    // Es lo que pasa cuando la central contesta con dos renglones seguidos.
    $servicio->escribeLaCentral($cadete, $central, 'primero');
    $servicio->escribeLaCentral($cadete, $central, 'segundo');

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes')->assertOk();

    expect(array_column($respuesta->json('mensajes'), 'mensaje'))->toBe(['primero', 'segundo']);
});

test('opening the chat marks everything as read, in one statement', function () {
    $cadete = unCadeteDelChat();
    $central = laCentral();

    foreach (range(1, 6) as $n) {
        Mensaje::query()->create([
            'cadete_id' => $cadete->id,
            'user_id' => $central->id,
            'mensaje' => 'sin leer '.$n,
            'leido_web' => true,
            'leido_app' => false,
        ]);
    }

    // ⚠️ El sistema viejo traía los modelos y los guardaba uno por uno dentro de un
    // `foreach`: seis UPDATE con su ida y vuelta, y además tocando `updated_at` de un
    // mensaje que nadie editó. La app pide esto cada 10 segundos.
    $escrituras = 0;
    DB::listen(function (object $consulta) use (&$escrituras): void {
        if (str_starts_with(strtolower(ltrim($consulta->sql)), 'update')) {
            $escrituras++;
        }
    });

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes')->assertOk();

    expect($escrituras)->toBe(1)
        ->and(Mensaje::query()->where('cadete_id', $cadete->id)->sinLeerEnLaApp()->count())->toBe(0);
});

test('a chat with nothing in it is an empty list and not a 404', function () {
    $cadete = unCadeteDelChat();

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/mensajes')
        ->assertOk()
        ->assertJsonPath('mensajes', [])
        ->assertJsonPath('cadete._id', (string) $cadete->id);
});

test('a cadete does not read another cadete conversation', function () {
    $cadete = unCadeteDelChat();
    $otro = unCadeteDelChat(['numero_movil' => 45], 'otro-chat@example.test');

    (new ConversacionConElCadete)->escribeElCadete($otro, $otro->user, 'esto es mío');

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/mensajes')
        ->assertOk()
        ->assertJsonPath('mensajes', []);
});

// ---------------------------------------------------------------------- escribir

test('the cadete writes and gets the whole conversation back', function () {
    $cadete = unCadeteDelChat();
    $central = laCentral();

    (new ConversacionConElCadete)->escribeLaCentral($cadete, $central, 'Pasá por el local');

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/mensajes/store', ['mensaje' => 'Voy'])
        ->assertOk();

    // ⚠️ `enviarMensaje()` hace `this.mensajes = data['mensajes']` y descarta
    // `data['mensaje']`: sin la conversación entera el chat se vaciaría al enviar.
    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('mensaje.mensaje'))->toBe('Voy')
        ->and(array_column($respuesta->json('mensajes'), 'mensaje'))->toBe(['Pasá por el local', 'Voy']);
});

test('what the cadete writes is read on his side and unread on the other', function () {
    $cadete = unCadeteDelChat();

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/mensajes/store', ['mensaje' => 'Ya salgo'])
        ->assertOk();

    $mensaje = Mensaje::query()->where('cadete_id', $cadete->id)->sole();

    expect($mensaje->leido_app)->toBeTrue()
        ->and($mensaje->leido_web)->toBeFalse()
        ->and($mensaje->user_id)->toBe($cadete->user_id);
});

test('the two sides of read are the same rule mirrored', function () {
    // Una regla, dos lados. Si alguno de los cuatro valores se escribe mal, el mensaje
    // aparece leído sin que nadie lo haya visto y el otro lado no se entera nunca.
    $cadete = unCadeteDelChat();
    $central = laCentral();
    $servicio = new ConversacionConElCadete;

    $delCadete = $servicio->escribeElCadete($cadete, $cadete->user, 'del cadete');
    $deLaCentral = $servicio->escribeLaCentral($cadete, $central, 'de la central');

    expect($delCadete->leido_app)->toBe($deLaCentral->leido_web)
        ->and($delCadete->leido_web)->toBe($deLaCentral->leido_app)
        ->and($delCadete->leido_app)->toBeTrue()
        ->and($delCadete->leido_web)->toBeFalse();
});

test('the message always lands in the cadete own conversation', function () {
    // ⚠️ Es SEC-17: antes, un `cadete_id` en el cuerpo mandaba el mensaje a la conversación
    // de ESE cadete, y la central lo veía como escrito por él. La app nunca lo manda, pero
    // el que tiene un token sí puede.
    $cadete = unCadeteDelChat();
    $victima = unCadeteDelChat(['numero_movil' => 46], 'victima@example.test');

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/mensajes/store', [
            'mensaje' => 'esto no es mío',
            'cadete_id' => $victima->id,
            'user_id' => $victima->user_id,
            'leido_web' => true,
        ])
        ->assertOk();

    expect(Mensaje::query()->where('cadete_id', $victima->id)->count())->toBe(0);

    $mensaje = Mensaje::query()->where('cadete_id', $cadete->id)->sole();

    expect($mensaje->user_id)->toBe($cadete->user_id)
        ->and($mensaje->leido_web)->toBeFalse();
});

test('an empty message is refused in the shape the app reads', function () {
    $cadete = unCadeteDelChat();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/mensajes/store', ['mensaje' => ''])
        ->assertStatus(422);

    expect($respuesta->json('code'))->toBe(422
    )->and($respuesta->json('error.mensaje'))->toBeArray()->not->toBeEmpty();

    expect(Mensaje::query()->count())->toBe(0);
});

test('the length cap is above anything a cadete ever wrote', function () {
    // Medido sobre los 8.543 mensajes guardados: el promedio son 29 caracteres y el más
    // largo 6.247. El tope no es una regla de redacción —la app no puede mostrar el error,
    // así que un tope alcanzable le deja el botón de enviar muerto— sino un freno al abuso.
    $cadete = unCadeteDelChat();

    expect(EnviarMensajeDesdeLaAppRequest::LARGO_MAXIMO)->toBeGreaterThan(6_247);

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/mensajes/store', ['mensaje' => str_repeat('a', 6_247)])
        ->assertOk();

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/mensajes/store', [
            'mensaje' => str_repeat('a', EnviarMensajeDesdeLaAppRequest::LARGO_MAXIMO + 1),
        ])
        ->assertStatus(422);
});

// ------------------------------------------------------------------- los no leídos

test('the unread count is what the red badge on the tab reads', function () {
    // Con el tiempo congelado los tres caen en el mismo segundo, así que el orden de abajo
    // lo decide el desempate por `id`: son los 52 grupos del mismo segundo que hay medidos.
    $this->freezeTime();

    $cadete = unCadeteDelChat();
    $central = laCentral();

    foreach (range(1, 3) as $n) {
        (new ConversacionConElCadete)->escribeLaCentral($cadete, $central, 'sin leer '.$n);
    }

    (new ConversacionConElCadete)->escribeElCadete($cadete, $cadete->user, 'esto lo escribí yo');

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/mensajes/noleidos')
        ->assertOk();

    // `tabs.page.ts` lee `resp['cantidad']` y nada más; `mensajes` está en el contrato.
    expect($respuesta->json('cantidad'))->toBe(3)
        ->and($respuesta->json('mensajes'))->toHaveCount(3)
        ->and(array_column($respuesta->json('mensajes'), 'mensaje'))
        ->toBe(['sin leer 1', 'sin leer 2', 'sin leer 3']);
});

test('asking for the unread ones does not mark them as read', function () {
    $cadete = unCadeteDelChat();
    $central = laCentral();

    (new ConversacionConElCadete)->escribeLaCentral($cadete, $central, 'sin leer');

    // El globito se consulta desde la pestaña, sin abrir el chat: si esto marcara, el cadete
    // perdería el aviso de un mensaje que no vio.
    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/mensajes/noleidos')->assertOk();

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/mensajes/noleidos')
        ->assertOk()
        ->assertJsonPath('cantidad', 1);
});

test('the unread count only counts his own', function () {
    $cadete = unCadeteDelChat();
    $otro = unCadeteDelChat(['numero_movil' => 47], 'otro-no-leido@example.test');
    $central = laCentral();

    (new ConversacionConElCadete)->escribeLaCentral($otro, $central, 'para el otro');

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/mensajes/noleidos')
        ->assertOk()
        ->assertJsonPath('cantidad', 0);
});

// ------------------------------------------------------------------------ la puerta

test('the chat needs a token and a cadete', function () {
    $this->getJson('/api/mensajes')->assertUnauthorized();
    $this->getJson('/api/mensajes/noleidos')->assertUnauthorized();
    $this->postJson('/api/mensajes/store', ['mensaje' => 'hola'])->assertUnauthorized();

    // ⚠️ El rol se exige además del token: el mismo guard lo usan las apps de clientes, que
    // se registran solas. Ver SEC-13.
    $cliente = User::factory()->create([
        'rol_id' => Rol::query()->firstOrCreate(
            ['rol' => Rol::CLIENTE_APP],
            ['display_rol' => 'Cliente app'],
        )->id,
    ]);

    $this->actingAs($cliente, 'sanctum')->getJson('/api/mensajes')->assertForbidden();
    $this->actingAs($cliente, 'sanctum')
        ->postJson('/api/mensajes/store', ['mensaje' => 'hola'])
        ->assertForbidden();
});
