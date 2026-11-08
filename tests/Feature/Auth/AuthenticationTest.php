<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('panel.inicio', absolute: false));
});

test('an account with no panel role lands on a 403, not on a broken page', function () {
    // ⚠️ Es la consecuencia de caer en /panel después de entrar, y queda fijada acá para
    // que sea una decisión y no un descubrimiento. La reciben las cuentas de cadete, las
    // 20 que en producción no tienen rol_id, y también cualquier registro nuevo, porque
    // el formulario de registro no asigna rol. El sistema viejo se comporta igual: sus
    // cinco controladores de auth redirigen al panel, que exige rol.
    $user = User::factory()->create(['rol_id' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('panel.inicio', absolute: false));

    $this->get(route('panel.inicio'))->assertForbidden();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('someone already signed in is sent to the panel, not to the dashboard', function () {
    // ⚠️ Es otro camino y otro middleware: a quien ya entró lo redirige
    // RedirectIfAuthenticated, que no mira config(fortify.home). Se configura en
    // bootstrap/app.php y se comprueba acá porque es fácil arreglar uno y olvidar el otro.
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get(route('login'))
        ->assertRedirect(route('panel.inicio', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('the screen answers in spanish', function () {
    // ⚠️ `APP_LOCALE=es` no alcanza: Laravel no trae los idiomas adentro, y sin los
    // archivos de `lang/es` esto contesta «These credentials do not match our records.».
    // Era así hasta el 2026-11-07. El mensaje no dice cuál de los dos está mal.
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['email' => 'El email o la contraseña no son correctos.']);

    $this->post(route('login.store'), [])
        ->assertSessionHasErrors([
            'email' => 'Hace falta completar el email.',
            'password' => 'Hace falta completar la contraseña.',
        ]);
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
