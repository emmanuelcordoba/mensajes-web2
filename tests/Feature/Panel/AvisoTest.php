<?php

use App\Models\Cliente;
use App\Models\Rol;

/*
| El mensaje que el servidor deja después de escribir.
|
| ⚠️ Todas las pantallas del panel vienen dejando `->with('success', ...)` desde la
| primera, y hasta el 2026-10-03 no lo leía nadie: se guardaba en la sesión, la sesión lo
| descartaba en el pedido siguiente y la pantalla contestaba en silencio. `<Toaster />`
| estaba montado en app.tsx sin que nada le mandara nada. El panel viejo mostraba estas dos
| alertas arriba de cada vista.
|
| El test está acá y no en cada pantalla porque el mensaje se comparte en un solo lugar:
| HandleInertiaRequests. Lo que cada controlador escribe ya lo prueba su propio archivo.
*/

test('what the controller leaves in the session reaches the screen', function () {
    $cliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->from('/panel/clientes')
        ->patch("/panel/clientes/{$cliente->id}", [
            'numero' => $cliente->numero,
            'nombre' => 'Un nombre nuevo',
            'direccion' => 'San Martín 100',
            'telefono' => '3815550000',
        ])
        ->assertRedirect('/panel/clientes');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes')
        ->assertInertia(fn ($pagina) => $pagina->where('aviso.success', 'Cliente actualizado.'));
});

test('with nothing to say, the two messages travel empty', function () {
    // El frontend pregunta por los dos campos en cada visita: si no estuvieran, la lectura
    // reventaría en lugar de no mostrar nada.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('aviso.success', null)
            ->where('aviso.danger', null));
});
