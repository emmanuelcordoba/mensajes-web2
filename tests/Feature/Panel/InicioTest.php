<?php

use App\Http\Controllers\Panel\MapaController;
use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Rol;
use App\Services\ColaDeCadetes;

/*
| Inicio — primera parte: la columna «Cadetes activos».
|
| Lo que esta columna tiene que garantizar es que sea la misma cola que ve el mostrador —los
| mismos estados, el mismo orden—, que la oficina pueda mandar a uno al final y vaciarla, y
| que un comercio no la vea. El formulario de pedido y la lista de espera son las otras dos
| partes y todavía no están.
*/

/** Un cadete en la cola, llegado en ese segundo. */
function unCadeteEnLaCola(int $movil, int $llegada, string $estado = Cadete::ESTADO_ACTIVO_WEB): Cadete
{
    return Cadete::factory()->create([
        'numero_movil' => $movil,
        'estado' => $estado,
        'orden_cola' => $llegada,
    ]);
}

test('the office opens the home screen and sees the queue', function () {
    unCadeteEnLaCola(11, 1000);

    foreach ([Rol::ADMIN, Rol::EMPLEADO] as $rol) {
        $this->actingAs(usuarioCon($rol))
            ->get('/panel')
            ->assertSuccessful()
            ->assertInertia(fn ($pagina) => $pagina
                ->component('inicio/index')
                ->has('cola', 1));
    }
});

test('a shop opens the same screen and the queue is not there', function () {
    // ⚠️ Es la razón por la que un comercio entra a esta pantalla: la columna del medio. Las
    // otras dos son de oficina, como el `@if(tieneRol(['admin','empleado']))` del panel viejo.
    unCadeteEnLaCola(11, 1000);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, Cliente::factory()->create()))
        ->get('/panel')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('inicio/index')
            // null y no vacío: «no te corresponde» no es «no hay nadie».
            ->where('cola', null)
            ->where('mapa', null));
});

test('the queue is in arrival order, and only the ones who are in it', function () {
    unCadeteEnLaCola(33, 3000);
    unCadeteEnLaCola(11, 1000);
    unCadeteEnLaCola(22, 2000, Cadete::ESTADO_ACTIVO_APP);

    // Con un pedido en la mano no está en la cola: no puede recibir otro.
    unCadeteEnLaCola(44, 500, Cadete::ESTADO_CON_PEDIDO_APP);
    unCadeteEnLaCola(55, 400, Cadete::ESTADO_INACTIVO);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(function ($pagina) {
            $cola = $pagina->toArray()['props']['cola'];

            expect(array_column($cola, 'movil'))->toBe([11, 22, 33])
                ->and($cola[0]['en_la_app'])->toBeFalse()
                ->and($cola[1]['en_la_app'])->toBeTrue();
        });
});

test('the queue does not carry the name of anybody', function () {
    // ⚠️ La pantalla queda abierta todo el día en una oficina, a la vista de quien pase. De
    // la cola se muestra el móvil y el vehículo, como en el panel viejo; el nombre sale sólo
    // cuando alguien abre el detalle.
    unCadeteEnLaCola(11, 1000);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => expect(
            array_keys($pagina->toArray()['props']['cola'][0]),
        )->toBe(['id', 'movil', 'vehiculo', 'vehiculo_entero', 'en_la_app']));
});

test('a cadete sent to the end of the queue goes last', function () {
    $this->travelTo('2026-10-05 12:00:00');

    unCadeteEnLaCola(11, 1000);
    $segundo = unCadeteEnLaCola(22, 2000);
    unCadeteEnLaCola(33, 3000);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cola/{$segundo->id}/al-final")
        ->assertRedirect();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => expect(
            array_column($pagina->toArray()['props']['cola'], 'movil'),
        )->toBe([11, 33, 22]));
});

