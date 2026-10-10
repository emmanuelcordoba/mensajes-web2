<?php

use App\Http\Controllers\Api\ClienteApp\V1\AuthController;
use App\Http\Controllers\Api\ClienteApp\V1\VerificacionController;
use App\Mail\CodigoDeVerificacion;
use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use App\Services\RegistroDeClienteApp;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
| La puerta de la app de clientes: entrar, registrarse y verificar la casilla.
|
| ⚠️ **Esta app declara UNA interfaz en todo `interfaces.ts`**, así que el contrato está
| implícito en lo que leen las pantallas. Lo que estos tests fijan salió de leerlas: el login
| deja el token en `api_token` y la verificación en `cliente.user.api_token`, que son dos
| lugares distintos para lo mismo porque son dos pantallas publicadas.
|
| ⚠️ Y el 422 va en la forma que la app pinta debajo de cada campo: `register.page.ts` hace
| `this.respuesta_messages = error.error.error`.
*/

function unClienteConCuenta(array $delCliente = [], array $deLaCuenta = []): Cliente
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CLIENTE_APP], ['display_rol' => 'Cliente app']);

    $cuenta = User::factory()->create(array_merge([
        'rol_id' => $rol->id,
        'email' => 'cliente@example.test',
        'password' => 'LaClave.1',
        'email_verified_at' => now(),
    ], $deLaCuenta));

    return Cliente::factory()->create(array_merge([
        'user_id' => $cuenta->id,
        'nombres' => 'Juan',
        'apellidos' => 'Pérez',
        'direccion' => 'Balcarce 100',
        'telefono' => '3815551234',
        'plataforma' => Cliente::PLATAFORMA_APP,
    ], $delCliente));
}

/** Un cliente que cargó la oficina: tiene fila y no tiene cuenta. */
function unClienteDelPanel(array $extra = []): Cliente
{
    return Cliente::factory()->create(array_merge([
        'user_id' => null,
        'nombre' => 'Panadería La Esquina',
        'nombres' => null,
        'apellidos' => null,
        'direccion' => 'Muñecas 500',
        'telefono' => '3815559999',
        'plataforma' => null,
    ], $extra));
}

function elFormularioDeRegistro(array $cambios = []): array
{
    return array_merge([
        'nombres' => 'Ana',
        'apellidos' => 'Gómez',
        'email' => 'ana@example.test',
        'password' => 'LaClave.1',
        'password_confirmation' => 'LaClave.1',
        'direccion' => 'San Martín 250',
        'telefono' => '3815550000',
    ], $cambios);
}

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('login-cliente|cliente@example.test|127.0.0.1');

    // El rol es dato de referencia: en producción está migrado, y los registros lo necesitan
    // para crear la cuenta. Sin esto, los tests que registran fallan por el entorno y no por
    // lo que miden.
    Rol::query()->firstOrCreate(['rol' => Rol::CLIENTE_APP], ['display_rol' => 'Cliente app']);
});

// ------------------------------------------------------------------- entrar

test('a client logs in and gets the token where the app looks for it', function () {
    $cliente = unClienteConCuenta();

    $respuesta = $this->postJson('/api/clientesapp/login', [
        'email' => 'cliente@example.test',
        'password' => 'LaClave.1',
    ])->assertOk();

    // ⚠️ `login.page.ts:33` lee `res['api_token']`, arriba. En verificar lo busca adentro
    // del cliente. Los dos lugares están publicados.
    expect($respuesta->json('api_token'))->toBeString()->not->toBeEmpty()
        ->and($respuesta->json('cliente._id'))->toBe((string) $cliente->id)
        ->and($respuesta->json('cliente.user.email'))->toBe('cliente@example.test')
        ->and($respuesta->json('cliente'))->not->toHaveKey('api_token');
});

test('the client comes with the fields the screens read, and no more', function () {
    $cliente = unClienteConCuenta();

    $respuesta = $this->postJson('/api/clientesapp/login', [
        'email' => 'cliente@example.test',
        'password' => 'LaClave.1',
    ])->assertOk();

    expect($respuesta->json('cliente'))
        ->toHaveKeys(['_id', 'numero', 'nombres', 'apellidos', 'direccion', 'telefono', 'user'])
        // ⚠️ El sistema viejo manda el modelo entero. El token de notificaciones del propio
        // teléfono no tiene nada que hacer de vuelta en el teléfono.
        ->and($respuesta->json('cliente'))->not->toHaveKey('fcm_token')
        ->and($respuesta->json('cliente'))->not->toHaveKey('nombre_empleado')
        ->and($respuesta->json('cliente.user'))->not->toHaveKey('rol_id')
        ->and($respuesta->json('cliente.user'))->not->toHaveKey('codigo_de_verificacion');
});

