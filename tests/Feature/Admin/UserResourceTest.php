<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
| La pantalla de cuentas de /admin.
|
| Lo que más vale de estos tests es el primero. El recurso se generó con `--generate`,
| que arma el formulario y la tabla leyendo las columnas, y dejó puestos el código de
| verificación y el segundo factor. O sea: la plantilla no sabe que esos campos no se
| muestran, y los vuelve a poner cada vez que alguien corra el generador de nuevo.
*/

beforeEach(function () {
    $this->actingAs(usuarioCon(Rol::ADMIN));
});

/**
 * El id de un rol, creándolo si hace falta.
 *
 * ⚠️ En la base de tests los roles no existen hasta que alguien los crea: `usuarioCon()`
 * crea el suyo y nada más. Buscar el id con `value()` devolvía null en silencio, y el
 * formulario respondía «el rol es obligatorio» por una razón que no era la del test.
 *
 * Con nombre propio porque una función declarada en un archivo de tests es global.
 */
function idDelRol(string $rol): int
{
    return Rol::query()
        ->firstOrCreate(['rol' => $rol], ['display_rol' => ucfirst($rol)])
        ->id;
}

test('the sensitive columns the generator added are not on the screen', function () {
    // ⚠️ Con `codigo_de_verificacion` se le cambia la contraseña a cualquiera: por eso
    // está en #[Hidden] del modelo desde SEC-10. Los otros dos son el segundo factor de
    // la persona, que no es de nadie más.
    Livewire::test(CreateUser::class)
        ->assertFormFieldDoesNotExist('codigo_de_verificacion')
        ->assertFormFieldDoesNotExist('codigo_generado_at')
        ->assertFormFieldDoesNotExist('two_factor_secret')
        ->assertFormFieldDoesNotExist('two_factor_recovery_codes');
});

test('the verification code of a user never reaches the listing', function () {
    $conCodigo = usuarioCon(Rol::EMPLEADO);
    $conCodigo->forceFill(['codigo_de_verificacion' => 483927])->save();

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertDontSee('483927');
});

test('a new account is stored with the password hashed and the email normalised', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Paula',
            // Con mayúsculas y espacios a propósito.
            'email' => '  Paula@Mensajes.Test  ',
            'password' => 'una-contrasena-larga',
            'password_confirmation' => 'una-contrasena-larga',
            'rol_id' => idDelRol(Rol::EMPLEADO),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $creada = User::query()->where('name', 'Paula')->sole();

    expect($creada->email)->toBe('paula@mensajes.test')
        ->and($creada->password)->not->toBe('una-contrasena-larga')
        ->and(Hash::check('una-contrasena-larga', $creada->password))->toBeTrue();
});

test('an email already taken by an account that was deleted is refused', function () {
    // ⚠️ El caso que no es obvio y el que frenó la migración: `users.email` es UNIQUE a
    // secas, así que una cuenta dada de baja SIGUE ocupando su email. Si la validación
    // mirara sólo las vivas, esto pasaría el formulario y reventaría contra el índice.
    // Ver DATA-7.
    $baja = usuarioCon(Rol::EMPLEADO);
    $baja->forceFill(['email' => 'ocupado@mensajes.test'])->save();
    $baja->delete();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Otro',
            'email' => 'ocupado@mensajes.test',
            'password' => 'una-contrasena-larga',
            'password_confirmation' => 'una-contrasena-larga',
            'rol_id' => idDelRol(Rol::EMPLEADO),
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

test('editing without touching the password leaves it alone', function () {
    $cuenta = usuarioCon(Rol::EMPLEADO);
    $antes = $cuenta->password;

    Livewire::test(EditUser::class, ['record' => $cuenta->getKey()])
        ->fillForm(['name' => 'Nombre nuevo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cuenta->refresh()->name)->toBe('Nombre nuevo')
        ->and($cuenta->password)->toBe($antes);
});

test('the shop an account can see is only asked for when the role is a shop', function () {
    $comercio = Cliente::factory()->create();

    Livewire::test(CreateUser::class)
        ->fillForm(['rol_id' => idDelRol(Rol::EMPLEADO)])
        ->assertFormFieldHidden('cliente_restringido_id')
        ->fillForm(['rol_id' => idDelRol(Rol::RESTRINGIDO)])
        ->assertFormFieldVisible('cliente_restringido_id')
        ->fillForm([
            'name' => 'El comercio',
            'email' => 'comercio@mensajes.test',
            'password' => 'una-contrasena-larga',
            'password_confirmation' => 'una-contrasena-larga',
            'cliente_restringido_id' => $comercio->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('name', 'El comercio')->sole()->cliente_restringido_id)
        ->toBe($comercio->id);
});

test('the listing starts on the panel accounts, like the old screen did', function () {
    $empleado = usuarioCon(Rol::EMPLEADO);
    $delApp = usuarioCon('cliente_app');

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$empleado])
        ->assertCanNotSeeTableRecords([$delApp]);
});

test('the panel filter can be turned off, which the old screen could not', function () {
    $delApp = usuarioCon('cliente_app');

    Livewire::test(ListUsers::class)
        ->filterTable('del_panel', false)
        ->assertCanSeeTableRecords([$delApp]);
});

test('nobody can delete their own account from the listing', function () {
    // ⚠️ El siguiente clic sería contra una cuenta que ya no entra.
    $yo = auth()->user();

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('delete', $yo);
});

test('nobody can change their own role from the form', function () {
    // Bajarse el rol es perder /admin en el mismo clic.
    $yo = auth()->user();

    Livewire::test(EditUser::class, ['record' => $yo->getKey()])
        ->assertFormFieldDisabled('rol_id');
});
