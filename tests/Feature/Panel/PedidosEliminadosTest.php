<?php

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Rol;

/*
| Lo que el listado deja hacer con un pedido: verlo, borrarlo, borrar varios de una y
| recuperar los borrados.
|
| ⚠️ LA PANTALLA DE BORRADOS NO ES UN LUJO. La oficina se deshace de los pedidos que no
| llegan al final borrándolos, así que la mayor parte de los estados intermedios vive
| entre los borrados. Sin ella, un borrado por error no tiene vuelta desde el panel.
|
| ⚠️ Y un pedido FINALIZADO no se ofrece para borrar —ni botón ni casilla—, igual que en
| el listado viejo. Lo decide el servidor con `se_puede_borrar`, no la pantalla.
*/

test('a finished order is not offered for deletion, and the rest are', function () {
    $finalizado = Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create();
    $enCurso = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(function ($pagina) use ($finalizado, $enCurso) {
            $filas = collect($pagina->toArray()['props']['pedidos']['data'])->keyBy('id');

            expect($filas[$finalizado->id]['se_puede_borrar'])->toBeFalse()
                ->and($filas[$enCurso->id]['se_puede_borrar'])->toBeTrue();
        });
});

test('only an order with a cadete is offered for printing', function () {
    // ⚠️ El cupón es el papel que se le entrega AL CADETE, así que sin cadete asignado no
    // hay nada que imprimir: el listado viejo dibuja el botón dentro de un
    // `@if($pedido->cadete)`.
    $conCadete = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)
        ->create(['cadete_id' => Cadete::factory()]);
    // ⚠️ Cancelado y no «Sin asignar»: ese estado no entra en el listado, así que la
    // fila no estaría y el test diría otra cosa de la que quiere decir.
    $sinCadete = Pedido::factory()->enEstado(Pedido::ESTADO_CANCELADO)
        ->create(['cadete_id' => null]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(function ($pagina) use ($conCadete, $sinCadete) {
            $filas = collect($pagina->toArray()['props']['pedidos']['data'])->keyBy('id');

            expect($filas[$conCadete->id]['se_puede_imprimir'])->toBeTrue()
                ->and($filas[$sinCadete->id]['se_puede_imprimir'])->toBeFalse();
        });
});

test('the coupon it points at is the one the office prints', function () {
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)
        ->create(['cadete_id' => Cadete::factory()]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos/'.$pedido->id.'/cupon')
        ->assertOk()
        ->assertViewIs('cupon');
});

test('deleting one from the listing is a soft delete, and it lands in the deleted screen', function () {
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete('/panel/pedidos/'.$pedido->id)
        ->assertSessionHas('success');

    expect(Pedido::query()->find($pedido->id))->toBeNull()
        ->and(Pedido::onlyTrashed()->find($pedido->id))->not->toBeNull();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos/eliminados')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('pedidos/eliminados')
            ->where('pedidos.total', 1)
            ->where('pedidos.data.0.id', $pedido->id)
        );
});

test('the mass delete takes the ones that were ticked', function () {
    $unos = Pedido::factory()->count(2)->enEstado(Pedido::ESTADO_EN_CURSO)->create();
    $otro = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete('/panel/pedidos', ['pedidos' => $unos->pluck('id')->all()])
        ->assertSessionHas('success');

    expect(Pedido::onlyTrashed()->count())->toBe(2)
        ->and(Pedido::query()->find($otro->id))->not->toBeNull();
});

test('the mass delete is all or nothing when one of them is not yours', function () {
    // ⚠️ El sistema viejo recorre dos veces: comprueba el alcance de TODOS y recién
    // entonces borra. Con una sola pasada, un id ajeno en el medio dejaría media
    // selección borrada y la otra media no, sin forma de saber cuál.
    $comercio = Cliente::factory()->create();
    $propio = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)
        ->create(['cliente_id' => $comercio->id]);
    $ajeno = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)
        ->create(['cliente_id' => Cliente::factory()]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->delete('/panel/pedidos', ['pedidos' => [$propio->id, $ajeno->id]])
        ->assertNotFound();

    // Ninguno de los dos, ni siquiera el que sí era suyo.
    expect(Pedido::onlyTrashed()->count())->toBe(0);
});

test('an id that does not exist deletes nothing', function () {
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete('/panel/pedidos', ['pedidos' => [$pedido->id, 999_999]])
        ->assertNotFound();

    expect(Pedido::onlyTrashed()->count())->toBe(0);
});

test('a shop can delete its own orders, one at a time and several at once', function () {
    $comercio = Cliente::factory()->create();
    $pedidos = Pedido::factory()->count(2)->enEstado(Pedido::ESTADO_EN_CURSO)
        ->create(['cliente_id' => $comercio->id]);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->delete('/panel/pedidos', ['pedidos' => $pedidos->pluck('id')->all()])
        ->assertSessionHas('success');

    expect(Pedido::onlyTrashed()->count())->toBe(2);
});

test('a shop cannot see the deleted screen, and does not get the button either', function () {
    // Puede borrar los suyos y no puede recuperarlos: recuperar es deshacer, y deshacer
    // es administración. Es lo que hace el sistema viejo, donde la ruta vive en el grupo
    // de oficina y el botón está escondido con un @if.
    $comercio = Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->get('/panel/pedidos/eliminados')
        ->assertForbidden();

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('puede_ver_eliminados', false));
});

test('the office does get the button', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('puede_ver_eliminados', true));
});

test('restoring puts the order back in the listing', function () {
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create();
    $pedido->delete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch('/panel/pedidos/'.$pedido->id.'/restaurar')
        ->assertSessionHas('success');

    expect(Pedido::query()->find($pedido->id))->not->toBeNull();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('pedidos.total', 1));
});

test('a shop cannot restore, not even its own order', function () {
    $comercio = Cliente::factory()->create();
    $pedido = Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)
        ->create(['cliente_id' => $comercio->id]);
    $pedido->delete();

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, $comercio))
        ->patch('/panel/pedidos/'.$pedido->id.'/restaurar')
        ->assertForbidden();

    expect(Pedido::query()->find($pedido->id))->toBeNull();
});

test('the deleted screen is the one route that a parameter could have swallowed', function () {
    // ⚠️ `pedidos/eliminados` se registra ANTES que `pedidos/{pedido}`. Si alguien las
    // da vuelta, Laravel sale a buscar un pedido con id «eliminados» y esto da 404. Es
    // la misma advertencia que el sistema viejo dejó escrita en su archivo de rutas.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos/eliminados')
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('pedidos/eliminados'));
});