test('the same words for a wrong password, a missing email and the wrong role', function () {
    unClienteConCuenta();

    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    User::factory()->create(['rol_id' => $rol->id, 'email' => 'cadete@example.test', 'password' => 'LaClave.1']);

    $casos = [
        ['cliente@example.test', 'otra'],
        ['nadie@example.test', 'LaClave.1'],
        // Un cadete no entra por la puerta de los clientes.
        ['cadete@example.test', 'LaClave.1'],
    ];

    foreach ($casos as [$email, $clave]) {
        $this->postJson('/api/clientesapp/login', ['email' => $email, 'password' => $clave])
            ->assertStatus(400)
            ->assertJsonPath('error', 'El email y/o contraseña son incorrectos.');
    }
});

test('an unverified mailbox is a 424 and a fresh code, not a failed login', function () {
    $cliente = unClienteConCuenta([], ['email_verified_at' => null]);

    $this->postJson('/api/clientesapp/login', [
        'email' => 'cliente@example.test',
        'password' => 'LaClave.1',
    ])->assertStatus(424);

    // El sistema viejo no manda código acá, y deja a quien perdió el mail sin forma de pedir
    // otro hasta llegar a la pantalla que justamente no funciona.
    Mail::assertQueued(CodigoDeVerificacion::class);

    expect($cliente->user->fresh()->codigo_de_verificacion)->not->toBeNull();
});

test('the 424 does not carry the account, because it cannot reach the app anyway', function () {
    // ⚠️ `login.page.ts:42` hace `setUser(error.user)`, pero `error` es el
    // `HttpErrorResponse` de Angular y no tiene propiedad `user`: el cuerpo vive en
    // `error.error`. Ese modelo entero no llega con NINGUNA forma que le demos al cuerpo, y
    // la pantalla siguiente hace `this.user._id` sobre un `undefined`. El camino está roto
    // en la app publicada y se arregla del lado de la app.
    unClienteConCuenta([], ['email_verified_at' => null]);

    $respuesta = $this->postJson('/api/clientesapp/login', [
        'email' => 'cliente@example.test',
        'password' => 'LaClave.1',
    ])->assertStatus(424);

    expect($respuesta->json())->not->toHaveKey('user')
        ->and($respuesta->json('error'))->toBe('El email no está verificado.');
});

test('an unverified mailbox does not burn login attempts', function () {
    // La contraseña ya fue correcta: el 424 es un desvío, no un fallo de credenciales.
    unClienteConCuenta([], ['email_verified_at' => null]);

    foreach (range(1, AuthController::MAXIMO_DE_INTENTOS + 2) as $ignorado) {
        $this->postJson('/api/clientesapp/login', [
            'email' => 'cliente@example.test',
            'password' => 'LaClave.1',
        ])->assertStatus(424);
    }
});

test('too many wrong passwords close the door', function () {
    unClienteConCuenta();

    foreach (range(1, AuthController::MAXIMO_DE_INTENTOS) as $ignorado) {
        $this->postJson('/api/clientesapp/login', [
            'email' => 'cliente@example.test',
            'password' => 'otra',
        ])->assertStatus(400);
    }

    $this->postJson('/api/clientesapp/login', [
        'email' => 'cliente@example.test',
        'password' => 'LaClave.1',
    ])->assertStatus(429)->assertHeader('Retry-After');
});

// --------------------------------------------------------------- registrarse

test('somebody new registers and gets a client with its own number', function () {
    $this->postJson('/api/clientesapp/register', elFormularioDeRegistro())
        ->assertOk()
        ->assertJsonPath('ok', true);

    $cliente = Cliente::query()->sole();

    expect($cliente->nombres)->toBe('Ana')
        ->and($cliente->apellidos)->toBe('Gómez')
        ->and($cliente->plataforma)->toBe(Cliente::PLATAFORMA_APP)
        // ⚠️ El número sale de `clientes_numero_seq` por DEFAULT: no se calcula en PHP. El
        // sistema viejo lo saca mirando toda la colección (PERF-1), que en un endpoint
        // público es además una carrera.
        ->and($cliente->numero)->toBeInt()->toBeGreaterThan(0)
        ->and($cliente->user)->not->toBeNull()
        ->and($cliente->user->email)->toBe('ana@example.test');

    Mail::assertQueued(CodigoDeVerificacion::class);
});

