<?php

use App\Support\NumeroBuscado;

/*
| El número que escribió quien busca.
|
| Lo que esto tiene que garantizar: que un número más grande que el máximo de una columna
| `integer` no llegue nunca a la consulta. Sobre Postgres eso no devuelve «nada», hace
| fallar la consulta entera con SQLSTATE 22003, y una caja de búsqueda es donde cualquiera
| pega un CUIT sin pensarlo.
*/

test('a dni sized like a dni gets through', function () {
    expect(NumeroBuscado::deTexto('26267164'))->toBe(26267164);
});

test('the biggest one an integer column holds gets through', function () {
    expect(NumeroBuscado::deTexto('2147483647'))->toBe(2147483647);
});

test('one bigger than the column does not', function (string $texto) {
    // ⚠️ Éste es el 500: un CUIT, un teléfono con característica, o un DNI con un dígito
    // de más. Antes llegaban a la consulta y Postgres la rechazaba.
    expect(NumeroBuscado::deTexto($texto))->toBeNull();
})->with([
    'uno más que el máximo' => '2147483648',
    'un CUIT' => '20262671649',
    'un teléfono' => '3814567890',
    // Más grande que PHP_INT_MAX, donde `(int)` satura en vez de desbordar: si se colara
    // por acá entraría como 9223372036854775807 y sería el mismo error.
    'absurdamente grande' => '99999999999999999999999999',
]);

test('what is not all digits does not either', function (string $texto) {
    expect(NumeroBuscado::deTexto($texto))->toBeNull();
})->with([
    'un nombre' => 'juan',
    // ⚠️ `(int) 'juan'` da 0, y así el panel viejo traía al móvil 0 y al cliente 0.
    'vacío' => '',
    'con puntos, como se escribe un DNI' => '26.267.164',
    'con espacios' => ' 26267164 ',
    'negativo' => '-26267164',
    'decimal' => '26267164.5',
]);

test('leading zeros are just zeros', function () {
    expect(NumeroBuscado::deTexto('0026267164'))->toBe(26267164)
        ->and(NumeroBuscado::deTexto('0'))->toBe(0);
});
