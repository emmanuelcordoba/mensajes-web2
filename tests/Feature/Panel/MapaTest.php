<?php

use App\Models\Cadete;
use App\Models\Rol;

/*
| El mapa de los cadetes en la app.
|
| Lo que esta pantalla tiene que garantizar es PANEL-18: que al mapa no llegue un cadete
| sin posición. El endpoint viejo filtraba con `whereNotNull('estado', 'ubicacion_lat')`, y
| el segundo argumento de `whereNotNull` es el booleano y no otra columna: exigía estado y
| no miraba las coordenadas.
*/

test('an employee reaches the map and a shop does not', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mapa')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('mapa/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/mapa')
        ->assertForbidden();
});

test('only the ones in the app with a position reach the map', function () {
    // ⚠️ PANEL-18. Los cuatro casos que el filtro viejo dejaba pasar o dejaba afuera.
    $enLaApp = Cadete::factory()->create([
        'numero_movil' => 11,
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'ubicacion_lat' => -26.81,
        'ubicacion_lon' => -65.21,
    ]);

    Cadete::factory()->create([
        'numero_movil' => 22,
        'estado' => Cadete::ESTADO_CON_PEDIDO_APP,
        'ubicacion_lat' => -26.82,
        'ubicacion_lon' => -65.22,
    ]);

    // En la app, pero sin posición: es el que el filtro viejo dejaba pasar, y con el que
    // el mapa armaba el marcador con `parseFloat(null)`.
    Cadete::factory()->create([
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'ubicacion_lat' => null,
        'ubicacion_lon' => null,
    ]);

    // Con sólo una de las dos coordenadas: lo mismo.
    Cadete::factory()->create([
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'ubicacion_lat' => -26.83,
        'ubicacion_lon' => null,
    ]);

    // Con posición, pero no está en la app: trabajando desde la web, o parado.
    Cadete::factory()->create([
        'estado' => Cadete::ESTADO_ACTIVO_WEB,
        'ubicacion_lat' => -26.84,
        'ubicacion_lon' => -65.24,
    ]);
    Cadete::factory()->create([
        'estado' => Cadete::ESTADO_INACTIVO,
        'ubicacion_lat' => -26.85,
        'ubicacion_lon' => -65.25,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mapa')
        ->assertInertia(function ($pagina) use ($enLaApp) {
            $enElMapa = $pagina->toArray()['props']['cadetes'];

            expect($enElMapa)->toHaveCount(2)
                ->and($enElMapa[0]['movil'])->toBe($enLaApp->numero_movil)
                ->and($enElMapa[0]['con_pedido'])->toBeFalse()
                ->and($enElMapa[1]['con_pedido'])->toBeTrue();
        });
});

test('a cadete given up never reaches the map, even if it is in the app', function () {
    $cadete = Cadete::factory()->create([
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'ubicacion_lat' => -26.81,
        'ubicacion_lon' => -65.21,
    ]);

    $cadete->delete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mapa')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['cadetes'],
        )->toHaveCount(0));
});

test('the map sends the mobile number and the position, and not the name', function () {
    // ⚠️ Para ver dónde está un móvil alcanza con su número, y esta pantalla suele quedar
    // abierta en una oficina. Es además lo que mandaba el endpoint viejo.
    Cadete::factory()->create([
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'ubicacion_lat' => -26.81,
        'ubicacion_lon' => -65.21,
        'nombres' => 'Juan',
        'apellidos' => 'Pérez',
        'dni' => 30111222,
        'fcm_token' => 'un-token',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mapa')
        ->assertInertia(function ($pagina) {
            $fila = $pagina->toArray()['props']['cadetes'][0];

            expect(array_keys($fila))->toBe(['movil', 'lat', 'lon', 'vehiculo', 'con_pedido'])
                ->and($fila['lat'])->toBeFloat();
        });
});

test('the screen opens without the key, and says what is missing', function () {
    // Sin clave no hay mapa: se dice, en vez de dibujar uno roto. Es una instalación a
    // medio configurar, no algo que se arregle desde la pantalla.
    config(['services.google.maps_key' => null]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mapa')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->where('clave', null));
});

test('the map opens on the city where the company works', function () {
    config(['services.google.maps_key' => 'una-clave']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/mapa')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('centro.lat', -26.8083)
            ->where('centro.lng', -65.2176)
            ->where('zoom', 13)
            ->where('clave', 'una-clave'));
});
