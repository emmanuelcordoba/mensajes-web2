<?php

use App\Models\Cadete;
use App\Models\ErrorLog;
use App\Models\HorarioAtencion;
use App\Models\MontoSemanal;
use App\Models\PublicidadApp;
use App\Models\Rol;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Las tablas chicas: roles, horario de atención, montos semanales, publicidades y la
| telemetría. Son pocas filas y casi ninguna lógica, pero cada una tiene una
| restricción que el sistema viejo no tenía.
*/

test('a role name cannot repeat', function () {
    // Hay tres roles repetidos en producción, con cero usuarios cada uno, y se borran
    // antes de migrar. Ver DATA-6.
    Rol::factory()->llamado('admin')->create();

    expect(fn () => DB::transaction(fn () => Rol::factory()->llamado('admin')->create()))
        ->toThrow(QueryException::class);
});

test('there is one weekly amount per vehicle and no more', function () {
    MontoSemanal::factory()->create(['monto' => '12000.00']);
    MontoSemanal::factory()->paraBicicleta('8000.00')->create();

    expect(MontoSemanal::para(Cadete::VEHICULO_MOTOCICLETA))->toBe('12000.00')
        ->and(MontoSemanal::para(Cadete::VEHICULO_BICICLETA))->toBe('8000.00');

    expect(fn () => DB::transaction(fn () => MontoSemanal::factory()->create()))
        ->toThrow(QueryException::class);

    // Un vehículo que no existe no tiene monto, en vez de devolver cero.
    expect(MontoSemanal::para('A'))->toBeNull();
});

test('the weekly amount keeps its cents', function () {
    MontoSemanal::factory()->create(['monto' => '12345.67']);

    expect(MontoSemanal::para(Cadete::VEHICULO_MOTOCICLETA))->toBeString()->toBe('12345.67');
});

test('opening hours answer for a normal day', function () {
    $horario = HorarioAtencion::factory()->create();

    expect($horario->estaAbierto(Carbon::parse('2026-09-26 12:00:00')))->toBeTrue()
        ->and($horario->estaAbierto(Carbon::parse('2026-09-26 08:00:00')))->toBeTrue()
        ->and($horario->estaAbierto(Carbon::parse('2026-09-26 21:00:00')))->toBeTrue()
        ->and($horario->estaAbierto(Carbon::parse('2026-09-26 07:59:59')))->toBeFalse()
        ->and($horario->estaAbierto(Carbon::parse('2026-09-26 23:00:00')))->toBeFalse();
});

test('opening hours that cross midnight work too', function () {
    // Hoy no hay ninguno así, pero el esquema no lo impide y cambiar el horario es una
    // pantalla del panel.
    $horario = HorarioAtencion::factory()->cruzandoLaMedianoche()->create();

    expect($horario->estaAbierto(Carbon::parse('2026-09-26 23:30:00')))->toBeTrue()
        ->and($horario->estaAbierto(Carbon::parse('2026-09-26 01:00:00')))->toBeTrue()
        ->and($horario->estaAbierto(Carbon::parse('2026-09-26 12:00:00')))->toBeFalse();
});

test('there is one row of opening hours, and actual finds it or nothing', function () {
    expect(HorarioAtencion::actual())->toBeNull();

    $horario = HorarioAtencion::factory()->create();

    expect(HorarioAtencion::actual()->id)->toBe($horario->id);
});

test('an advert is visible by default, and only the visible ones are served', function () {
    $visible = PublicidadApp::factory()->create();
    $oculta = PublicidadApp::factory()->oculta()->create();

    // DEFAULT TRUE en la tabla: sin el trait volvería en NULL.
    expect($visible->visible)->toBeTrue()
        ->and($oculta->visible)->toBeFalse()
        ->and(PublicidadApp::query()->visibles()->pluck('id')->all())->toBe([$visible->id]);
});

test('an advert holds its image in another table, one per advert', function () {
    $publicidad = PublicidadApp::factory()->create();

    $publicidad->imagen()->create(['ruta_archivo' => 'publicidad-app-imagenes/1.png']);

    expect($publicidad->imagen->ruta_archivo)->toBe('publicidad-app-imagenes/1.png');

    // Una sola: publicidad_id es UNIQUE.
    expect(fn () => DB::transaction(
        fn () => $publicidad->imagen()->create(['ruta_archivo' => 'publicidad-app-imagenes/1.jpg'])
    ))->toThrow(QueryException::class);
});

test('telemetry takes whatever the apps send, including invalid json in extra', function () {
    // `extra` queda como TEXT y no se castea a array, al revés que en el modelo viejo:
    // un cast que falla al leer convierte un error ajeno en un error propio.
    $log = ErrorLog::query()->create([
        'message' => 'boom',
        'source' => ErrorLog::CADETE_APP,
        'extra' => 'esto no es json {',
        'user_id' => 999_999,
        'user_email' => 'alguien@example.com',
    ]);

    expect($log->fresh()->extra)->toBe('esto no es json {')
        // Y el user_id no tiene clave foránea a propósito: 999.999 no existe y entra.
        ->and($log->fresh()->user_id)->toBe(999_999);
});
