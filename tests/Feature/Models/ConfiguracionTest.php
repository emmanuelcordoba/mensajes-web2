<?php

use App\Models\Configuracion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Las ocho configuraciones que la oficina edita. La tabla guarda «3000» y «30
| minutos» en la misma columna, y `tipo` dice cómo leerla.
*/

test('a numeric setting comes back as a number and a text one as text', function () {
    Configuracion::factory()->numero('COSTO_MINIMO', '1950')->create();
    Configuracion::factory()->texto('TIEMPO_DE_DEMORA', '30 minutos')->create();

    expect(Configuracion::valor('COSTO_MINIMO'))->toBe(1950.0)
        ->and(Configuracion::valor('TIEMPO_DE_DEMORA'))->toBe('30 minutos');
});

test('a missing setting names itself instead of failing on null', function () {
    // El modelo viejo hacía `$config->tipo` sobre el resultado de un first() que
    // podía ser null: una configuración faltante daba «Attempt to read property on
    // null» en medio de una cotización, sin decir cuál faltaba.
    expect(fn () => Configuracion::valor('NO_EXISTE'))
        ->toThrow(RuntimeException::class, 'No existe la configuración «NO_EXISTE»');
});

test('asking a text setting for a number says so instead of giving zero', function () {
    Configuracion::factory()->texto('TIEMPO_DE_DEMORA', '30 minutos')->create();

    // (float) '30 minutos' daría 30.0, que es un número plausible y equivocado.
    expect(fn () => Configuracion::numero('TIEMPO_DE_DEMORA'))
        ->toThrow(RuntimeException::class, 'es de tipo texto');
});

test('the database refuses a numeric setting whose value is not a plain number', function () {
    // Esto no lo validaba el sistema viejo, donde `valor` podía ser cualquier cosa.
    // Cada rechazo va en su propio savepoint: en PostgreSQL el primer fallo
    // abortaría la transacción del test y los cuatro siguientes fallarían por el
    // motivo equivocado.
    foreach (['1.9e3', '-250', '250,50', 'gratis', ''] as $invalido) {
        expect(fn () => DB::transaction(
            fn () => Configuracion::factory()->numero('COSTO_MINIMO', $invalido)->create()
        ))->toThrow(QueryException::class);
    }

    // Y acepta lo que sí es un número, con o sin decimales.
    expect(Configuracion::factory()->numero('COSTO_POR_KM', '250')->create()->valor)->toBe('250')
        ->and(Configuracion::factory()->numero('COSTO_GARANTIA', '0.18')->create()->valor)->toBe('0.18');
});

test('a text setting may hold anything, including nothing', function () {
    expect(Configuracion::factory()->texto('LIBRE', 'cualquier cosa')->create()->valor)
        ->toBe('cualquier cosa');

    $vacia = Configuracion::factory()->create(['tipo' => Configuracion::TIPO_TEXTO, 'valor' => null]);

    expect($vacia->valor)->toBeNull();
});

test('the name is unique, because the code looks settings up by name', function () {
    Configuracion::factory()->create(['nombre' => 'COSTO_MINIMO']);

    expect(fn () => DB::transaction(
        fn () => Configuracion::factory()->create(['nombre' => 'COSTO_MINIMO'])
    ))->toThrow(QueryException::class);
});

test('only the two known types are accepted', function () {
    expect(fn () => DB::transaction(
        fn () => Configuracion::factory()->create(['tipo' => 'booleano'])
    ))->toThrow(QueryException::class);
});
