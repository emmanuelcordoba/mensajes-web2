<?php

use App\Etl\MapaDeIds;
use App\Etl\Origen;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/*
| El ETL lee MongoDB, que no está disponible en CI. Lo que se prueba acá es lo
| que no depende de él: la traducción de ids y fechas, y el mapa que sostiene
| todas las claves foráneas. Cargar de verdad se verifica corriendo el comando
| contra una copia restaurada (ETL-1).
*/

test('a mongo id is read from every shape the old system stores it in', function () {
    $oid = new ObjectId('64f2a1b2c3d4e5f6a7b8c9d0');

    expect(Origen::id($oid))->toBe('64f2a1b2c3d4e5f6a7b8c9d0')
        // json_decode leaves an ObjectId as ['$oid' => '...'].
        ->and(Origen::id(['$oid' => '64f2a1b2c3d4e5f6a7b8c9d0']))->toBe('64f2a1b2c3d4e5f6a7b8c9d0')
        // Foreign keys are plain strings in the old system (DATA-3).
        ->and(Origen::id('64f2a1b2c3d4e5f6a7b8c9d0'))->toBe('64f2a1b2c3d4e5f6a7b8c9d0')
        ->and(Origen::id('64F2A1B2C3D4E5F6A7B8C9D0'))->toBe('64f2a1b2c3d4e5f6a7b8c9d0');

    foreach ([null, '', 'no-es-un-id', 12345, '64f2a1b2c3d4e5f6a7b8c9', []] as $basura) {
        expect(Origen::id($basura))->toBeNull();
    }
});

test('a date keeps its milliseconds, which is why the columns are not timestamp(0)', function () {
    // 23:59:59.600 on the last day of a month: rounded to seconds it moves to
    // the next month, which is the reason for Schema::defaultTimePrecision(null).
    $filo = new UTCDateTime(strtotime('2026-01-31 23:59:59 UTC') * 1000 + 600);

    expect(Origen::fecha($filo))->toBe('2026-01-31 23:59:59.600+00')
        ->and(Origen::fecha(new UTCDateTime(0)))->toBe('1970-01-01 00:00:00.000+00')
        ->and(Origen::fecha(['$date' => ['$numberLong' => '1600000000123']]))->toBe('2020-09-13 12:26:40.123+00')
        ->and(Origen::fecha(null))->toBeNull()
        ->and(Origen::fecha('2020-09-13'))->toBeNull();
});

test('the id map answers what a document became, and says so only for that table', function () {
    MapaDeIds::crearSiFalta();

    MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 1, '64f2a1b2c3d4e5f6a7b8c9d1' => 2]);
    MapaDeIds::anotar('clientes', ['64f2a1b2c3d4e5f6a7b8c9d0' => 7]);

    // The same legacy id can exist in two collections and mean different rows.
    expect(MapaDeIds::id('roles', '64f2a1b2c3d4e5f6a7b8c9d0'))->toBe(1)
        ->and(MapaDeIds::id('clientes', '64f2a1b2c3d4e5f6a7b8c9d0'))->toBe(7)
        ->and(MapaDeIds::id('cadetes', '64f2a1b2c3d4e5f6a7b8c9d0'))->toBeNull()
        ->and(MapaDeIds::id('roles', null))->toBeNull()
        ->and(MapaDeIds::mapa('roles'))->toBe(['64f2a1b2c3d4e5f6a7b8c9d0' => 1, '64f2a1b2c3d4e5f6a7b8c9d1' => 2])
        ->and(MapaDeIds::cuantos('roles'))->toBe(2);
});

test('the id map refuses to record the same document twice', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 1]);

    // Running the ETL twice without --reiniciar would duplicate everything.
    expect(fn () => MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 9]))
        ->toThrow(Illuminate\Database\QueryException::class, 'SQLSTATE[23505]');
});

test('creating the id map is safe to repeat', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 1]);

    MapaDeIds::crearSiFalta();

    expect(MapaDeIds::cuantos('roles'))->toBe(1);
});
