<?php

use App\Models\Cliente;
use App\Models\PublicidadApp;
use App\Models\PublicidadAppImagen;
use App\Models\Rol;
use App\Models\User;
use App\Services\ImagenesDeLaApp;
use Illuminate\Support\Facades\Storage;

/*
| El inicio y el perfil de un cliente de la app.
|
| ⚠️ Esta app declara UNA interfaz en todo `interfaces.ts`, así que lo que estos tests fijan
| salió de leer las pantallas y de buscar en la historia del repositorio qué se leyó alguna
| vez. Donde la respuesta se recortó, el test dice por qué.
*/

function unClienteDeLaApp(array $delCliente = [], array $deLaCuenta = []): Cliente
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CLIENTE_APP], ['display_rol' => 'Cliente app']);

    $cuenta = User::factory()->create(array_merge([
        'rol_id' => $rol->id,
        'email' => 'cliente-app@example.test',
        'email_verified_at' => now(),
    ], $deLaCuenta));

    return Cliente::factory()->create(array_merge([
        'user_id' => $cuenta->id,
        'nombre' => 'Panadería La Esquina',
        'nombres' => 'Juan',
        'apellidos' => 'Pérez',
        'direccion' => 'Balcarce 100',
        'telefono' => '3815551234',
        'plataforma' => Cliente::PLATAFORMA_APP,
    ], $delCliente));
}

function unaPublicidad(bool $visible, string $archivo): PublicidadApp
{
    $publicidad = PublicidadApp::query()->create([
        'url' => 'https://www.facebook.com/alguien',
        'visible' => $visible,
    ]);

    $ruta = ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/'.$archivo;

    Storage::disk(ImagenesDeLaApp::disco())->put($ruta, 'bytes de una imagen');

    PublicidadAppImagen::query()->create([
        'publicidad_id' => $publicidad->id,
        'ruta_archivo' => $ruta,
    ]);

    return $publicidad;
}

beforeEach(function () {
    Storage::fake(ImagenesDeLaApp::disco());
});

// ---------------------------------------------------------- el token del teléfono

test('the app stores the token Firebase gave this phone', function () {
    $cliente = unClienteDeLaApp(['fcm_token' => null]);

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/guardarfcmtoken', ['fcm_token' => 'el-token-de-este-telefono'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect($cliente->fresh()->fcm_token)->toBe('el-token-de-este-telefono');
});

test('a request without the token does not wipe the one he had', function () {
    // ⚠️ El sistema viejo hace `$cliente->fcm_token = $request->fcm_token` sin validar: una
    // petición sin el campo le borra el token y el cliente deja de recibir el aviso de que
    // su pedido salió o llegó, sin que nadie se entere.
    $cliente = unClienteDeLaApp(['fcm_token' => 'el-que-funciona']);

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/guardarfcmtoken', [])
        ->assertStatus(422);

    expect($respuesta->json('code'))->toBe(422)
        ->and($cliente->fresh()->fcm_token)->toBe('el-que-funciona');
});

// ----------------------------------------------------------------- el inicio

test('the home screen brings the visible ads, which is all the app reads', function () {
    $cliente = unClienteDeLaApp();

    $visible = unaPublicidad(true, 'visible.jpg');
    unaPublicidad(false, 'oculta.jpg');

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getinicio')
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('publicidades'))->toHaveCount(1)
        ->and($respuesta->json('publicidades.0._id'))->toBe((string) $visible->id);
});

test('the image travels as a link and not as base64', function () {
    // ⚠️ Medido sobre las 4 publicidades visibles: 692 KB en disco, ~948 KB como data URI
    // —una sola pesa 537 KB—, y eso viajaba en CADA carga de la pantalla que el cliente
    // abre primero. El nombre del campo miente y se queda: la plantilla hace
    // `<img [src]="slide.img_base64">` y ese literal está en los teléfonos.
    $cliente = unClienteDeLaApp();
    unaPublicidad(true, 'una.jpg');

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getinicio')
        ->assertOk();

    $imagen = $respuesta->json('publicidades.0.img_base64');

    expect($imagen)->toBeString()
        ->and($imagen)->not->toStartWith('data:')
        ->and(strlen($imagen))->toBeLessThan(2_000);
});

