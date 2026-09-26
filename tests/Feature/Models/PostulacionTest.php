<?php

use App\Models\Cadete;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Las postulaciones y sus cuatro imágenes. Dos de esas imágenes son el DNI de la
| persona, así que el disco es privado y nada de esto va en public/.
*/

test('a pending application is one with no cadete', function () {
    $pendiente = Postulacion::factory()->create();
    $contratada = Postulacion::factory()->contratada()->create();

    expect($pendiente->fueContratada())->toBeFalse()
        ->and($contratada->fueContratada())->toBeTrue()
        ->and(Postulacion::query()->pendientes()->pluck('id')->all())->toBe([$pendiente->id]);
});

test('the email is stored lowercase and trimmed, like a user email', function () {
    // 35 de las 725 tenían mayúsculas, y el CHECK de la columna no las admite.
    $postulacion = Postulacion::factory()->create(['email' => '  Juan.Gomez@Example.COM  ']);

    expect($postulacion->email)->toBe('juan.gomez@example.com')
        ->and($postulacion->fresh()->email)->toBe('juan.gomez@example.com');
});

test('the dni and the email cannot repeat', function () {
    Postulacion::factory()->create(['dni' => 30_111_222, 'email' => 'juan@example.com']);

    expect(fn () => DB::transaction(
        fn () => Postulacion::factory()->create(['dni' => 30_111_222])
    ))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(
        fn () => Postulacion::factory()->create(['email' => 'juan@example.com'])
    ))->toThrow(QueryException::class);
});

test('the full name puts the given name first, unlike a cadete', function () {
    // Acá es una persona presentándose, no un móvil en una planilla.
    $postulacion = Postulacion::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);

    expect($postulacion->nombre_completo)->toBe('Ana Pérez');
});

test('the four documents hang off the application, and two of them are an id card', function () {
    $postulacion = Postulacion::factory()->create();

    foreach (PostulacionDocumento::TIPOS as $tipo) {
        $postulacion->documentos()->create([
            'tipo' => $tipo,
            'ruta_archivo' => "postulacion-documentos/{$postulacion->id}/{$tipo}.jpg",
        ]);
    }

    expect($postulacion->documentos()->count())->toBe(4);

    $frente = $postulacion->documentos()->where('tipo', PostulacionDocumento::TIPO_DNI_FRENTE)->first();
    $foto = $postulacion->documentos()->where('tipo', PostulacionDocumento::TIPO_FOTO)->first();

    expect($frente->esDocumentoDeIdentidad())->toBeTrue()
        ->and($foto->esDocumentoDeIdentidad())->toBeFalse();
});

test('the same document type cannot be stored twice for one application', function () {
    $postulacion = Postulacion::factory()->create();

    $postulacion->documentos()->create([
        'tipo' => PostulacionDocumento::TIPO_FOTO,
        'ruta_archivo' => 'postulacion-documentos/1/foto.jpg',
    ]);

    expect(fn () => DB::transaction(fn () => $postulacion->documentos()->create([
        'tipo' => PostulacionDocumento::TIPO_FOTO,
        'ruta_archivo' => 'postulacion-documentos/1/foto.png',
    ])))->toThrow(QueryException::class);
});

test('a path that could climb out of its directory is refused by the database', function () {
    $postulacion = Postulacion::factory()->create();

    expect(fn () => DB::transaction(fn () => $postulacion->documentos()->create([
        'tipo' => PostulacionDocumento::TIPO_FOTO,
        'ruta_archivo' => '../../etc/passwd',
    ])))->toThrow(QueryException::class);
});

test('hiring links the application to its cadete', function () {
    $postulacion = Postulacion::factory()->create();
    $cadete = Cadete::factory()->create();

    $postulacion->update(['cadete_id' => $cadete->id]);

    expect($postulacion->fresh()->cadete->id)->toBe($cadete->id)
        ->and($cadete->postulacion->id)->toBe($postulacion->id);
});
