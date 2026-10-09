<?php

use App\Http\Controllers\Api\CadeteApp\V1\RecuperarContrasenaController;
use App\Mail\CodigoDeRecuperacion;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
| Recuperar la contraseña desde una de las dos apps, con un código al mail.
|
| ⚠️ **ACÁ ESTÁ EL PEOR AGUJERO DEL SISTEMA VIEJO Y SE PORTA, a propósito**: `sendcode`
| devuelve el código en la respuesta HTTP y la app lo verifica en el teléfono. Quien sepa el
| email de un cadete puede pedir el código, leerlo y cambiarle la contraseña. Es SEC-2, y la
| app publicada no funciona sin eso: sin el código hace
| `response['user'].codigo_de_verificacion.toString()` sobre un `undefined`.
|
| Lo que estos tests fijan es las dos cosas: que hoy sale —porque si deja de salir, 220
| cadetes se quedan sin recuperar la contraseña— y que el interruptor lo apaga, que es lo que
| hay que hacer el día que las apps se republiquen.
*/

function unaCuentaDeApp(string $rol, string $email, array $extra = []): User
{
    $fila = Rol::query()->firstOrCreate(['rol' => $rol], ['display_rol' => $rol]);

    return User::factory()->create(array_merge([
        'rol_id' => $fila->id,
        'email' => $email,
        'name' => 'Juan Pérez',
        'password' => 'LaDeAntes.1',
        'codigo_de_verificacion' => null,
    ], $extra));
}

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('password-reset|cadete@example.test');
});

// ------------------------------------------------------------- pedir el código

test('asking for a code mails it and writes it on the account', function () {
    $cuenta = unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $respuesta = $this->postJson('/api/password/sendcode', ['email' => 'cadete@example.test'])
        ->assertOk();

    $codigo = $respuesta->json('user.codigo_de_verificacion');

    expect($codigo)->toBeString()
        ->and($codigo)->toMatch('/^\d{6}$/')
        ->and((string) $cuenta->fresh()->codigo_de_verificacion)->toBe($codigo);

    Mail::assertQueued(CodigoDeRecuperacion::class, fn (CodigoDeRecuperacion $mail): bool => $mail->hasTo('cadete@example.test')
        && $mail->codigo === $codigo);
});

test('the response carries only the code, not the whole account', function () {
    unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $respuesta = $this->postJson('/api/password/sendcode', ['email' => 'cadete@example.test'])
        ->assertOk();

    // ⚠️ El sistema viejo manda el modelo entero: `rol_id`, `bloqueado`, `deleted_at`, el
    // nombre de la persona. La app lee este campo y nada más.
    expect($respuesta->json('user'))->toBe(['codigo_de_verificacion' => $respuesta->json('user.codigo_de_verificacion')]);
});

test('the switch stops the code from travelling, which is stage 2 of SEC-2', function () {
    config(['auth.recuperacion.codigo_en_la_respuesta' => false]);

    $cuenta = unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $this->postJson('/api/password/sendcode', ['email' => 'cadete@example.test'])
        ->assertOk()
        ->assertJsonPath('user', null);

    // El código se escribió y se mandó por mail igual: lo único que cambia es que no sale
    // en la respuesta.
    expect($cuenta->fresh()->codigo_de_verificacion)->not->toBeNull();
    Mail::assertQueued(CodigoDeRecuperacion::class);
});

test('an email nobody has looks the same as one from the panel', function () {
    unaCuentaDeApp(Rol::ADMIN, 'jefe@example.test');

    foreach (['nadie@example.test', 'jefe@example.test'] as $email) {
        $this->postJson('/api/password/sendcode', ['email' => $email])
            ->assertOk()
            ->assertJsonPath('user', null);
    }

    // ⚠️ Una cuenta del panel no es recuperable por acá: con el código viajando al lado,
    // dejarla entrar era regalarla. Tiene su propia recuperación en la web, con tokens que
    // vencen. Ver SEC-2.
    Mail::assertNothingQueued();
});

test('two accounts for one mailbox cannot exist here, so there is no plural to handle', function () {
    // ⚠️ El sistema viejo recorre TODAS las cuentas con ese email porque en MongoDB conviven
    // pares que sólo difieren en mayúsculas (DATA-7). Acá el esquema lo impide: `users.email`
    // es UNIQUE, tiene `CHECK (email = lower(btrim(email)))` y el modelo lo normaliza en su
    // mutador. Por eso el servicio trabaja con una cuenta y no con una colección.
    unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    expect(fn () => unaCuentaDeApp(Rol::CLIENTE_APP, 'CADETE@example.test'))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the mailbox is found no matter how it was typed', function () {
    // Lo que la persona escribe en el teléfono no respeta mayúsculas; la columna sí.
    $cuenta = unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $respuesta = $this->postJson('/api/password/sendcode', ['email' => 'Cadete@Example.Test'])
        ->assertOk();

    expect((string) $cuenta->fresh()->codigo_de_verificacion)
        ->toBe($respuesta->json('user.codigo_de_verificacion'));
});

test('a client of the other app can recover too, since both share these routes', function () {
    unaCuentaDeApp(Rol::CLIENTE_APP, 'cliente@example.test');

    $this->postJson('/api/password/sendcode', ['email' => 'cliente@example.test'])
        ->assertOk()
        ->assertJsonPath('user.codigo_de_verificacion', fn (mixed $codigo): bool => is_string($codigo));
});

// ------------------------------------------------------------ cambiar la contraseña

