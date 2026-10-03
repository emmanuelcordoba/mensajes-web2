<?php

use App\Models\PublicidadApp;
use App\Models\PublicidadAppImagen;
use App\Models\Rol;
use App\Services\ImagenesDeLaApp;
use App\Support\RutaDeArchivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| El carrusel de publicidades de la app.
|
| Lo que distingue a esta pantalla es que escribe archivos. El disco es privado —en el
| mismo están los documentos de identidad de las postulaciones, DATA-9— y las imágenes se
| muestran con una URL firmada.
*/

beforeEach(function () {
    Storage::fake(ImagenesDeLaApp::disco());
});

test('an employee reaches the screen and a shop does not', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/publicidad-app')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('publicidad-app/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/publicidad-app')
        ->assertForbidden();
});

test('creating one writes the row and the file together', function () {
    // ⚠️ Son dos filas —la publicidad y su imagen— y van en una transacción: una
    // publicidad sin imagen es una tarjeta vacía en el carrusel de la app.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/publicidad-app', [
            'url' => 'https://mensajes.test/promo',
            'visible' => '1',
            'imagen' => UploadedFile::fake()->image('promo.png', 800, 450),
        ])
        ->assertRedirect('/panel/publicidad-app');

    $publicidad = PublicidadApp::query()->where('url', 'https://mensajes.test/promo')->sole();

    expect($publicidad->visible)->toBeTrue()
        ->and($publicidad->imagen)->not->toBeNull();

    Storage::disk(ImagenesDeLaApp::disco())->assertExists($publicidad->imagen->ruta_archivo);
});

test('the stored path is one the column would accept', function () {
    // ⚠️ El nombre lo pone el servidor, no quien sube: un nombre que viene del cliente es
    // por donde se escribe fuera de la carpeta. El CHECK de la columna lo exige, y acá se
    // comprueba antes de que lo exija la base.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/publicidad-app', [
            'url' => 'https://mensajes.test/promo',
            'imagen' => UploadedFile::fake()->image('../../escapar.png'),
        ])
        ->assertRedirect();

    $ruta = PublicidadAppImagen::query()->sole()->ruta_archivo;

    expect($ruta)->toStartWith(ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/')
        ->and($ruta)->not->toContain('..')
        ->and(RutaDeArchivo::esValida($ruta))->toBeTrue();
});

test('without an image there is no publicity', function () {
    // El panel viejo mandaba la imagen como base64 en un campo oculto que completaba el
    // JavaScript; si ese JS fallaba, se creaba una publicidad SIN imagen, porque el
    // validador pedía el campo de archivo y lo que se guardaba era el oculto.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/publicidad-app', ['url' => 'https://mensajes.test/promo'])
        ->assertSessionHasErrors('imagen');

    expect(PublicidadApp::query()->count())->toBe(0);
});

test('a file that is not an image does not get in', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/publicidad-app', [
            'url' => 'https://mensajes.test/promo',
            'imagen' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('imagen');
});