test('the response carries the account id, which is what the app needs next', function () {
    // `register.page.ts:59` hace `setUser(res['user'])` y después llama a
    // `verificar/{user}` con `this.user._id`.
    $respuesta = $this->postJson('/api/clientesapp/register', elFormularioDeRegistro())->assertOk();

    expect($respuesta->json('user._id'))->toBeString()
        ->and($respuesta->json('user._id'))->toBe((string) User::query()->sole()->id)
        ->and($respuesta->json('user'))->not->toHaveKey('password');
});

test('two registrations in a row get different numbers', function () {
    $this->postJson('/api/clientesapp/register', elFormularioDeRegistro())->assertOk();
    $this->postJson('/api/clientesapp/register', elFormularioDeRegistro([
        'email' => 'otra@example.test',
    ]))->assertOk();

    expect(Cliente::query()->pluck('numero')->unique())->toHaveCount(2);
});

test('the registration refuses what the old one let through', function () {
    // ⚠️ El registro viejo no le pone mínimo a la contraseña: aceptaba una sola letra. El
    // otro registro sí pedía `min:6`, así que eran dos reglas para lo mismo.
    $corta = $this->postJson('/api/clientesapp/register', elFormularioDeRegistro([
        'password' => 'a',
        'password_confirmation' => 'a',
    ]))->assertStatus(422);

    expect($corta->json('code'))->toBe(422)
        ->and($corta->json('error.password.0'))->toContain('al menos 6');

    expect(Cliente::query()->count())->toBe(0);
});

test('a longer address is accepted, because thirty characters was not enough', function () {
    // «Avenida Nicolás Avellaneda 607» son 30 justos, y la columna aguanta 255.
    $this->postJson('/api/clientesapp/register', elFormularioDeRegistro([
        'direccion' => 'Avenida Nicolás Avellaneda 607, Piso 3, Departamento B',
    ]))->assertOk();

    expect(Cliente::query()->sole()->direccion)->toBe('Avenida Nicolás Avellaneda 607, Piso 3, Departamento B');
});

test('an email already in use is refused, no matter the case', function () {
    unClienteConCuenta();

    foreach (['cliente@example.test', 'Cliente@Example.Test'] as $email) {
        $respuesta = $this->postJson('/api/clientesapp/register', elFormularioDeRegistro(['email' => $email]))
            ->assertStatus(422);

        expect($respuesta->json('error.email.0'))->toBe('Ese email ya está en uso.');
    }
});

// -------------------------------------- el registro de un cliente del panel

test('the first step finds the client and hands back only the id and the phone', function () {
    $cliente = unClienteDelPanel();

    $respuesta = $this->getJson('/api/clientesapp/registerCliente/getCliente?numero='.$cliente->numero)
        ->assertOk();

    // ⚠️ Es SEC-15: la app compara el teléfono EN EL TELÉFONO, así que el servidor se lo
    // tiene que dar, y los números son correlativos. Lo que sí está recortado es el resto:
    // no sale el nombre ni la dirección.
    expect($respuesta->json('cliente'))->toBe([
        '_id' => (string) $cliente->id,
        'telefono' => '3815559999',
    ]);
});

test('a number nobody has is a 422 on the field the screen shows', function () {
    $respuesta = $this->getJson('/api/clientesapp/registerCliente/getCliente?numero=999999')
        ->assertStatus(422);

    expect($respuesta->json('code'))->toBe(422)
        ->and($respuesta->json('error.numero.0'))->toBe('El número de cliente ingresado no es válido.');
});

test('a number that is not a number does not reach the database', function () {
    // ⚠️ El sistema viejo valida `required` a secas: `?numero=hola` llegaba a
    // `where('numero', 'hola')`, que en PostgreSQL es un error de tipo.
    $this->getJson('/api/clientesapp/registerCliente/getCliente?numero=hola')->assertStatus(422);
});