test('an ad whose file is missing comes as null instead of a broken link', function () {
    $cliente = unClienteDeLaApp();

    $publicidad = PublicidadApp::query()->create([
        'url' => 'https://example.test',
        'visible' => true,
    ]);

    PublicidadAppImagen::query()->create([
        'publicidad_id' => $publicidad->id,
        'ruta_archivo' => ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/no-esta.jpg',
    ]);

    $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getinicio')
        ->assertOk()
        ->assertJsonPath('publicidades.0.img_base64', null);
});

test('the home screen does not carry the account nor the schedule', function () {
    // ⚠️ El sistema viejo manda `usuario` —la cuenta ENTERA con su cliente anidado— y
    // `horariodeatencion`. Buscados en toda la historia del repositorio de la app:
    // `res['usuario']` no aparece nunca y `horariodeatencion` tampoco. El único consumidor
    // es `inicio.page.ts`, que desde su primera versión hace
    // `this.slides = res['publicidades']` y nada más.
    $cliente = unClienteDeLaApp();
    unaPublicidad(true, 'una.jpg');

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getinicio')
        ->assertOk();

    expect($respuesta->json())->toHaveKeys(['ok', 'publicidades'])
        ->and($respuesta->json())->not->toHaveKey('usuario')
        ->and($respuesta->json())->not->toHaveKey('horariodeatencion');
});

test('the ad does not carry its destination link either', function () {
    // La plantilla sólo dibuja la imagen, no la hace tocable, y `url` nunca se leyó.
    $cliente = unClienteDeLaApp();
    unaPublicidad(true, 'una.jpg');

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getinicio')
        ->assertOk();

    expect($respuesta->json('publicidades.0'))->toHaveKeys(['_id', 'img_base64'])
        ->and($respuesta->json('publicidades.0'))->toHaveCount(2);
});

test('a home screen with no ads is an empty list and not a hole', function () {
    $cliente = unClienteDeLaApp();

    $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getinicio')
        ->assertOk()
        ->assertJsonPath('publicidades', []);
});

// ----------------------------------------------------------------- el perfil

test('the profile comes with the fields the screen reads', function () {
    $cliente = unClienteDeLaApp();

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getperfil')
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('cliente._id'))->toBe((string) $cliente->id)
        ->and($respuesta->json('cliente.numero'))->toBe($cliente->numero)
        ->and($respuesta->json('cliente.nombres'))->toBe('Juan')
        ->and($respuesta->json('cliente.apellidos'))->toBe('Pérez')
        ->and($respuesta->json('cliente.direccion'))->toBe('Balcarce 100')
        ->and($respuesta->json('cliente.telefono'))->toBe('3815551234')
        // `perfil.page.html:27` y `editar.page.ts:55` leen esto.
        ->and($respuesta->json('cliente.user.email'))->toBe('cliente-app@example.test');
});

test('the profile does not leak the phone token nor the account internals', function () {
    $cliente = unClienteDeLaApp(['fcm_token' => 'el-token']);

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/clientesapp/getperfil')
        ->assertOk();

    expect($respuesta->json('cliente'))->not->toHaveKey('fcm_token')
        ->and($respuesta->json('cliente'))->not->toHaveKey('nombre_empleado')
        ->and($respuesta->json('cliente.user'))->not->toHaveKey('rol_id')
        ->and($respuesta->json('cliente.user'))->not->toHaveKey('codigo_de_verificacion');
});

// ------------------------------------------------------------ editar el perfil

test('the client saves his profile and the account name is rebuilt', function () {
    $cliente = unClienteDeLaApp();

    $respuesta = $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/editarperfil', [
            'nombres' => 'Juan Carlos',
            'apellidos' => 'Pérez',
            'direccion' => 'Muñecas 500',
            'telefono' => '3819998888',
            'email' => 'nuevo@example.test',
        ])
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('cliente.nombres'))->toBe('Juan Carlos')
        ->and($respuesta->json('cliente.user.email'))->toBe('nuevo@example.test');

    $cliente->refresh();

    expect($cliente->direccion)->toBe('Muñecas 500')
        ->and($cliente->telefono)->toBe('3819998888')
        // ⚠️ El sistema viejo no tocaba esto, y quedó distinto en 95 clientes. Ver DATA-8.
        ->and($cliente->user->name)->toBe($cliente->nombreDePersona());
});

