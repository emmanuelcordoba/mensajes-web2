<?php

use App\Models\Configuracion;
use App\Services\Cotizador;

/*
| La tarifa.
|
| Los números esperados NO están inventados: salen de correr la fórmula del sistema viejo
| —`App\Pedido::cotizar()`, en coma flotante— con las seis configuraciones que tiene
| producción hoy. Si alguno cambia, cambió la tarifa, y eso es plata.
*/

/** Las configuraciones de producción, medidas el 2026-10-06. */
function laTarifaDeProduccion(): void
{
    foreach ([
        'COSTO_MINIMO' => '3000',
        'COSTO_POR_KM' => '900',
        'COSTO_POR_KM_5KM' => '1000',
        'COSTO_RETORNO' => '5',
        'COSTO_RETORNO_5KM' => '9',
        'COSTO_GARANTIA' => '9',
    ] as $nombre => $valor) {
        Configuracion::query()->updateOrCreate(
            ['nombre' => $nombre],
            ['titulo' => $nombre, 'tipo' => Configuracion::TIPO_NUMERO, 'valor' => $valor],
        );
    }
}

beforeEach(function () {
    laTarifaDeProduccion();
});

dataset('viajes', [
    // [metros, retorno, garantía, lo que cobra el sistema viejo]
    'menos de un kilómetro queda en el mínimo' => [0, false, null, '3000.00'],
    '999 metros son cero kilómetros' => [999, false, null, '3000.00'],
    'un kilómetro no llega al mínimo' => [1000, false, null, '3000.00'],
    'tres kilómetros tampoco' => [3000, false, null, '3000.00'],
    'cuatro kilómetros ya lo pasan' => [4999, false, null, '3600.00'],
    'a los cinco rige la tarifa larga' => [5000, false, null, '5000.00'],
    'siete kilómetros' => [7000, false, null, '7000.00'],
    'los metros de más no se cobran' => [12500, false, null, '12000.00'],
    'el retorno se suma sobre el mínimo' => [3000, true, null, '3150.00'],
    'el retorno corto es del 5%' => [4999, true, null, '3780.00'],
    'el retorno largo es del 9%' => [5000, true, null, '5450.00'],
    'retorno largo sobre siete kilómetros' => [7000, true, null, '7630.00'],
    'mil de garantía no suman' => [0, false, '1000', '3000.00'],
    'un peso más ya suma un paso' => [0, false, '1001', '3009.00'],
    'mil novecientos sigue siendo un paso' => [0, false, '1999', '3009.00'],
    'dos mil son dos pasos' => [0, false, '2000', '3018.00'],
    'cincuenta mil son cincuenta pasos' => [0, false, '50000', '3450.00'],
    'todo junto' => [7000, true, '50000', '8080.00'],
]);

test('cobra lo mismo que el sistema viejo', function (int $metros, bool $retorno, ?string $garantia, string $esperado) {
    expect((new Cotizador)->cotizar($metros, $retorno, $garantia))->toBe($esperado);
})->with('viajes');

test('un metro separa las dos tarifas, y es un salto de 1.400 pesos', function () {
    // ⚠️ No es un defecto que se arregle acá: es la tarifa que cobra la empresa, y está
    // escrita así desde siempre. Queda fijada para que nadie la "redondee" sin darse cuenta
    // de que el salto existe.
    $cotizador = new Cotizador;

    expect($cotizador->cotizar(4999))->toBe('3600.00')
        ->and($cotizador->cotizar(5000))->toBe('5000.00');
});

test('los kilómetros se truncan y no se redondean', function () {
    $cotizador = new Cotizador;

    // 5.999 metros son 5 kilómetros, no 6: el sistema viejo hace floor().
    expect($cotizador->cotizar(5999))->toBe('5000.00')
        ->and($cotizador->cotizar(6000))->toBe('6000.00');
});

test('una garantía vacía o no numérica no suma nada', function () {
    $cotizador = new Cotizador;

    expect($cotizador->cotizar(7000, false, null))->toBe('7000.00')
        ->and($cotizador->cotizar(7000, false, ''))->toBe('7000.00')
        ->and($cotizador->cotizar(7000, false, 'cualquier cosa'))->toBe('7000.00');
});

test('una distancia negativa no descuenta plata', function () {
    // No llega de Google, pero la ruta recibe un número de afuera. Sin el max(0) de por
    // medio, los kilómetros negativos restaban del mínimo.
    expect((new Cotizador)->cotizar(-5000))->toBe('3000.00');
});

test('dice qué configuración falta en vez de cotizar cualquier cosa', function () {
    // ⚠️ En el sistema viejo una clave ausente daba «Attempt to read property on null» en
    // medio de la cuenta, y en el peor caso cotizaba 0.
    Configuracion::query()->where('nombre', 'COSTO_POR_KM')->delete();

    expect(fn () => (new Cotizador)->cotizar(3000))
        ->toThrow(RuntimeException::class, 'COSTO_POR_KM');
});

test('la garantía sólo pide su costo cuando de verdad suma', function () {
    // Sin COSTO_GARANTIA cargado, un viaje sin garantía tiene que poder cotizarse igual:
    // es la configuración que menos se toca y no tiene por qué frenar al resto.
    Configuracion::query()->where('nombre', 'COSTO_GARANTIA')->delete();

    expect((new Cotizador)->cotizar(7000))->toBe('7000.00');

    expect(fn () => (new Cotizador)->cotizar(7000, false, '5000'))
        ->toThrow(RuntimeException::class, 'COSTO_GARANTIA');
});

test('las cuentas no pasan por punto flotante', function () {
    // Con un costo por kilómetro de muchos decimales, la cuenta vieja arrastra el error de
    // la coma flotante y ésta no. 7 × 1000.005 = 7000.035, que la columna guarda 7000.04.
    Configuracion::query()->where('nombre', 'COSTO_POR_KM_5KM')
        ->update(['valor' => '1000.005']);

    expect((new Cotizador)->cotizar(7000))->toBe('7000.04');
});