test('a client already registered cannot be taken over, and that is SEC-11', function () {
    // ⚠️ Allá esta regla sólo la miraba el paso que LEE; el que escribe aceptaba cualquier
    // id y le colgaba un usuario nuevo a un cliente ya registrado, que así perdía su cuenta
    // y todos sus pedidos.
    $cliente = unClienteConCuenta();

    $primerPaso = $this->getJson('/api/clientesapp/registerCliente/getCliente?numero='.$cliente->numero)
        ->assertStatus(422);

    expect($primerPaso->json('error.numero.0'))->toBe('El cliente ingresado ya está registrado.');

    $segundoPaso = $this->postJson('/api/clientesapp/registerCliente/'.$cliente->id, elFormularioDeRegistro())
        ->assertStatus(422);

    expect($segundoPaso->json('error.numero.0'))->toBe('El cliente ingresado ya está registrado.')
        // La cuenta que ya tenía sigue siendo la suya.
        ->and($cliente->fresh()->user_id)->toBe($cliente->user_id)
        ->and(User::query()->count())->toBe(1);
});

test('a client with no usable phone cannot register, since the office cannot call back', function () {
    $cliente = unClienteDelPanel(['telefono' => '123']);

    $respuesta = $this->getJson('/api/clientesapp/registerCliente/getCliente?numero='.$cliente->numero)
        ->assertStatus(422);

    expect($respuesta->json('error.numero.0'))
        ->toBe('El cliente ingresado no puede registrarse. Por favor comuníquese por teléfono.');
});

test('both steps ask the same rule', function () {
    // Una regla, dos lectores. Si se separan, vuelve SEC-11.
    $registrado = unClienteConCuenta();
    $sinTelefono = unClienteDelPanel(['telefono' => '12']);
    $puede = unClienteDelPanel(['telefono' => '3815551111']);

    expect(RegistroDeClienteApp::porQueNoSePuedeRegistrar($registrado))->toBeString()
        ->and(RegistroDeClienteApp::porQueNoSePuedeRegistrar($sinTelefono))->toBeString()
        ->and(RegistroDeClienteApp::porQueNoSePuedeRegistrar($puede))->toBeNull();
});

test('the second step gives him an account and keeps the name the office wrote', function () {
    $cliente = unClienteDelPanel();

    $this->postJson('/api/clientesapp/registerCliente/'.$cliente->id, elFormularioDeRegistro())
        ->assertOk()
        ->assertJsonPath('ok', true);

    $cliente->refresh();

    expect($cliente->user)->not->toBeNull()
        ->and($cliente->user->email)->toBe('ana@example.test')
        ->and($cliente->nombres)->toBe('Ana')
        ->and($cliente->apellidos)->toBe('Gómez')
        // ⚠️ Hasta DATA-8 el registro reemplazaba el nombre del panel por el de la persona,
        // y si era un comercio se perdía.
        ->and($cliente->nombre)->toBe('Panadería La Esquina')
        ->and($cliente->plataforma)->toBe(Cliente::PLATAFORMA_APP);

    Mail::assertQueued(CodigoDeVerificacion::class);
});

test('the second step does not let him rewrite the phone the office has', function () {
    $cliente = unClienteDelPanel();

    $this->postJson('/api/clientesapp/registerCliente/'.$cliente->id, elFormularioDeRegistro([
        'telefono' => '3810000000',
    ]))->assertOk();

    expect($cliente->fresh()->telefono)->toBe('3815559999');
});

// ------------------------------------------------------------ verificar

test('the code verifies the mailbox and hands back the token where the app reads it', function () {
    $cliente = unClienteConCuenta([], ['email_verified_at' => null, 'codigo_de_verificacion' => 123456]);
    $cuenta = $cliente->user;

    $respuesta = $this->postJson('/api/clientesapp/verificar/'.$cuenta->id, ['codigo' => '123456'])
        ->assertOk();

    // ⚠️ `verify.page.ts:62` lee `res['cliente'].user.api_token`, adentro. En el login está
    // arriba. Dos pantallas publicadas, dos lugares.
    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('cliente.user.api_token'))->toBeString()->not->toBeEmpty()
        ->and($respuesta->json('cliente._id'))->toBe((string) $cliente->id);

    $cuenta->refresh();

    expect($cuenta->email_verified_at)->not->toBeNull()
        ->and($cuenta->codigo_de_verificacion)->toBeNull();
});

test('verifying saves the phone token when the app already has one', function () {
    $cliente = unClienteConCuenta([], ['email_verified_at' => null, 'codigo_de_verificacion' => 123456]);

    $this->postJson('/api/clientesapp/verificar/'.$cliente->user_id, [
        'codigo' => '123456',
        'fcm_token' => 'el-token-de-este-telefono',
    ])->assertOk();

    expect($cliente->fresh()->fcm_token)->toBe('el-token-de-este-telefono');
});

