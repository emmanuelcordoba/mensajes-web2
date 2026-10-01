<?php

use App\Models\Rol;
use App\Models\User;

/*
| Lo que viaja a TODAS las páginas.
|
| `HandleInertiaRequests::share()` compartía `$request->user()` entero, así que en cada
| página —el listado de pedidos, el perfil, la portada— viajaban seis columnas que no
| lee nadie: `bloqueado`, `bloqueado_mensaje`, `codigo_generado_at`, `rol_id`,
| `cliente_restringido_id` y `deleted_at`. `codigo_de_verificacion` sí estaba oculto
| (SEC-10); el resto salía por omisión, que es la forma de SEC-14.
|
| Estos tests existen para que no vuelva solo: agregar una columna a `users` no la
| publica, porque el alcance estricto de `has()` falla si aparece una propiedad que el
| test no nombró.
*/

test('what travels to every page is only what the frontend reads', function () {
    $user = usuarioCon(Rol::EMPLEADO);

    $this->actingAs($user)
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina
            // El alcance es estricto: si mañana aparece otra propiedad acá dentro,
            // este test falla y hay que decidir a mano si se publica.
            ->has('auth.user', fn ($usuario) => $usuario
                ->where('id', $user->id)
                ->where('name', $user->name)
                ->where('email', $user->email)
                ->where('rol', Rol::EMPLEADO)
                ->has('email_verified_at')
            )
        );
});

test('an internal column does not reach the frontend even when it has a value', function () {
    // Con valor y no en null: una columna vacía podría no aparecer en el JSON y el
    // test pasaría sin probar nada.
    $user = usuarioCon(Rol::EMPLEADO);
    $user->forceFill([
        'bloqueado' => 'falta-de-pago',
        'bloqueado_mensaje' => 'Bloqueado por falta de pago.',
        'codigo_de_verificacion' => 123456,
        'codigo_generado_at' => now(),
    ])->save();

    $respuesta = $this->actingAs($user)->get('/panel/pedidos');

    foreach (['bloqueado', 'bloqueado_mensaje', 'codigo_de_verificacion', 'codigo_generado_at', 'rol_id', 'deleted_at'] as $columna) {
        $respuesta->assertInertia(fn ($pagina) => $pagina->missing("auth.user.{$columna}"));
    }
});

test('the role travels by name, because a row id tells the frontend nothing', function () {
    // El panel tiene que poder esconder lo que un restringido no puede usar, y para eso
    // necesita el nombre del rol y no `rol_id`.
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('auth.user.rol', Rol::ADMIN));
});

test('a user with no role shares a null role instead of failing', function () {
    // Hay 20 usuarios activos sin rol_id en producción. No entran al panel, pero sí a
    // las pantallas de perfil, que comparten lo mismo.
    $this->actingAs(User::factory()->create())
        ->get('/settings/profile')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->where('auth.user.rol', null));
});

test('a guest gets no user at all', function () {
    $this->get('/')
        ->assertInertia(fn ($pagina) => $pagina->where('auth.user', null));
});