test('sending to the end does not change the state, only the place', function () {
    $enLaApp = unCadeteEnLaCola(22, 2000, Cadete::ESTADO_ACTIVO_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cola/{$enLaApp->id}/al-final");

    expect($enLaApp->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP);
});

test('somebody who is not in the queue cannot be sent to the end of it', function () {
    // ⚠️ El sistema viejo hacía `findOrFail` y escribía `orden_cola` sobre cualquier cadete.
    // Desde la pantalla no se llegaba, pero el endpoint estaba abierto con un id.
    $inactivo = Cadete::factory()->create([
        'numero_movil' => 77,
        'estado' => Cadete::ESTADO_INACTIVO,
        'orden_cola' => 1000,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/cola/{$inactivo->id}/al-final")
        ->assertRedirect()
        ->assertSessionHas('danger');

    expect($inactivo->fresh()->orden_cola)->toBe(1000);
});

test('clearing the queue leaves everybody inactive, and nobody else', function () {
    $enLaCola = unCadeteEnLaCola(11, 1000);
    $enLaApp = unCadeteEnLaCola(22, 2000, Cadete::ESTADO_ACTIVO_APP);
    $conPedido = unCadeteEnLaCola(44, 500, Cadete::ESTADO_CON_PEDIDO_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete('/panel/cadetes/cola')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($enLaCola->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO)
        ->and($enLaApp->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO)
        // ⚠️ El que tiene un pedido en la mano no está en la cola, así que vaciarla no lo
        // toca. Es lo que hace el panel viejo, que recorre `getActivos()`.
        ->and($conPedido->fresh()->estado)->toBe(Cadete::ESTADO_CON_PEDIDO_APP);
});

test('the screen and the writer agree on when the queue can be cleared', function () {
    // Una regla, dos lectores: la pantalla esconde el botón con la misma función que el
    // controlador consulta antes de escribir.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('porQueNoLimpiar', ColaDeCadetes::porQueNoLimpiar()));

    expect(ColaDeCadetes::porQueNoLimpiar())->toBe('No hay nadie en la cola.');

    unCadeteEnLaCola(11, 1000);

    expect(ColaDeCadetes::porQueNoLimpiar())->toBeNull();
});

test('clearing an empty queue is refused instead of saying it worked', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete('/panel/cadetes/cola')
        ->assertRedirect()
        ->assertSessionHas('danger');
});

test('a shop cannot touch the queue', function () {
    $cadete = unCadeteEnLaCola(11, 1000);
    $comercio = usuarioCon(Rol::RESTRINGIDO, Cliente::factory()->create());

    $this->actingAs($comercio)
        ->post("/panel/cadetes/cola/{$cadete->id}/al-final")
        ->assertForbidden();

    $this->actingAs($comercio)
        ->delete('/panel/cadetes/cola')
        ->assertForbidden();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_WEB);
});

test('the detail does not travel with the screen: the dialog asks for it', function () {
    // ⚠️ La primera versión lo mandaba en las props con `?cadete=302`, y el parámetro quedaba
    // en la URL al cerrar el diálogo: volver a abrir el mismo cadete no abría nada, porque la
    // prop llegaba igual y React no veía ningún cambio. Qué diálogo está abierto es estado de
    // la pantalla, no de la dirección. Y de paso el nombre de una persona deja de viajar en
    // cada refresco de una pantalla que está siempre abierta.
    unCadeteEnLaCola(11, 1000);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina->missing('detalle'));
});

test('the detail of a cadete comes as json, with what the dialog draws', function () {
    $cadete = Cadete::factory()->create([
        'numero_movil' => 11,
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'orden_cola' => 1000,
        'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
        'ubicacion_lat' => -26.81,
        'ubicacion_lon' => -65.21,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson("/panel/cadetes/{$cadete->id}/detalle")
        ->assertSuccessful()
        ->assertJsonPath('movil', 11)
        ->assertJsonPath('estado', 'Activo App')
        ->assertJsonPath('posicion', 1)
        ->assertJsonPath('vehiculo', 'Motocicleta')
        ->assertJsonPath('ubicacion.lat', -26.81)
        ->assertJsonPath('ubicacion.con_pedido', false)
        // Los dos motivos viajan con los datos: el diálogo no dibuja un botón que iba a
        // fallar, y son las mismas funciones que el controlador consulta antes de escribir.
        ->assertJsonPath('por_que_no_al_final', null)
        ->assertJsonPath('por_que_no_sacar', null);
});

test('the detail of somebody who is not in the app carries no position', function () {
    // ⚠️ Es la trampa de PANEL-18 otra vez: el mapa viejo exigía estado y no miraba las
    // coordenadas. Acá los dos casos dan null, y el diálogo dice «Cadete sin ubicación».
    $enLaWeb = unCadeteEnLaCola(22, 1000);
    $enLaWeb->update(['ubicacion_lat' => -26.81, 'ubicacion_lon' => -65.21]);

    $sinCoordenadas = Cadete::factory()->create([
        'numero_movil' => 33,
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'orden_cola' => 2000,
        'ubicacion_lat' => -26.81,
        'ubicacion_lon' => null,
    ]);

    foreach ([$enLaWeb, $sinCoordenadas] as $cadete) {
        $this->actingAs(usuarioCon(Rol::EMPLEADO))
            ->getJson("/panel/cadetes/{$cadete->id}/detalle")
            ->assertJsonPath('ubicacion', null);
    }
});

test('the detail says why a button is not there, instead of offering it', function () {
    $inactivo = Cadete::factory()->create([
        'numero_movil' => 77,
        'estado' => Cadete::ESTADO_INACTIVO,
        'orden_cola' => 1000,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->getJson("/panel/cadetes/{$inactivo->id}/detalle")
        ->assertJsonPath('por_que_no_al_final', 'El móvil 77 no está en la cola.')
        ->assertJsonPath('por_que_no_sacar', 'El móvil 77 no está en la cola.')
        ->assertJsonPath('posicion', null);
});

test('a shop cannot ask for the detail of a cadete', function () {
    $cadete = unCadeteEnLaCola(11, 1000);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, Cliente::factory()->create()))
        ->getJson("/panel/cadetes/{$cadete->id}/detalle")
        ->assertForbidden();
});

test('the quitar button takes one out of the queue and leaves the rest', function () {
    $sale = unCadeteEnLaCola(11, 1000);
    $queda = unCadeteEnLaCola(22, 2000, Cadete::ESTADO_ACTIVO_APP);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/cadetes/cola/{$sale->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($sale->fresh()->estado)->toBe(Cadete::ESTADO_INACTIVO)
        ->and($queda->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_APP);
});

test('somebody who is not in the queue cannot be taken out of it', function () {
    $inactivo = Cadete::factory()->create([
        'numero_movil' => 77,
        'estado' => Cadete::ESTADO_INACTIVO,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/cadetes/cola/{$inactivo->id}")
        ->assertRedirect()
        ->assertSessionHas('danger');
});

test('a shop cannot take anybody out of the queue', function () {
    $cadete = unCadeteEnLaCola(11, 1000);

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO, Cliente::factory()->create()))
        ->delete("/panel/cadetes/cola/{$cadete->id}")
        ->assertForbidden();

    expect($cadete->fresh()->estado)->toBe(Cadete::ESTADO_ACTIVO_WEB);
});

test('clearing the queue is not read as taking out a cadete called cola', function () {
    // ⚠️ `DELETE cadetes/cola` y `DELETE cadetes/cola/{cadete}` conviven, y si la primera se
    // registrara después, «cola» entraría como id por el comodín.
    unCadeteEnLaCola(11, 1000);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete('/panel/cadetes/cola')
        ->assertRedirect()
        ->assertSessionHas('success', 'Salió 1 cadete de la cola.');
});

test('the screen carries what the map of the dialog needs', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel')
        ->assertInertia(fn ($pagina) => $pagina
            // El centro sale de la pantalla de Mapa: está escrito en un solo lado.
            ->where('mapa.centro', MapaController::CENTRO)
            ->where('mapa.zoom', MapaController::ZOOM)
            ->has('mapa.clave'));
});