test('the code changes the password, burns itself and verifies the email', function () {
    $cuenta = unaCuentaDeApp(Rol::CADETE, 'cadete@example.test', ['email_verified_at' => null]);

    $codigo = $this->postJson('/api/password/sendcode', ['email' => 'cadete@example.test'])
        ->json('user.codigo_de_verificacion');

    $this->postJson('/api/password/reset', [
        'email' => 'cadete@example.test',
        'code' => $codigo,
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ])->assertOk()->assertJsonPath('mensaje', 'Contraseña restaurada con éxito.');

    $cuenta->refresh();

    expect(Hash::check('LaNueva.1', $cuenta->password))->toBeTrue()
        ->and($cuenta->codigo_de_verificacion)->toBeNull()
        // ⚠️ Llegar acá prueba que la persona leyó un mail en esa casilla, y es la única
        // verificación de email que las apps tienen.
        ->and($cuenta->email_verified_at)->not->toBeNull();
});

test('the same code does not work twice', function () {
    unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $codigo = $this->postJson('/api/password/sendcode', ['email' => 'cadete@example.test'])
        ->json('user.codigo_de_verificacion');

    $cuerpo = [
        'email' => 'cadete@example.test',
        'code' => $codigo,
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ];

    $this->postJson('/api/password/reset', $cuerpo)->assertOk();
    $this->postJson('/api/password/reset', $cuerpo)->assertStatus(400);
});

test('zero does not pass for an account with no pending code', function () {
    // ⚠️ Es SEC-3: antes la comparación era `!=` entre el entero guardado y lo que mandara
    // el cliente, así que el número 0 contra una cuenta sin código daba verdadero porque
    // `null == 0`. Ahora la hace el modelo con `hash_equals`.
    $cuenta = unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    foreach ([0, '0', '', null] as $intento) {
        $this->postJson('/api/password/reset', [
            'email' => 'cadete@example.test',
            'code' => $intento,
            'password' => 'LaNueva.1',
            'password_confirmation' => 'LaNueva.1',
        ])->assertStatus($intento === null || $intento === '' ? 422 : 400);
    }

    expect(Hash::check('LaDeAntes.1', $cuenta->fresh()->password))->toBeTrue();
});

test('a code with rubbish stuck to the end does not pass either', function () {
    unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $codigo = $this->postJson('/api/password/sendcode', ['email' => 'cadete@example.test'])
        ->json('user.codigo_de_verificacion');

    $this->postJson('/api/password/reset', [
        'email' => 'cadete@example.test',
        'code' => $codigo.'abc',
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ])->assertStatus(400);
});

test('a panel account cannot be taken over with this route', function () {
    $jefe = unaCuentaDeApp(Rol::ADMIN, 'jefe@example.test', ['codigo_de_verificacion' => 123456]);

    $this->postJson('/api/password/reset', [
        'email' => 'jefe@example.test',
        'code' => 123456,
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ])->assertStatus(400);

    expect(Hash::check('LaDeAntes.1', $jefe->fresh()->password))->toBeTrue();
});

test('the password has to be confirmed and long enough', function () {
    unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $corta = $this->postJson('/api/password/reset', [
        'email' => 'cadete@example.test',
        'code' => '123456',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertStatus(422);

    expect($corta->json('code'))->toBe(422)
        ->and($corta->json('error.password'))->toBeArray();

    $distinta = $this->postJson('/api/password/reset', [
        'email' => 'cadete@example.test',
        'code' => '123456',
        'password' => 'LaNueva.1',
        'password_confirmation' => 'OtraCosa.1',
    ])->assertStatus(422);

    expect($distinta->json('error.password.0'))->toBe('La confirmación de la contraseña no coincide.');
});

test('guessing the code is stopped after five tries, counted per mailbox', function () {
    unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $intento = fn (string $codigo) => $this->postJson('/api/password/reset', [
        'email' => 'cadete@example.test',
        'code' => $codigo,
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ]);

    foreach (range(1, RecuperarContrasenaController::MAXIMO_DE_INTENTOS) as $vuelta) {
        $intento('000'.str_pad((string) $vuelta, 3, '0', STR_PAD_LEFT))->assertStatus(400);
    }

    // ⚠️ El contador cuelga del email y no de la IP: repartir los pedidos entre varias IP no
    // debería comprar intentos extra.
    $intento('999999')->assertStatus(429)->assertHeader('Retry-After');
});

test('a good code clears the counter', function () {
    unaCuentaDeApp(Rol::CADETE, 'cadete@example.test');

    $codigo = $this->postJson('/api/password/sendcode', ['email' => 'cadete@example.test'])
        ->json('user.codigo_de_verificacion');

    $this->postJson('/api/password/reset', [
        'email' => 'cadete@example.test',
        'code' => '000000',
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ])->assertStatus(400);

    $this->postJson('/api/password/reset', [
        'email' => 'cadete@example.test',
        'code' => $codigo,
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ])->assertOk();

    expect(RateLimiter::attempts('password-reset|cadete@example.test'))->toBe(0);
});

test('an email that does not exist is not told apart from a wrong code', function () {
    // ⚠️ El sistema viejo valida `exists:users` acá, o sea que contesta 422 «no existe»: un
    // oráculo para averiguar qué casillas están registradas. El paso siguiente ya dice lo
    // mismo sin delatar nada.
    $this->postJson('/api/password/reset', [
        'email' => 'nadie@example.test',
        'code' => '123456',
        'password' => 'LaNueva.1',
        'password_confirmation' => 'LaNueva.1',
    ])->assertStatus(400)->assertJsonPath('error', 'El código es inválido.');
});
