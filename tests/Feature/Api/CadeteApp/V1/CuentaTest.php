<?php

use App\Models\ActividadCadete;
use App\Models\Cadete;
use App\Models\Configuracion;
use App\Models\MotivoBloqueo;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserFoto;
use Illuminate\Support\Facades\Storage;

/*
| La API de la app del cadete: los cuatro endpoints con los que entra a la cola.
|
| ⚠️ **Lo que estos tests fijan es un contrato que no podemos cambiar.** La app está
| publicada en los teléfonos de los cadetes y declara lo que lee en
| `mensajes-cadeteria-cadete-app/src/app/interfaces/interfaces.ts`. Un campo que falte o que
| cambie de tipo JSON es una pantalla rota para alguien que está trabajando, y no se entera
| nadie hasta que llama por teléfono. Por eso se comprueban los NOMBRES y los TIPOS, no sólo
| que la respuesta sea 200.
*/

function unCadeteDeLaApp(array $delCadete = [], array $delUsuario = []): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);

    $usuario = User::factory()->create(array_merge([
        'rol_id' => $rol->id,
        'email' => 'cadete@example.test',
        'password' => 'LaClaveDelCadete.1',
    ], $delUsuario));

    return Cadete::factory()->create(array_merge([
        'user_id' => $usuario->id,
        'numero_movil' => 77,
        'estado' => Cadete::ESTADO_INACTIVO,
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
        'cobranza_saldo' => '5000.00',
    ], $delCadete));
}

function laTarifaMinima(): void
{
    foreach (['COBRANZA_SALDO_PORCENTAJE' => '18', 'COSTO_MINIMO' => '3000'] as $nombre => $valor) {
        Configuracion::query()->updateOrCreate(
            ['nombre' => $nombre],
            ['titulo' => $nombre, 'tipo' => Configuracion::TIPO_NUMERO, 'valor' => $valor],
        );
    }
}

// ----------------------------------------------------------------------------- el login

test('a cadete logs in and gets a token', function () {
    $cadete = unCadeteDeLaApp();

    $respuesta = $this->postJson('/api/cadetes/login', [
        'email' => 'cadete@example.test',
        'password' => 'LaClaveDelCadete.1',
    ])->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        // ⚠️ La app lee exactamente `res['access_token']` —`auth.service.ts:52`—. El nombre
        // no cambia aunque el token ya no sea de Passport sino de Sanctum.
        ->and($respuesta->json('access_token'))->toBeString()->not->toBeEmpty()
        ->and($respuesta->json('user._id'))->toBe((string) $cadete->user_id);
});

test('the login answers the user with the shape the app declares', function () {
    $cadete = unCadeteDeLaApp();

    $usuario = $this->postJson('/api/cadetes/login', [
        'email' => 'cadete@example.test',
        'password' => 'LaClaveDelCadete.1',
    ])->json('user');

    // Los campos de `interface User`, y ninguno más: el sistema viejo mandaba el modelo
    // entero —`rol_id`, `bloqueado_mensaje`, `codigo_de_verificacion`, `deleted_at`—.
    expect(array_keys($usuario))->toBe([
        '_id', 'name', 'email', 'foto', 'rol_id', 'bloqueado', 'created_at', 'updated_at', 'cadete',
    ]);

    expect($usuario['_id'])->toBeString()
        ->and($usuario['bloqueado'])->toBeBool()
        ->and($usuario['cadete']['_id'])->toBe((string) $cadete->id);
});

test('the login does not say which of the three things was wrong', function () {
    unCadeteDeLaApp();

    // No existe, contraseña equivocada, y una cuenta que no es cadete: el mismo texto.
    $mensajes = [];

    $mensajes[] = $this->postJson('/api/cadetes/login', [
        'email' => 'nadie@example.test', 'password' => 'LaClaveDelCadete.1',
    ])->assertStatus(400)->json('error');

    $mensajes[] = $this->postJson('/api/cadetes/login', [
        'email' => 'cadete@example.test', 'password' => 'otra',
    ])->assertStatus(400)->json('error');

    $empleado = usuarioCon(Rol::EMPLEADO);
    $empleado->update(['password' => 'LaClaveDelCadete.1']);

    $mensajes[] = $this->postJson('/api/cadetes/login', [
        'email' => $empleado->email, 'password' => 'LaClaveDelCadete.1',
    ])->assertStatus(400)->json('error');

    expect(array_unique($mensajes))->toHaveCount(1);
});

