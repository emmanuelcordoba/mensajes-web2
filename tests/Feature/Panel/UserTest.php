<?php

use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
| La pantalla de Usuarios de /panel.
|
| Es la del menú lateral viejo. Las reglas que tiene que sostener son tres, y las tres
| tienen número: la cuenta de un admin sólo la toca otro admin (SEC-9), sólo un admin
| nombra a otro admin (SEC-4), y el código de verificación no sale de la base (SEC-10).
*/

function idDelRolDelPanel(string $rol): int
{
    return Rol::query()
        ->firstOrCreate(['rol' => $rol], ['display_rol' => ucfirst($rol)])
        ->id;
}

test('the listing shows the panel accounts and not the ones from the apps', function () {
    $empleado = usuarioCon(Rol::EMPLEADO);
    $delApp = usuarioCon(Rol::CLIENTE_APP);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/users')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('users/index')
            ->where('usuarios.total', 2) // el admin que mira y el empleado
        );

    expect(User::query()->whereKey($delApp->id)->exists())->toBeTrue();
    expect($empleado->exists)->toBeTrue();
});

test('the verification code never leaves the server', function () {
    // ⚠️ SEC-10: con ese código se le cambia la contraseña a cualquiera. Viajaba en todo
    // JSON que incluyera un usuario.
    $cuenta = usuarioCon(Rol::EMPLEADO);
    $cuenta->forceFill(['codigo_de_verificacion' => 483927])->save();

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/users')
        ->assertSuccessful()
        ->assertDontSee('483927')
        ->assertDontSee('codigo_de_verificacion');
});

test('a shop cannot reach the screen at all', function () {
    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/users')
        ->assertForbidden();
});

test('an employee cannot edit the account of an administrator', function () {
    // ⚠️ SEC-9. Con su contraseña o su email, un empleado se volvía administrador.
    $admin = usuarioCon(Rol::ADMIN);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/users/{$admin->id}/edit")
        ->assertForbidden();
});

test('an employee cannot name an administrator', function () {
    // ⚠️ SEC-4. La regla está en el validador, y el formulario además no ofrece el rol.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/users', [
            'name' => 'Intruso',
            'email' => 'intruso@mensajes.test',
            'password' => 'una-contrasena-larga',
            'password_confirmation' => 'una-contrasena-larga',
            'rol_id' => idDelRolDelPanel(Rol::ADMIN),
        ])
        ->assertSessionHasErrors('rol_id');

    expect(User::query()->where('email', 'intruso@mensajes.test')->exists())->toBeFalse();
});

test('the form offers the admin role only to whoever could assign it', function () {
    // Ofrecer una opción que después se niega es el botón que siempre falla. El validador
    // la rechaza igual (SEC-4); esto es para que no haya que llegar hasta ahí.
    //
    // ⚠️ El rol se crea ANTES de las dos comprobaciones a propósito. La primera versión
    // de este test buscaba el nombre de producción, «Administrador», que en la base de
    // pruebas no existe —`usuarioCon()` lo crea como «Admin»—, así que el caso negativo
    // pasaba porque el rol no estaba, no porque estuviera filtrado. Pasar por la razón
    // equivocada es peor que fallar.
    $admin = Rol::query()->firstOrCreate(
        ['rol' => Rol::ADMIN],
        ['display_rol' => 'Administrador'],
    );

    $ofrecidos = function ($pagina) {
        return collect($pagina->toArray()['props']['roles'])->pluck('nombre');
    };

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/users/create')
        ->assertSuccessful()
        ->assertInertia(function ($pagina) use ($ofrecidos, $admin) {
            $pagina->component('users/create');

            expect($ofrecidos($pagina))->not->toContain($admin->display_rol)
                // Y sí le ofrece los otros: si la lista viniera vacía, el test de arriba
                // pasaría solo.
                ->and($ofrecidos($pagina))->not->toBeEmpty();
        });

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/users/create')
        ->assertInertia(function ($pagina) use ($ofrecidos, $admin) {
            expect($ofrecidos($pagina))->toContain($admin->display_rol);
        });
});

test('a new account is stored with the password hashed and the email normalised', function () {
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->post('/panel/users', [
            'name' => 'Paula',
            // Con mayúsculas y espacios a propósito.
            'email' => '  Paula@Mensajes.Test  ',
            'password' => 'una-contrasena-larga',
            'password_confirmation' => 'una-contrasena-larga',
            'rol_id' => idDelRolDelPanel(Rol::EMPLEADO),
        ])
        ->assertRedirect('/panel/users');

    $creada = User::query()->where('name', 'Paula')->sole();

    expect($creada->email)->toBe('paula@mensajes.test')
        ->and(Hash::check('una-contrasena-larga', $creada->password))->toBeTrue();
});