test('the name the office wrote survives the edit', function () {
    // `nombre` es del panel y `nombres`/`apellidos` son de la app. Si el cliente es un
    // comercio, su nombre comercial no es asunto de esta pantalla.
    $cliente = unClienteDeLaApp();

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/editarperfil', [
            'nombres' => 'Ana',
            'apellidos' => 'Gómez',
            'direccion' => 'Balcarce 100',
            'telefono' => '3815551234',
            'email' => 'cliente-app@example.test',
        ])
        ->assertOk();

    expect($cliente->fresh()->nombre)->toBe('Panadería La Esquina');
});

test('his own email is not a duplicate of himself', function () {
    $cliente = unClienteDeLaApp();

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/editarperfil', [
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'direccion' => 'Balcarce 100',
            'telefono' => '3815551234',
            'email' => 'cliente-app@example.test',
        ])
        ->assertOk();
});

test('an email somebody else has is refused, no matter the case', function () {
    $cliente = unClienteDeLaApp();
    unClienteDeLaApp(['telefono' => '3810000000'], ['email' => 'ocupado@example.test']);

    foreach (['ocupado@example.test', 'Ocupado@Example.Test'] as $email) {
        $respuesta = $this->actingAs($cliente->user, 'sanctum')
            ->postJson('/api/clientesapp/editarperfil', [
                'nombres' => 'Juan',
                'apellidos' => 'Pérez',
                'direccion' => 'Balcarce 100',
                'telefono' => '3815551234',
                'email' => $email,
            ])
            ->assertStatus(422);

        expect($respuesta->json('error.email.0'))->toBe('Ese email ya está en uso.');
    }

    expect($cliente->user->fresh()->email)->toBe('cliente-app@example.test');
});

test('a longer address is accepted, because thirty characters was not enough', function () {
    $cliente = unClienteDeLaApp();

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/editarperfil', [
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'direccion' => 'Avenida Nicolás Avellaneda 607, Piso 3, Departamento B',
            'telefono' => '3815551234',
            'email' => 'cliente-app@example.test',
        ])
        ->assertOk();

    expect($cliente->fresh()->direccion)->toBe('Avenida Nicolás Avellaneda 607, Piso 3, Departamento B');
});

test('what the app does not send stays untouched', function () {
    $cliente = unClienteDeLaApp(['fcm_token' => 'el-token']);
    $numero = $cliente->numero;

    $administrador = Rol::query()->firstOrCreate(['rol' => Rol::ADMIN], ['display_rol' => 'Admin']);

    $this->actingAs($cliente->user, 'sanctum')
        ->postJson('/api/clientesapp/editarperfil', [
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'direccion' => 'Balcarce 100',
            'telefono' => '3815551234',
            'email' => 'cliente-app@example.test',
            'numero' => 1,
            'fcm_token' => null,
            'plataforma' => null,
            'rol_id' => $administrador->id,
        ])
        ->assertOk();

    $cliente->refresh();

    expect($cliente->numero)->toBe($numero)
        ->and($cliente->fcm_token)->toBe('el-token')
        ->and($cliente->plataforma)->toBe(Cliente::PLATAFORMA_APP)
        ->and($cliente->user->rol_id)->not->toBe($administrador->id);
});

// ----------------------------------------------------------------- la puerta

test('all four need a token and the client role', function () {
    $this->postJson('/api/clientesapp/guardarfcmtoken', ['fcm_token' => 'x'])->assertUnauthorized();
    $this->getJson('/api/clientesapp/getinicio')->assertUnauthorized();
    $this->getJson('/api/clientesapp/getperfil')->assertUnauthorized();
    $this->postJson('/api/clientesapp/editarperfil', [])->assertUnauthorized();

    // ⚠️ El mismo guard lo usan las tres apps. Ver SEC-13.
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $cadete = User::factory()->create(['rol_id' => $rol->id]);

    $this->actingAs($cadete, 'sanctum')->getJson('/api/clientesapp/getinicio')->assertForbidden();
    $this->actingAs($cadete, 'sanctum')->getJson('/api/clientesapp/getperfil')->assertForbidden();
});

test('the dead v2 setup endpoint is not published', function () {
    // ⚠️ Su único consumidor dejó de llamarlo el 2024-06-30 —commit `1333550` de la app—
    // cuando el precio pasó a calcularlo el servidor. HEAD de la app es de 2026-08-06.
    $cliente = unClienteDeLaApp();

    $this->actingAs($cliente->user, 'sanctum')
        ->getJson('/api/v2/clientesapp/get-setup')
        ->assertNotFound();
});
