<?php

use App\Models\Configuracion;
use App\Models\Rol;

/*
| Los parámetros del negocio.
|
| Lo que se cambia acá se lee al cotizar un pedido y al cobrarle al cadete. En el sistema
| viejo `valor` era texto libre: un texto valía 0 al leerlo con floatval() y un negativo
| restaba. Acá la columna tiene un CHECK, y el validador exige lo mismo que el CHECK para
| que el error llegue como mensaje y no como un 500.
*/

function configuracion(string $nombre, string $tipo = Configuracion::TIPO_NUMERO, ?string $valor = '100'): Configuracion
{
    return Configuracion::query()->create([
        'nombre' => $nombre,
        'titulo' => $nombre,
        'tipo' => $tipo,
        'valor' => $valor,
    ]);
}

test('the office reaches the screen and a shop does not', function () {
    configuracion('COSTO_MINIMO');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/configuraciones')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->component('configuraciones/index')
            ->where('configuraciones.0.titulo', 'COSTO_MINIMO')
        );

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/configuraciones')
        ->assertForbidden();
});

test('the screen shows them in the order they are read when quoting', function () {
    // No el orden de la base: el de la lista, que es el de la cuenta.
    configuracion('COSTO_GARANTIA');
    configuracion('COSTO_MINIMO');
    configuracion('COSTO_POR_KM');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/configuraciones')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('configuraciones.0.titulo', 'COSTO_MINIMO')
            ->where('configuraciones.1.titulo', 'COSTO_POR_KM')
            ->where('configuraciones.2.titulo', 'COSTO_GARANTIA')
        );
});

test('what the column check refuses, the form refuses first', function (string $valor) {
    // ⚠️ `numeric|min:0` de Laravel deja pasar los cuatro, y el CHECK de la columna los
    // rechaza: el resultado sería un 500 sin explicación. Ver PANEL-16.
    $config = configuracion('COSTO_MINIMO');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/configuraciones/{$config->id}", ['valor' => $valor])
        ->assertSessionHasErrors('valor');

    expect($config->refresh()->valor)->toBe('100');
})->with([
    'notación científica' => '1e3',
    'negativo' => '-50',
    'sin parte entera' => '.5',
    'con punto colgando' => '1.',
    'un texto' => 'gratis',
]);

test('a plain number and one with decimals do get in', function (string $valor) {
    $config = configuracion('COSTO_MINIMO');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/configuraciones/{$config->id}", ['valor' => $valor])
        ->assertSessionHasNoErrors();

    expect($config->refresh()->valor)->toBe($valor);
})->with([
    'entero' => '3000',
    'con decimales' => '1250.50',
    'cero' => '0',
]);

test('the saldo percentage keeps its range', function () {
    // El mismo rango que exige la pantalla de cobranza por saldo.
    $config = configuracion('COBRANZA_SALDO_PORCENTAJE', valor: '18');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/configuraciones/{$config->id}", ['valor' => '120'])
        ->assertSessionHasErrors('valor');

    expect($config->refresh()->valor)->toBe('18');
});

test('the delay can be left unset, which is a valid state', function () {
    // ⚠️ Es la única que acepta vacío, y se guarda como null y no como cadena vacía: el
    // CHECK rechaza la cadena vacía en las numéricas, y en las de texto «sin indicar» es
    // la ausencia de valor.
    $config = configuracion('TIEMPO_DE_DEMORA', Configuracion::TIPO_TEXTO, '30 minutos');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/configuraciones/{$config->id}", ['valor' => ''])
        ->assertSessionHasNoErrors();

    expect($config->refresh()->valor)->toBeNull();
});

test('the delay only accepts one of the times on the list', function () {
    $config = configuracion('TIEMPO_DE_DEMORA', Configuracion::TIPO_TEXTO, '30 minutos');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/configuraciones/{$config->id}", ['valor' => '7 horas'])
        ->assertSessionHasErrors('valor');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/configuraciones/{$config->id}", ['valor' => '45 minutos'])
        ->assertSessionHasNoErrors();

    expect($config->refresh()->valor)->toBe('45 minutos');
});

test('the delay travels to the topbar only for whoever can change it', function () {
    configuracion('TIEMPO_DE_DEMORA', Configuracion::TIPO_TEXTO, '30 minutos');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('tiempoDeDemora.valor', '30 minutos')
            ->where('tiempoDeDemora.opciones', Configuracion::TIEMPOS_DE_DEMORA)
        );

    // Un comercio no lo recibe: no se esconde con CSS, no viaja.
    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/pedidos')
        ->assertInertia(fn ($pagina) => $pagina->where('tiempoDeDemora', null));
});

test('a shop cannot change it either, even posting straight at the route', function () {
    $config = configuracion('TIEMPO_DE_DEMORA', Configuracion::TIPO_TEXTO, '30 minutos');

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->patch("/panel/configuraciones/{$config->id}", ['valor' => '15 minutos'])
        ->assertForbidden();

    expect($config->refresh()->valor)->toBe('30 minutos');
});