test('an email already taken by an account that was given up is refused', function () {
    // ⚠️ DATA-7: `users.email` es UNIQUE a secas, así que una cuenta dada de baja sigue
    // ocupando su email. Si la validación mirara sólo las vivas, esto pasaría el
    // formulario y reventaría contra el índice.
    $baja = usuarioCon(Rol::EMPLEADO);
    $baja->forceFill(['email' => 'ocupado@mensajes.test'])->save();
    $baja->delete();

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->post('/panel/users', [
            'name' => 'Otro',
            'email' => 'ocupado@mensajes.test',
            'password' => 'una-contrasena-larga',
            'password_confirmation' => 'una-contrasena-larga',
            'rol_id' => idDelRolDelPanel(Rol::EMPLEADO),
        ])
        ->assertSessionHasErrors('email');
});

test('editing without a new password leaves the old one alone', function () {
    $cuenta = usuarioCon(Rol::EMPLEADO);
    $antes = $cuenta->password;

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->patch("/panel/users/{$cuenta->id}", [
            'name' => 'Nombre nuevo',
            'email' => $cuenta->email,
            'rol_id' => $cuenta->rol_id,
        ])
        ->assertRedirect('/panel/users');

    expect($cuenta->refresh()->name)->toBe('Nombre nuevo')
        ->and($cuenta->password)->toBe($antes);
});

test('a shop account needs a shop, and loses it when it stops being one', function () {
    $comercio = Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->post('/panel/users', [
            'name' => 'El comercio',
            'email' => 'comercio@mensajes.test',
            'password' => 'una-contrasena-larga',
            'password_confirmation' => 'una-contrasena-larga',
            'rol_id' => idDelRolDelPanel(Rol::RESTRINGIDO),
        ])
        // Sin comercio no se puede: sin cliente no hay pertenencia que comprobar, y la
        // cuenta entraría al panel sin ver nada. Ver PANEL-6 y SEC-7.
        ->assertSessionHasErrors('cliente_restringido_id');

    $cuenta = usuarioCon(Rol::RESTRINGIDO, $comercio);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->patch("/panel/users/{$cuenta->id}", [
            'name' => $cuenta->name,
            'email' => $cuenta->email,
            // Deja de ser comercio: el vínculo viejo no puede quedar colgado.
            'rol_id' => idDelRolDelPanel(Rol::EMPLEADO),
        ])
        ->assertRedirect('/panel/users');

    expect($cuenta->refresh()->cliente_restringido_id)->toBeNull();
});

test('only an administrator gives an account up, and never their own', function () {
    $otro = usuarioCon(Rol::EMPLEADO);

    // Un empleado no borra, aunque el listado viejo le dibujaba el botón igual.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/users/{$otro->id}")
        ->assertForbidden();

    $admin = usuarioCon(Rol::ADMIN);

    // Nadie se borra a sí mismo: el siguiente clic sería contra una cuenta que ya no
    // entra. El sistema viejo no lo impedía.
    $this->actingAs($admin)
        ->delete("/panel/users/{$admin->id}")
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete("/panel/users/{$otro->id}")
        ->assertRedirect('/panel/users');

    expect($otro->refresh()->trashed())->toBeTrue();
});

test('the listing offers exactly the buttons the policy would allow', function () {
    // Una regla, dos lectores: el listado pregunta lo mismo que el controlador aplica.
    $admin = usuarioCon(Rol::ADMIN);
    $empleado = usuarioCon(Rol::EMPLEADO);

    $this->actingAs($empleado)
        ->get('/panel/users')
        ->assertInertia(function ($pagina) use ($admin, $empleado) {
            $filas = collect($pagina->toArray()['props']['usuarios']['data'])->keyBy('id');

            // Sobre la cuenta del admin, un empleado no puede nada.
            expect($filas[$admin->id]['puede_editarse'])->toBeFalse()
                ->and($filas[$admin->id]['puede_borrarse'])->toBeFalse()
                // Sobre la suya puede editar, pero no darse de baja.
                ->and($filas[$empleado->id]['puede_editarse'])->toBeTrue()
                ->and($filas[$empleado->id]['puede_borrarse'])->toBeFalse();
        });
});

test('looking a shop up by text does not drag in the one numbered zero', function () {
    // ⚠️ Lo encontró abrir la pantalla: buscar «farmacia» traía además al cliente número
    // 0, que es un banco, porque la consulta comparaba `numero` con `(int) $buscado` y
    // `(int)` sobre un texto da 0. En una lista de la que se elige a quién le va a ver
    // los pedidos una cuenta, un resultado de más no es un detalle.
    $cero = Cliente::factory()->create(['numero' => 0, 'nombre' => 'Banco Nación']);
    $buscado = Cliente::factory()->create(['nombre' => 'Farmacia del Pueblo']);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->getJson('/panel/users/comercios?q=farmacia')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonFragment(['id' => $buscado->id]);

    // Y por número sigue funcionando.
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->getJson('/panel/users/comercios?q=0')
        ->assertJsonFragment(['id' => $cero->id]);
});

test('a shop cannot look up the list of shops either', function () {
    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->getJson('/panel/users/comercios?q=farmacia')
        ->assertForbidden();
});
