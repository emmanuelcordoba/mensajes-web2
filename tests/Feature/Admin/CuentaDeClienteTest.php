<?php

use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use App\Services\CuentaDeCliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
| Corregir el email y la contraseña de la cuenta de la app desde la pantalla del cliente.
|
| Lo que más vale acá no es que los campos guarden: es que dejarlos en blanco NO cambie
| nada, que cambiar la contraseña corte lo que quedó abierto con la vieja, y que guardar
| sin tocar el email no diga que el email está en uso.
*/

beforeEach(function () {
    $this->actingAs(usuarioCon(Rol::ADMIN));
});

/** Un cliente con su cuenta de la app, que es el caso que importa. */
function clienteConCuenta(array $deLaCuenta = []): Cliente
{
    $cuenta = User::factory()->create($deLaCuenta + [
        'email' => 'persona@ejemplo.com',
        'password' => 'la-de-antes',
        'email_verified_at' => Carbon::now(),
    ]);

    return Cliente::factory()->create(['user_id' => $cuenta->id]);
}

test('the email and the password of the app account are edited from the client screen', function () {
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm([
            'cuenta_email' => 'nueva@ejemplo.com',
            'cuenta_password' => 'secreto123',
            'cuenta_password_confirmation' => 'secreto123',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $cuenta = $cliente->fresh()->user;

    expect($cuenta->email)->toBe('nueva@ejemplo.com')
        ->and(Hash::check('secreto123', $cuenta->password))->toBeTrue();
});

test('the email arrives lowercase and trimmed', function () {
    // El modelo lo normaliza al guardar; el formulario lo normaliza antes de validar. Si
    // sólo pasara lo primero, `unique` compararía algo distinto de lo guardado. Ver DATA-7.
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['cuenta_email' => '  Nueva@Ejemplo.COM '])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cliente->fresh()->user->email)->toBe('nueva@ejemplo.com');
});

test('leaving the password blank does not change it', function () {
    // ⚠️ Es el test de la línea más peligrosa de la pantalla. Sin el `dehydrated`, corregir
    // un teléfono le escribiría la contraseña en blanco a la persona y la dejaría afuera.
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm([
            'telefono' => '3814643232',
            'cuenta_password' => '',
            'cuenta_password_confirmation' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('la-de-antes', $cliente->fresh()->user->password))->toBeTrue();
});

test('saving without touching the email does not claim the email is taken', function () {
    // ⚠️ El test de una trampa concreta: `unique(ignoreRecord: true)` compararía contra
    // `clientes`, donde la columna `email` no existe, y entonces guardar cualquier cosa sin
    // tocar el email daría «ya está en uso». Lo que se ignora es la CUENTA del cliente.
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['nombre' => 'Farmacia del Pueblo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cliente->fresh()->nombre)->toBe('Farmacia del Pueblo');
});

test('an email used by another account is rejected', function () {
    User::factory()->create(['email' => 'ocupado@ejemplo.com']);
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['cuenta_email' => 'ocupado@ejemplo.com'])
        ->call('save')
        ->assertHasFormErrors(['cuenta_email']);
});

test('an email used by an account that was given up stays taken', function () {
    // ⚠️ `users.email` es UNIQUE a secas: una cuenta dada de baja sigue ocupando su email.
    // Es la mitad de DATA-7 que no era obvia, y sin esto la validación pasa y revienta
    // contra el índice al guardar.
    $baja = User::factory()->create(['email' => 'se-fue@ejemplo.com']);
    $baja->delete();

    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['cuenta_email' => 'se-fue@ejemplo.com'])
        ->call('save')
        ->assertHasFormErrors(['cuenta_email']);
});

test('changing the password revokes the tokens the app was using', function () {
    // ⚠️ Un token de Sanctum no mira la contraseña: sin esto sigue entrando a la API
    // después del cambio, y cambiarle la contraseña a una cuenta tomada por otro no le
    // cortaría el acceso.
    $cliente = clienteConCuenta();
    $cuenta = $cliente->user;
    $cuenta->createToken('app-clientes');
    $cuenta->createToken('app-clientes');

    expect($cuenta->tokens()->count())->toBe(2);

    app(CuentaDeCliente::class)->actualizar($cliente, null, 'secreto123');

    expect($cuenta->fresh()->tokens()->count())->toBe(0);
});

test('changing the password closes the sessions in the database', function () {
    // ⚠️ El driver se fuerza acá porque en los tests es `array` (phpunit.xml), y en
    // producción es `database`. Sin esto la prueba mide la guarda y no la limpieza.
    config(['session.driver' => 'database']);

    $cliente = clienteConCuenta();
    $cuenta = $cliente->user;

    DB::table('sessions')->insert([
        'id' => 'una-sesion',
        'user_id' => $cuenta->id,
        'payload' => '',
        'last_activity' => Carbon::now()->getTimestamp(),
    ]);

    $cambios = app(CuentaDeCliente::class)->actualizar($cliente, null, 'secreto123');

    expect($cambios['sesiones'])->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $cuenta->id)->count())->toBe(0);
});

test('the sessions are left alone when they do not live in the database', function () {
    // ⚠️ Con `redis` o `file` la tabla `sessions` no es donde están, y en algunos
    // despliegues ni existe. La guarda mira el driver en vez de suponerlo: lo que no puede
    // pasar es que cambiar una contraseña reviente por buscar una tabla ausente.
    config(['session.driver' => 'redis']);

    $cambios = app(CuentaDeCliente::class)->actualizar(clienteConCuenta(), null, 'secreto123');

    expect($cambios['contrasena'])->toBeTrue()
        ->and($cambios['sesiones'])->toBe(0);
});