test('editing without choosing a new image keeps the one that was there', function () {
    $publicidad = PublicidadApp::factory()->create();
    $imagen = PublicidadAppImagen::query()->create([
        'publicidad_id' => $publicidad->id,
        'ruta_archivo' => ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/vieja.png',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/publicidad-app/{$publicidad->id}", [
            'url' => 'https://mensajes.test/otra',
            'visible' => '1',
        ])
        ->assertRedirect('/panel/publicidad-app');

    expect($publicidad->refresh()->url)->toBe('https://mensajes.test/otra')
        ->and($imagen->refresh()->ruta_archivo)->toBe(ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/vieja.png');
});

test('showing and hiding is its own action', function () {
    // Cambiar si una publicidad se ve no debería obligar a abrir un formulario. Igual que
    // en el panel viejo.
    $publicidad = PublicidadApp::factory()->create(['visible' => true]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/publicidad-app/{$publicidad->id}/visibilidad")
        ->assertRedirect();

    expect($publicidad->refresh()->visible)->toBeFalse();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/publicidad-app/{$publicidad->id}/visibilidad");

    expect($publicidad->refresh()->visible)->toBeTrue();
});

test('only an administrator gives one up', function () {
    // El mismo recorte que el sistema viejo hacía con `rol:admin` sobre esa ruta.
    $publicidad = PublicidadApp::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/publicidad-app/{$publicidad->id}")
        ->assertForbidden();

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/publicidad-app/{$publicidad->id}")
        ->assertRedirect('/panel/publicidad-app');

    expect($publicidad->refresh()->trashed())->toBeTrue();
});

test('the image travels as a url and never as the stored path', function () {
    // ⚠️ El disco es privado. Mandar la ruta al frontend no sirve —no se puede pedir sin
    // firma— y además dice dónde están guardados los archivos.
    //
    // ⚠️ Acá NO se comprueba que la URL venga firmada, y el primer intento sí lo hacía:
    // con `Storage::fake` el `temporaryUrl()` es un doble que devuelve una URL sin firma,
    // así que esa aserción probaba el doble y no el comportamiento. Que el endpoint exija
    // firma se comprueba abajo, contra el endpoint.
    $ruta = ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/una.png';

    $publicidad = PublicidadApp::factory()->create();
    PublicidadAppImagen::query()->create([
        'publicidad_id' => $publicidad->id,
        'ruta_archivo' => $ruta,
    ]);
    Storage::disk(ImagenesDeLaApp::disco())->put($ruta, 'contenido');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/publicidad-app')
        ->assertInertia(function ($pagina) use ($ruta) {
            $imagen = $pagina->toArray()['props']['publicidades'][0]['imagen'];

            expect($imagen)->toBeString()
                ->and($imagen)->not->toBe($ruta)
                ->and($imagen)->toStartWith('http');
        });
});

test('the endpoint that serves those files refuses an unsigned request', function () {
    // ⚠️ Esto vale más allá de la publicidad: en el MISMO disco privado están los
    // documentos de identidad de las postulaciones, que no están en ningún otro respaldo
    // (DATA-9). La ruta la registra Laravel por `serve => true`, y `ServeFile` exige
    // firma cuando la visibilidad es privada. Si alguien marcara el disco como público,
    // este test lo dice.
    Storage::disk(ImagenesDeLaApp::disco())->put('postulacion-documentos/dni.png', 'contenido');

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/storage/postulacion-documentos/dni.png')
        ->assertForbidden();
});

test('a missing file shows as missing instead of as a broken image', function () {
    // El ETL sube en dos tiempos, así que una fila puede existir sin su archivo todavía.
    $publicidad = PublicidadApp::factory()->create();
    PublicidadAppImagen::query()->create([
        'publicidad_id' => $publicidad->id,
        'ruta_archivo' => ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/no-esta.png',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/publicidad-app')
        ->assertInertia(fn ($pagina) => $pagina->where('publicidades.0.imagen', null));
});

test('choosing a new image replaces the path and leaves the old file there', function () {
    $vieja = ImagenesDeLaApp::CARPETA_PUBLICIDADES.'/vieja.png';

    $publicidad = PublicidadApp::factory()->create();
    $imagen = PublicidadAppImagen::query()->create([
        'publicidad_id' => $publicidad->id,
        'ruta_archivo' => $vieja,
    ]);
    Storage::disk(ImagenesDeLaApp::disco())->put($vieja, 'contenido');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/publicidad-app/{$publicidad->id}", [
            'url' => 'https://mensajes.test/promo',
            'visible' => '1',
            'imagen' => UploadedFile::fake()->image('nueva.png', 800, 450),
        ])
        ->assertRedirect('/panel/publicidad-app');

    $ahora = $imagen->refresh()->ruta_archivo;

    expect($ahora)->not->toBe($vieja);
    Storage::disk(ImagenesDeLaApp::disco())->assertExists($ahora);

    // ⚠️ El archivo anterior NO se borra, y es deliberado: la publicidad se da de baja en
    // lógico y se puede restaurar, y el resto del sistema tampoco borra archivos solo
    // —ver el docblock de Etl\Deposito, que no borra nunca porque en el mismo disco hay
    // documentación de identidad—. Lo que sobra lo reclama una limpieza, no un camino de
    // escritura. Si alguna vez se decide borrarlo, este test lo dice.
    Storage::disk(ImagenesDeLaApp::disco())->assertExists($vieja);
});