test('a wrong code is a 422 on the field, and zero does not pass', function () {
    $cliente = unClienteConCuenta([], ['email_verified_at' => null, 'codigo_de_verificacion' => null]);

    // ⚠️ SEC-3: la comparación era `==`, y con el código en null el número 0 daba verdadero.
    // Acertar acá no sólo verifica el mail: emite un token.
    $respuesta = $this->postJson('/api/clientesapp/verificar/'.$cliente->user_id, ['codigo' => 0])
        ->assertStatus(422);

    expect($respuesta->json('code'))->toBe(422)
        ->and($respuesta->json('error.codigo.0'))->toBe('El código ingresado es inválido.')
        ->and($cliente->user->fresh()->email_verified_at)->toBeNull();
});

test('five wrong codes close the door for that account', function () {
    $cliente = unClienteConCuenta([], ['email_verified_at' => null, 'codigo_de_verificacion' => 123456]);

    RateLimiter::clear('verificar-email|'.$cliente->user_id);

    foreach (range(1, VerificacionController::MAXIMO_DE_INTENTOS) as $ignorado) {
        $this->postJson('/api/clientesapp/verificar/'.$cliente->user_id, ['codigo' => '000000'])
            ->assertStatus(422);
    }

    $this->postJson('/api/clientesapp/verificar/'.$cliente->user_id, ['codigo' => '123456'])
        ->assertStatus(429)->assertHeader('Retry-After');
});

// -------------------------------------------------------------- reenviar

test('asking for another code does not hand the code back', function () {
    // ⚠️ El sistema viejo lo devuelve —`makeVisible('codigo_de_verificacion')`— y la app NO
    // lo lee: `reenviarCodigo()` sólo reinicia su contador. Con el id de cuenta siendo un
    // entero correlativo y sin tope de intentos, devolverlo era regalar cualquier cuenta
    // pendiente.
    $cliente = unClienteConCuenta([], ['email_verified_at' => null, 'codigo_de_verificacion' => 111111]);

    RateLimiter::clear('reenviar-codigo|'.$cliente->user_id);

    $respuesta = $this->getJson('/api/clientesapp/verificar/'.$cliente->user_id.'/reenviar')->assertOk();

    expect($respuesta->json())->toBe(['ok' => true]);

    // El código se regeneró y se mandó por mail.
    expect((string) $cliente->user->fresh()->codigo_de_verificacion)->not->toBe('111111');
    Mail::assertQueued(CodigoDeVerificacion::class);
});

test('the resend has a cap, which the old one does not', function () {
    $cliente = unClienteConCuenta([], ['email_verified_at' => null]);

    RateLimiter::clear('reenviar-codigo|'.$cliente->user_id);

    foreach (range(1, VerificacionController::MAXIMO_DE_REENVIOS) as $ignorado) {
        $this->getJson('/api/clientesapp/verificar/'.$cliente->user_id.'/reenviar')->assertOk();
    }

    // Sin tope sirve para llenarle la casilla a cualquiera, y además invalida el código que
    // la persona estaba por tipear.
    $this->getJson('/api/clientesapp/verificar/'.$cliente->user_id.'/reenviar')
        ->assertStatus(429)->assertHeader('Retry-After');
});

test('an account that is not a client gets the same answer as one that does not exist', function () {
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::ADMIN], ['display_rol' => 'Admin']);
    $jefe = User::factory()->create(['rol_id' => $rol->id]);

    RateLimiter::clear('reenviar-codigo|'.$jefe->id);

    $this->getJson('/api/clientesapp/verificar/'.$jefe->id.'/reenviar')->assertStatus(400);

    Mail::assertNothingQueued();
});

// ---------------------------------------------------------------- salir

test('logging out stops the notifications to this phone', function () {
    $cliente = unClienteConCuenta(['fcm_token' => 'el-token']);

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/logout')
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect($cliente->fresh()->fcm_token)->toBeNull();
});

test('logging out needs a token and the client role', function () {
    $this->postJson('/api/clientesapp/logout')->assertUnauthorized();

    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $cadete = User::factory()->create(['rol_id' => $rol->id]);

    // ⚠️ El mismo guard lo usan las tres apps. Ver SEC-13.
    $this->actingAs($cadete, 'sanctum')->postJson('/api/clientesapp/logout')->assertForbidden();
});
