<?php

use App\Models\Cadete;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\Rol;
use App\Models\User;
use App\Services\DocumentosDePostulacion;
use App\Services\FotosDePerfil;
use App\Services\ImagenesDeLaApp;
use App\Support\FechaDeNacimiento;
use App\Support\ImagenEnDataUrl;
use Illuminate\Support\Facades\Storage;

/*
| Postularse como cadete desde la app.
|
| ⚠️ **Es el único endpoint público que escribe.** El login no escribe y el reseteo necesita
| un código: ésta es la única puerta por la que un desconocido deja una fila y cuatro
| archivos en el servidor. Dos de esos archivos son el DNI de una persona y no están en
| ningún otro respaldo (DATA-9).
|
| ⚠️ Y el 422 va en la forma que la app lee: `postulacion.page.ts` hace
| `if (err.error.code == 422) { this.respuesta_messages = err.error.error }`.
*/

function unaImagen(string $tipo = 'image/jpeg', int $bytes = 2_048): string
{
    return 'data:'.$tipo.';base64,'.base64_encode(str_repeat('x', $bytes));
}

/** Los 17 campos que manda el formulario de la app. */
function elFormularioDePostulacion(array $cambios = []): array
{
    $imagenes = [];

    foreach (PostulacionDocumento::TIPOS as $tipo) {
        $imagenes[$tipo] = unaImagen();
    }

    return array_merge([
        'tipo_vehiculo' => Cadete::VEHICULO_BICICLETA,
        'apellidos' => 'Pérez',
        'nombres' => 'Juan',
        'email' => 'postulante@example.test',
        'dni' => '30111222',
        'fecha_nacimiento' => '1995-04-10',
        'direccion' => 'Balcarce 100',
        'telefono' => '3815551234',
        'pregunta_experiencia_app' => Postulacion::SI,
        'pregunta_tiene_celular' => Postulacion::SI,
        'pregunta_equipo_en_condiciones' => Postulacion::SI,
        'pregunta_tiene_datos' => Postulacion::NO,
        'recomendado_por' => 'Un amigo',
        // La app lo manda SIEMPRE en null, y acá no hay dónde guardarlo.
        'fcm_token' => null,
    ], $imagenes, $cambios);
}

function unCadeteCualquiera(array $extra = [], string $email = 'cadete-post@example.test'): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
    $usuario = User::factory()->create(['rol_id' => $rol->id, 'email' => $email]);

    return Cadete::factory()->create(array_merge([
        'user_id' => $usuario->id,
        'numero_movil' => 99,
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
    ], $extra));
}

beforeEach(function () {
    Storage::fake(ImagenesDeLaApp::disco());
});

// ----------------------------------------------------------------- el alta

test('somebody applies from the app and the row lands with its four images', function () {
    $this->postJson('/api/postulacion', elFormularioDePostulacion())
        ->assertOk()
        // El sistema viejo contesta exactamente esto, y la app no lee nada de la respuesta.
        ->assertExactJson(['ok' => true]);

    $postulacion = Postulacion::query()->sole();

    expect($postulacion->nombres)->toBe('Juan')
        ->and($postulacion->apellidos)->toBe('Pérez')
        // ⚠️ Entero: en el sistema viejo el mismo documento convivía como texto y como
        // número en esta columna (PANEL-15, DATA-4).
        ->and($postulacion->dni)->toBe(30111222)
        ->and($postulacion->email)->toBe('postulante@example.test')
        ->and($postulacion->tipo_vehiculo)->toBe(Cadete::VEHICULO_BICICLETA)
        ->and($postulacion->recomendado_por)->toBe('Un amigo')
        ->and($postulacion->cadete_id)->toBeNull();

    // ⚠️ Cuatro FILAS con una ruta, y no cuatro columnas con el base64 adentro, que es como
    // lo guarda el sistema viejo (PERF-2).
    $documentos = $postulacion->documentos;

    expect($documentos)->toHaveCount(4)
        ->and($documentos->pluck('tipo')->sort()->values()->all())
        ->toBe(collect(PostulacionDocumento::TIPOS)->sort()->values()->all());

    foreach ($documentos as $documento) {
        Storage::disk(ImagenesDeLaApp::disco())->assertExists($documento->ruta_archivo);
        expect($documento->ruta_archivo)->toStartWith(DocumentosDePostulacion::CARPETA.'/');
    }
});