test('too many wrong passwords stop the attempts, and a good one clears the count', function () {
    // ⚠️ Cuentan sólo los intentos FALLIDOS. Con un `throttle` en la ruta, un cadete que
    // reinstala la app o al que se le vence el token quedaría afuera por entrar bien.
    unCadeteDeLaApp();

    foreach (range(1, 9) as $intento) {
        $this->postJson('/api/cadetes/login', [
            'email' => 'cadete@example.test', 'password' => 'mal',
        ])->assertStatus(400);
    }

    // El décimo todavía entra bien y limpia el contador.
    $this->postJson('/api/cadetes/login', [
        'email' => 'cadete@example.test', 'password' => 'LaClaveDelCadete.1',
    ])->assertOk();

    foreach (range(1, 10) as $intento) {
        $this->postJson('/api/cadetes/login', [
            'email' => 'cadete@example.test', 'password' => 'mal',
        ])->assertStatus(400);
    }

    $this->postJson('/api/cadetes/login', [
        'email' => 'cadete@example.test', 'password' => 'LaClaveDelCadete.1',
    ])->assertStatus(429)->assertHeader('Retry-After');
});

test('the email is matched without caring about case', function () {
    unCadeteDeLaApp();

    $this->postJson('/api/cadetes/login', [
        'email' => '  CADETE@Example.TEST ',
        'password' => 'LaClaveDelCadete.1',
    ])->assertOk();
});

// ------------------------------------------------------------------------- getcadete

