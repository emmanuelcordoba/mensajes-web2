<?php

use App\Models\Rol;
use App\Models\User;

/*
| El panel de administración de Filament, en /admin.
|
| ⚠️ Esto existe porque la puerta de Filament viene abierta. Su middleware consulta
| `canAccessPanel()` sólo si el modelo implementa `FilamentUser`; si no lo implementa,
| deja pasar a **cualquier usuario autenticado** mientras APP_ENV sea `local`. O sea que
| el recorte depende de que nadie se haya olvidado de escribirlo, que es exactamente la
| forma de SEC-4 —una cuenta de comercio llegaba a la administración de usuarios y podía
| crearse un administrador—.
|
| Un test que sólo comprobara que un admin entra pasaría igual con la puerta abierta. Lo
| que hace falta es el inverso, y es lo que hay acá: los otros tres no entran.
*/

test('an admin reaches the admin panel', function () {
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/admin')
        ->assertSuccessful();
});

test('nobody else reaches the admin panel', function (?string $rol) {
    $user = $rol === null
        ? User::factory()->create(['rol_id' => null])
        : usuarioCon($rol);

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
})->with([
    // Un empleado hace su trabajo desde /panel, con las reglas del negocio puestas.
    // /admin es alta y baja sobre las tablas directamente.
    'empleado' => Rol::EMPLEADO,
    'comercio' => Rol::RESTRINGIDO,
    // Las cuentas de cadete y las 20 que en producción no tienen rol_id.
    'sin rol' => null,
]);

test('a visitor is sent to the one login the application has', function () {
    // ⚠️ A /login, no a /admin/login: el panel se declara sin `->login()` a propósito,
    // porque dos pantallas de ingreso para las mismas credenciales son dos lugares donde
    // arreglar lo mismo. Si alguien agrega `->login()`, este test lo dice.
    $this->get('/admin')
        ->assertRedirect(route('login', absolute: false));
});

test('/admin is never more open than /panel about email verification', function () {
    // ⚠️ HOY LAS DOS DEJAN ENTRAR SIN VERIFICAR, y no porque falte el middleware: `User`
    // no implementa MustVerifyEmail —la línea está comentada en el modelo, como viene del
    // starter kit—, así que tanto el `verified` de las rutas del panel como el
    // EnsureEmailIsVerified de /admin son adorno. Es fácil de creer que están puestos:
    // están escritos.
    //
    // Encenderlo es una decisión aparte y cara. Medido sobre la copia de producción: de
    // los 9 empleados activos hay 1 verificado, de los 2 comercios ninguno, y de 280
    // cadetes 27. Prenderlo sin más deja a la oficina afuera.
    //
    // Entonces lo que este test fija no es la política sino que las dos pantallas tengan
    // la misma. El día que se encienda, /admin no puede quedar abierto mientras /panel
    // se cierra: es la más peligrosa de las dos, porque toca las filas sin las reglas
    // del negocio en el medio.
    $admin = usuarioCon(Rol::ADMIN);
    $admin->forceFill(['email_verified_at' => null])->save();

    $enAdmin = $this->actingAs($admin)->get('/admin');
    $enPanel = $this->actingAs($admin)->get('/panel');

    expect($enAdmin->getStatusCode())->toBe($enPanel->getStatusCode());
});