test('changing the password changes the remember token', function () {
    // La cookie de «mantenerme conectado» no está en `sessions` ni en los tokens.
    $cliente = clienteConCuenta();
    $cuenta = $cliente->user;
    $cuenta->forceFill(['remember_token' => 'el-de-antes'])->save();

    app(CuentaDeCliente::class)->actualizar($cliente, null, 'secreto123');

    expect($cuenta->fresh()->remember_token)->not->toBe('el-de-antes');
});

test('changing only the email leaves the sessions alone', function () {
    // Corregir un email mal escrito no es motivo para echar a nadie de la app.
    $cliente = clienteConCuenta();
    $cliente->user->createToken('app-clientes');

    $cambios = app(CuentaDeCliente::class)->actualizar($cliente, 'otra@ejemplo.com', null);

    expect($cambios['tokens'])->toBe(0)
        ->and($cliente->user->fresh()->tokens()->count())->toBe(1);
});

test('changing the email undoes the verification', function () {
    // ⚠️ Tiene consecuencia: `AuthController::login()` responde 424 y no deja entrar
    // mientras `email_verified_at` sea null. Se hace igual, porque si la verificación
    // sobreviviera, apuntar la cuenta de un cliente a cualquier casilla y entrar con ella
    // sería un movimiento de una sola pantalla.
    $cliente = clienteConCuenta();

    app(CuentaDeCliente::class)->actualizar($cliente, 'otra@ejemplo.com', null);

    expect($cliente->user->fresh()->email_verified_at)->toBeNull();
});

test('writing the same email again does not undo the verification', function () {
    // Guardar un cambio de dirección no tiene por qué dejar a la persona sin verificar.
    $cliente = clienteConCuenta();

    $cambios = app(CuentaDeCliente::class)->actualizar($cliente, 'persona@ejemplo.com', null);

    expect($cambios['email'])->toBeFalse()
        ->and($cliente->user->fresh()->email_verified_at)->not->toBeNull();
});

test('a client with no app account is told so, and has no fields to fill', function () {
    // 3.684 de los 11.000 clientes están así: los cargó la oficina y nunca se registraron.
    $cliente = Cliente::factory()->create(['user_id' => null]);

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->assertFormFieldDoesNotExist('cuenta_email')
        ->assertFormFieldDoesNotExist('cuenta_password');

    expect(CuentaDeCliente::porQueNo($cliente))->toContain('no tiene cuenta de la app');
});

test('the service refuses a client with no account instead of writing anything', function () {
    $cliente = Cliente::factory()->create(['user_id' => null]);

    expect(fn () => app(CuentaDeCliente::class)->actualizar($cliente, 'otra@ejemplo.com', 'secreto123'))
        ->toThrow(RuntimeException::class);
});

test('the password rule is the one the app uses, not the one the panel uses', function () {
    // ⚠️ En producción `Password::defaults()` pide 12 caracteres con símbolos, y la app
    // pide 6. Exigir el default acá dejaría al panel sin poder escribir una contraseña que
    // la app sí acepta. Ver CuentaDeCliente::reglasDeContrasena().
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm([
            'cuenta_password' => 'abc123',
            'cuenta_password_confirmation' => 'abc123',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('abc123', $cliente->fresh()->user->password))->toBeTrue();
});

test('a password shorter than what the app accepts is rejected', function () {
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm([
            'cuenta_password' => 'abc12',
            'cuenta_password_confirmation' => 'abc12',
        ])
        ->call('save')
        ->assertHasFormErrors(['cuenta_password']);
});

test('a password that does not match its confirmation is rejected', function () {
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm([
            'cuenta_password' => 'secreto123',
            'cuenta_password_confirmation' => 'otracosa123',
        ])
        ->call('save')
        ->assertHasFormErrors(['cuenta_password']);
});

test('the names the app writes can be corrected', function () {
    $cliente = Cliente::factory()->create([
        'nombre' => 'Farmacia del Pueblo',
        'nombres' => 'Ana',
        'apellidos' => 'Peres',
    ]);

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['apellidos' => 'Pérez'])
        ->call('save')
        ->assertHasNoFormErrors();

    $cliente->refresh();

    // Y el nombre del comercio sigue siendo el del comercio: es la lección de DATA-8.
    expect($cliente->apellidos)->toBe('Pérez')
        ->and($cliente->nombre_mostrado)->toBe('Farmacia del Pueblo');
});

test('the account fields never reach the clientes table', function () {
    // ⚠️ `cuenta_email` no es una columna de `clientes`. Si dejara de sacarse de los datos,
    // el `Fillable` del modelo lo ignoraría EN SILENCIO y no habría síntoma hasta que
    // alguien notara que el email no cambia.
    $cliente = clienteConCuenta();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['cuenta_email' => 'nueva@ejemplo.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_keys(DB::table('clientes')->where('id', $cliente->id)->first() ? (array) DB::table('clientes')->where('id', $cliente->id)->first() : []))
        ->not->toContain('cuenta_email')
        ->and($cliente->fresh()->user->email)->toBe('nueva@ejemplo.com');
});

test('the screen warns when the account is also a cadete', function () {
    // 6 cuentas de producción están así, porque es la misma persona.
    $cliente = clienteConCuenta();
    Cadete::factory()->create(['user_id' => $cliente->user_id]);

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->assertSee('también');
});