test('the cadete comes with the shape the app declares', function () {
    $cadete = unCadeteDeLaApp(['estado' => Cadete::ESTADO_ACTIVO_APP, 'orden_cola' => 5]);

    $datos = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/getcadete')
        ->assertOk()
        ->json('cadete');

    expect(array_keys($datos))->toBe([
        '_id', 'numero_movil', 'apellidos', 'nombres', 'direccion', 'telefono',
        'fecha_nacimiento', 'dni', 'tipo_vehiculo', 'estado', 'ubicacion_lat',
        'ubicacion_lon', 'orden_cola', 'posicion_en_cola', 'user_id', 'cobranza_saldo',
        'modalidad_cobranza', 'created_at', 'updated_at',
    ]);

    // ⚠️ Los tipos, que es donde se rompe en silencio.
    expect($datos['_id'])->toBeString()
        ->and($datos['user_id'])->toBeString()
        ->and($datos['numero_movil'])->toBeString()
        ->and($datos['cobranza_saldo'])->toBeString()
        ->and($datos['orden_cola'])->toBeInt()
        ->and($datos['posicion_en_cola'])->toBeInt()
        // La app la mete en un `<ion-input type="date">`, que exige este formato.
        ->and($datos['fecha_nacimiento'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        // ⚠️ Y las fechas como las escribe el sistema viejo: la app imprime este texto tal
        // cual en la pantalla del cadete. Ver `Momento::enApi()`.
        ->and($datos['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

test('the queue position is the real one', function () {
    $primero = unCadeteDeLaApp(['estado' => Cadete::ESTADO_ACTIVO_WEB, 'orden_cola' => 1]);
    $segundo = unCadeteDeLaApp(
        ['numero_movil' => 78, 'estado' => Cadete::ESTADO_ACTIVO_APP, 'orden_cola' => 2],
        ['email' => 'otro@example.test'],
    );

    expect($this->actingAs($segundo->user, 'sanctum')->getJson('/api/getcadete')->json('cadete.posicion_en_cola'))
        ->toBe(2);

    expect($this->actingAs($primero->user, 'sanctum')->getJson('/api/getcadete')->json('cadete.posicion_en_cola'))
        ->toBe(1);
});

test('without a token nothing answers', function () {
    unCadeteDeLaApp();

    $this->getJson('/api/getcadete')->assertUnauthorized();
    $this->getJson('/api/cambiarestado')->assertUnauthorized();
    $this->postJson('/api/actualizarubicacion', ['lat' => 1, 'lon' => 1])->assertUnauthorized();
});

test('a client account cannot use the cadete endpoints', function () {
    // ⚠️ SEC-13: las apps comparten el guard, así que el rol se exige además del token. Sin
    // esto, el token de un cliente —que se registra solo— entraba acá.
    $cliente = usuarioCon(Rol::CLIENTE_APP);

    $this->actingAs($cliente, 'sanctum')->getJson('/api/getcadete')->assertForbidden();
});

// ---------------------------------------------------------------------- cambiarestado

test('a cadete goes active and the queue opens a stretch of activity', function () {
    $cadete = unCadeteDeLaApp();

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cambiarestado')->assertOk();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP)
        ->and($cadete->fresh()->orden_cola)->not->toBeNull()
        // ⚠️ El tramo lo abre la app y no el mostrador: es lo que miden los informes de
        // horas trabajadas.
        ->and(ActividadCadete::query()->where('cadete_id', $cadete->id)->whereNull('fin')->count())
        ->toBe(1);
});

test('going inactive closes the stretch', function () {
    $cadete = unCadeteDeLaApp(['estado' => Cadete::ESTADO_ACTIVO_APP, 'orden_cola' => 1]);
    ActividadCadete::create(['cadete_id' => $cadete->id, 'inicio' => now()->subHour()]);

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cambiarestado')->assertOk();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO)
        ->and(ActividadCadete::query()->where('cadete_id', $cadete->id)->whereNull('fin')->count())
        ->toBe(0);
});

test('a blocked cadete is told why, with the words of the old system', function () {
    $motivos = [
        MotivoBloqueo::FALTA_DE_PAGO => 'Bloqueado por falta de pago.',
        MotivoBloqueo::RECLAMO_PEDIDO => 'Bloqueado por reclamo en un pedido.',
        MotivoBloqueo::OTRO_MOTIVO => 'Cadete bloqueado. Pasar pronto por administración.',
    ];

    foreach ($motivos as $codigo => $esperado) {
        $cadete = unCadeteDeLaApp(
            ['numero_movil' => random_int(100, 900)],
            ['email' => $codigo.'@example.test', 'bloqueado' => $codigo],
        );

        $this->actingAs($cadete->user, 'sanctum')
            ->getJson('/api/cambiarestado')
            ->assertStatus(400)
            ->assertJsonPath('error', $esperado);

        expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO);
    }
});

test('a blocked cadete can still go inactive', function () {
    // Salir se puede siempre: lo contrario deja a alguien trabajando sin poder parar.
    $cadete = unCadeteDeLaApp(
        ['estado' => Cadete::ESTADO_ACTIVO_APP, 'orden_cola' => 1],
        ['bloqueado' => MotivoBloqueo::OTRO_MOTIVO],
    );

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cambiarestado')->assertOk();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO);
});

test('a cadete below the minimum balance cannot go active from the app', function () {
    laTarifaMinima();

    // El mínimo con estas configuraciones: 18% de 3000 = 540, redondeado a 600.
    $cadete = unCadeteDeLaApp([
        'modalidad_cobranza' => Cadete::COBRANZA_SALDO,
        'cobranza_saldo' => '100.00',
    ]);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cambiarestado')
        ->assertStatus(400)
        ->assertJsonPath('error', 'Saldo insuficiente.<br><br>Saldo actual: $ 100.00<br>Saldo mínimo: $ 600');

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO);
});

test('the balance only stops the ones who pay by balance', function () {
    laTarifaMinima();

    $cadete = unCadeteDeLaApp([
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
        'cobranza_saldo' => '0.00',
    ]);

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cambiarestado')->assertOk();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP);
});

test('an expired fee does not stop the app, and that is the old behaviour', function () {
    // ⚠️ **Esto es lo que hay que no romper, y es una asimetría rara.** El control de
    // vencimiento del endpoint de la app está COMENTADO en el sistema viejo
    // —`Api\v1\CadeteController@cambiarEstado`, líneas 123-130— mientras que el del
    // mostrador sí lo aplica. O sea que hoy un cadete con la cuota vencida entra por la app
    // y no por el mostrador. Se replica tal cual; cambiarlo es decidir a quién se le deja
    // trabajar, y eso no lo decide un porteo.
    $cadete = unCadeteDeLaApp(['fecha_vencimiento' => today()->subMonth()]);

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cambiarestado')->assertOk();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP);
});

// ----------------------------------------------------------------- actualizarubicacion

test('the location is saved and comes back in the cadete', function () {
    $cadete = unCadeteDeLaApp(['estado' => Cadete::ESTADO_ACTIVO_APP, 'orden_cola' => 1]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/actualizarubicacion', ['lat' => -26.8241, 'lon' => -65.2226])
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('cadete.ubicacion_lat'))->toBe(-26.8241)
        ->and($cadete->fresh()->ubicacion_lat)->toBe(-26.8241)
        ->and($cadete->fresh()->ubicacion_lon)->toBe(-65.2226);
});

test('a location outside the world is refused', function () {
    // El sistema viejo valida sólo que vengan. Una coordenada imposible deja al cadete en
    // un punto del mapa que no existe, y el mapa del panel lo dibuja igual.
    $cadete = unCadeteDeLaApp();

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/actualizarubicacion', ['lat' => 999, 'lon' => -65.2])
        ->assertStatus(422);

    expect($cadete->fresh()->ubicacion_lat)->toBeNull();
});

test('the location is accepted as a form too, like the old one', function () {
    $cadete = unCadeteDeLaApp();

    $this->actingAs($cadete->user, 'sanctum')
        ->post('/api/actualizarubicacion', ['lat' => '-26.83', 'lon' => '-65.20'])
        ->assertOk();

    expect($cadete->fresh()->ubicacion_lat)->toBe(-26.83);
});

// ------------------------------------------------------------------------ la foto

test('the photo travels as a link and not as the image', function () {
    // ⚠️ En el sistema viejo este campo lleva el base64 entero adentro del usuario: 1.129 MB
    // en MongoDB, que viajaban en cada login y en cada getperfil.
    Storage::fake('local');

    $cadete = unCadeteDeLaApp();
    Storage::disk('local')->put('user-fotos/una.jpg', 'bytes de una foto');
    UserFoto::create(['user_id' => $cadete->user_id, 'ruta_archivo' => 'user-fotos/una.jpg']);

    $foto = $this->postJson('/api/cadetes/login', [
        'email' => 'cadete@example.test',
        'password' => 'LaClaveDelCadete.1',
    ])->json('user.foto');

    expect($foto)->toBeString()
        ->and($foto)->not->toContain('base64');
});

test('without a photo the field is null, and the app draws its own avatar', function () {
    $cadete = unCadeteDeLaApp();

    expect($this->actingAs($cadete->user, 'sanctum')->getJson('/api/getcadete')->status())->toBe(200);

    $foto = $this->postJson('/api/cadetes/login', [
        'email' => 'cadete@example.test',
        'password' => 'LaClaveDelCadete.1',
    ])->json('user.foto');

    // La app hace `usuario.foto || 'assets/images/avatar.png'`.
    expect($foto)->toBeNull();
});

test('a 422 comes in the shape the app reads, and not in Laravel default one', function () {
    // ⚠️ La app hace `if (err.error.code == 422) { this.respuesta_messages = err.error.error }`
    // y después recorre `respuesta_messages.<campo>`. El 422 que Laravel manda por omisión
    // es `{message, errors}`: con ése la app muestra «Hubo un error» y el cadete no se
    // entera de qué campo está mal. Son los tres endpoints con validación de este
    // consumidor, y el formato vive en un solo lugar: `PeticionDeLaApp`.
    $cadete = unCadeteDeLaApp();

    $respuestas = [
        'login' => $this->postJson('/api/cadetes/login', ['email' => 'no-es-un-email']),
        'ubicacion' => $this->actingAs($cadete->user, 'sanctum')
            ->postJson('/api/actualizarubicacion', ['lat' => 999, 'lon' => 'por ahí']),
    ];

    foreach ($respuestas as $cual => $respuesta) {
        $respuesta->assertStatus(422);

        expect($respuesta->json('code'))->toBe(422, $cual)
            ->and($respuesta->json('error'))->toBeArray()
            ->and($respuesta->json())->not->toHaveKey('errors')
            ->and($respuesta->json())->not->toHaveKey('message');
    }

    expect($respuestas['ubicacion']->json('error.lon.0'))->toBeString();
});
