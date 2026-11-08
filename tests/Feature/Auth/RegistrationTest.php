<?php

use Laravel\Fortify\Features;

/*
| El registro web.
|
| Va detrás de `REGISTRO_ABIERTO` y cerrado por defecto —ver `config/fortify.php`—, así
| que los dos primeros se saltean solos mientras lo esté y el que manda es el último: que
| la puerta no esté. El salteo por test, y no en un `beforeEach`, es para que el tercero
| corra igual; es lo mismo que hace SecurityTest.
*/

test('registration screen can be rendered', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('panel.inicio', absolute: false));
});

test('closed is what it is without the variable', function () {
    // ⚠️ Lo que esto defiende es el valor por defecto. Un entorno nuevo, o uno al que se
    // le olvidó la variable, tiene que quedar cerrado: del otro lado están los datos de
    // 9.075 personas y 1.734 imágenes de DNI, en una URL pública. La suite corre sin
    // `REGISTRO_ABIERTO`, así que esto es el defecto y no una configuración de prueba.
    expect(env('REGISTRO_ABIERTO'))->toBeNull()
        ->and(Features::enabled(Features::registration()))->toBeFalse();

    // La ruta no existe, así que se pide por URL y no por nombre.
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

test('the sign up link follows the variable', function () {
    // El enlace sale de la misma lista de features, no de una copia: si se abriera el
    // registro y el enlace no volviera, nadie encontraría la puerta abierta.
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('canRegister', false));
});