test('the questionnaire is stored as it was answered', function () {
    $this->postJson('/api/postulacion', elFormularioDePostulacion())->assertOk();

    $postulacion = Postulacion::query()->sole();

    expect($postulacion->pregunta_experiencia_app)->toBe(Postulacion::SI)
        ->and($postulacion->pregunta_tiene_datos)->toBe(Postulacion::NO);
});

test('the fcm token the app always sends has nowhere to go and is ignored', function () {
    $this->postJson('/api/postulacion', elFormularioDePostulacion(['fcm_token' => 'un-token']))
        ->assertOk();

    expect(Postulacion::query()->sole()->getAttributes())->not->toHaveKey('fcm_token');
});

test('applying needs no token at all', function () {
    // Es público: quien se postula todavía no tiene cuenta.
    $this->postJson('/api/postulacion', elFormularioDePostulacion())->assertOk();
});

// -------------------------------------------------------------- lo que se niega

test('the validation errors come in the shape the app paints under each field', function () {
    $respuesta = $this->postJson('/api/postulacion', elFormularioDePostulacion([
        'nombres' => '',
        'dni' => 'veinte',
    ]))->assertStatus(422);

    expect($respuesta->json('code'))->toBe(422)
        ->and($respuesta->json('error.nombres'))->toBeArray()->not->toBeEmpty()
        ->and($respuesta->json('error.dni.0'))->toBeString()
        ->and($respuesta->json())->not->toHaveKey('errors');

    expect(Postulacion::query()->count())->toBe(0);
});

test('a dni or an email that already exists anywhere is refused', function () {
    unCadeteCualquiera(['dni' => 29888777], 'ocupado@example.test');

    $porElDni = $this->postJson('/api/postulacion', elFormularioDePostulacion(['dni' => '29888777']))
        ->assertStatus(422);

    expect($porElDni->json('error.dni.0'))->toBe('Ese DNI ya está cargado, en otra postulación o en un cadete.');

    $porElEmail = $this->postJson('/api/postulacion', elFormularioDePostulacion(['email' => 'ocupado@example.test']))
        ->assertStatus(422);

    expect($porElEmail->json('error.email.0'))->toBe('Ese email ya está cargado, en otra postulación o en una cuenta.');
});

test('an email that only differs in case is the same email', function () {
    unCadeteCualquiera([], 'ocupado@example.test');

    // ⚠️ Sin normalizar antes de validar, `unique` no lo encuentra y la escritura revienta
    // contra el índice. Ver DATA-7.
    $this->postJson('/api/postulacion', elFormularioDePostulacion(['email' => 'Ocupado@Example.Test']))
        ->assertStatus(422);
});

test('a second application with the same dni is refused', function () {
    $this->postJson('/api/postulacion', elFormularioDePostulacion())->assertOk();

    $this->postJson('/api/postulacion', elFormularioDePostulacion(['email' => 'otro@example.test']))
        ->assertStatus(422);

    expect(Postulacion::query()->count())->toBe(1);
});

test('an impossible birth date is refused, and the old system let it through', function () {
    // ⚠️ Allá acá es `required|date` a secas, así que por esta puerta entraban las fechas
    // imposibles que el panel sí frena: de los 41 casos de DATA-10, 21 son postulaciones.
    $respuesta = $this->postJson('/api/postulacion', elFormularioDePostulacion([
        'fecha_nacimiento' => '0190-01-01',
    ]))->assertStatus(422);

    expect($respuesta->json('error.fecha_nacimiento.0'))
        ->toBe('La fecha de nacimiento no puede ser de hace más de '.FechaDeNacimiento::ANOS_MAXIMOS.' años.');
});

test('only a B or an M is a vehicle, and only S or N answer a question', function () {
    $this->postJson('/api/postulacion', elFormularioDePostulacion(['tipo_vehiculo' => 'Z']))
        ->assertStatus(422);

    $respuesta = $this->postJson('/api/postulacion', elFormularioDePostulacion([
        'pregunta_tiene_celular' => 'tal vez',
    ]))->assertStatus(422);

    expect($respuesta->json('error.pregunta_tiene_celular.0'))->toBe('Por favor complete el cuestionario.');
});

test('each of the four images is required, with its own words', function () {
    foreach (PostulacionDocumento::TIPOS as $tipo) {
        $respuesta = $this->postJson('/api/postulacion', elFormularioDePostulacion([$tipo => null]))
            ->assertStatus(422);

        expect($respuesta->json('error.'.$tipo.'.0'))->toBeString()->not->toBeEmpty();
    }

    expect(Postulacion::query()->count())->toBe(0);
});

test('an image that is not an image is a 422 with the reason, not a 500', function () {
    $respuesta = $this->postJson('/api/postulacion', elFormularioDePostulacion([
        'dni_frente' => 'data:application/pdf;base64,'.base64_encode('no es una imagen'),
    ]))->assertStatus(422);

    expect($respuesta->json('error.dni_frente.0'))->toContain('application/pdf');
});

test('an image past the cap is refused, and the reason says the size', function () {
    $respuesta = $this->postJson('/api/postulacion', elFormularioDePostulacion([
        'dni_dorso' => unaImagen('image/jpeg', DocumentosDePostulacion::BYTES_MAXIMOS + 1),
    ]))->assertStatus(422);

    expect($respuesta->json('error.dni_dorso.0'))->toContain('demasiado grande')
        ->and($respuesta->json('error.dni_dorso.0'))->toContain('4,0 MB');
});

test('the cap is above every image that was ever migrated', function () {
    // Medido sobre las 2.900 imágenes migradas: mediana 656 KB, p99 1,8 MB, la mayor 2,7 MB.
    expect(DocumentosDePostulacion::BYTES_MAXIMOS)->toBeGreaterThan(2_764 * 1024);
});

test('nothing is written when one of the four images is broken', function () {
    // El orden importa: si la fila se escribiera antes de validar, quedaría una postulación
    // sin documentos y nadie podría contratarla ni saber por qué.
    $this->postJson('/api/postulacion', elFormularioDePostulacion([
        'boleta_de_servicio' => 'data:image/jpeg;base64,no-es-base64-valido-$$$',
    ]))->assertStatus(422);

    expect(Postulacion::query()->count())->toBe(0)
        ->and(PostulacionDocumento::query()->count())->toBe(0)
        ->and(Storage::disk(ImagenesDeLaApp::disco())->allFiles())->toBe([]);
});

// -------------------------------------------- una regla, dos lectores

test('what the validation refuses and what the writer refuses are the same thing', function () {
    $roto = 'data:application/pdf;base64,'.base64_encode('no');
    $grande = unaImagen('image/jpeg', DocumentosDePostulacion::BYTES_MAXIMOS + 1);
    $buena = unaImagen();

    expect(DocumentosDePostulacion::porQueNoSirve($roto))->toBeString()
        ->and(DocumentosDePostulacion::porQueNoSirve($grande))->toBeString()
        ->and(DocumentosDePostulacion::porQueNoSirve($buena))->toBeNull();
});

test('the profile photo and the application images read a data url the same way', function () {
    // ⚠️ Era la misma regla escrita dos veces. Ahora las dos preguntan a
    // `ImagenEnDataUrl`, y lo único propio de cada una es cuánto puede pesar.
    expect(FotosDePerfil::esFotoNueva('data:image/jpeg;base64,x'))->toBeTrue()
        ->and(ImagenEnDataUrl::esUnaImagen('data:image/jpeg;base64,x'))->toBeTrue()
        ->and(ImagenEnDataUrl::esUnaImagen('https://example.test/foto.jpg'))->toBeFalse();

    // Y el tope sí es distinto: la foto de perfil sale 500x500 y el DNI 2000 de ancho.
    expect(DocumentosDePostulacion::BYTES_MAXIMOS)
        ->toBeGreaterThan(FotosDePerfil::BYTES_MAXIMOS);
});
