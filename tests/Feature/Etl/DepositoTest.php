<?php

use App\Etl\Deposito;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Subir las imágenes al disco definitivo. Lo que se prueba acá es lo que decide
| si el corte se puede dar por bueno: que subir sea repetible y barato de
| repetir, y que FALTAR un archivo frene. Ver ETL-1 y DATA-9.
|
| No hace falta un bucket: los dos discos son falsos, y es el mismo `Storage` que
| la aplicación usa contra cualquier proveedor.
*/

/** Una fila de `user_fotos` con su archivo puesto en el disco de la carga. */
function fotoDe(string $nombre, string $contenido = 'unos bytes'): string
{
    $user = User::factory()->create();
    $ruta = "user-fotos/{$nombre}.png";

    Storage::disk('carga')->put($ruta, $contenido);

    DB::table('user_fotos')->insert([
        'user_id' => $user->id,
        'ruta_archivo' => $ruta,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $ruta;
}

beforeEach(function () {
    Storage::fake('carga');
    Storage::fake('deposito');
});

test('uploading copies what is missing and leaves alone what is already there', function () {
    $primera = fotoDe('aaaaaaaaaaaaaaaaaaaaaaa1');
    $segunda = fotoDe('aaaaaaaaaaaaaaaaaaaaaaa2');

    // La segunda ya está en el destino, con el mismo tamaño.
    Storage::disk('deposito')->put($segunda, 'unos bytes');

    $deposito = new Deposito('carga', 'deposito');
    $cuenta = $deposito->subir(aplicar: true);

    expect($cuenta['copiados'])->toBe(1)
        ->and($cuenta['salteados'])->toBe(1)
        ->and($cuenta['bytes'])->toBe(10);

    Storage::disk('deposito')->assertExists($primera);
    expect(Storage::disk('deposito')->get($primera))->toBe('unos bytes');
});

test('running it twice is cheap, which is what makes the cutover window short', function () {
    fotoDe('bbbbbbbbbbbbbbbbbbbbbbb1');
    fotoDe('bbbbbbbbbbbbbbbbbbbbbbb2');

    $deposito = new Deposito('carga', 'deposito');

    expect($deposito->subir(aplicar: true)['copiados'])->toBe(2);

    // La corrida de la ventana sube sólo lo que cambió: acá, nada. Es la razón
    // de ser de todo esto —68 archivos y 31 MB en la ventana, no 4.509 y 2,2 GB—.
    $segunda = $deposito->subir(aplicar: true);

    expect($segunda['copiados'])->toBe(0)
        ->and($segunda['salteados'])->toBe(2)
        ->and($segunda['bytes'])->toBe(0);
});

test('a file whose size changed is sent again', function () {
    $ruta = fotoDe('ccccccccccccccccccccccc1', 'la nueva, mas larga');

    // En el destino quedó una versión más corta de una corrida anterior.
    Storage::disk('deposito')->put($ruta, 'la vieja');

    expect((new Deposito('carga', 'deposito'))->subir(aplicar: true)['copiados'])->toBe(1)
        ->and(Storage::disk('deposito')->get($ruta))->toBe('la nueva, mas larga');
});

test('the informe mode measures without writing, so the window can be planned', function () {
    fotoDe('ddddddddddddddddddddddd1');

    $cuenta = (new Deposito('carga', 'deposito'))->subir(aplicar: false);

    expect($cuenta['copiados'])->toBe(1)
        ->and($cuenta['bytes'])->toBe(10)
        ->and(Storage::disk('deposito')->allFiles())->toBeEmpty();
});

test('a row whose image is missing has to stop the cutover', function () {
    $presente = fotoDe('eeeeeeeeeeeeeeeeeeeeeee1');
    $ausente = fotoDe('eeeeeeeeeeeeeeeeeeeeeee2');

    Storage::disk('deposito')->put($presente, 'unos bytes');

    // Una fila cuya ruta apunta a la nada es exactamente lo que describe DATA-9,
    // y es lo que este proyecto viene a no reproducir.
    expect((new Deposito('carga', 'deposito'))->faltantes())->toBe([$ausente]);

    $this->artisan('etl:archivos', ['--verificar' => true, '--origen' => 'carga', '--destino' => 'deposito'])
        ->assertExitCode(1);
});

test('what nobody claims is reported and never deleted', function () {
    $reclamada = fotoDe('fffffffffffffffffffffff1');

    Storage::disk('deposito')->put($reclamada, 'unos bytes');
    // De una corrida anterior, o una imagen que el origen dejó de tener.
    Storage::disk('deposito')->put('user-fotos/fffffffffffffffffffffff9.png', 'huerfana');

    $deposito = new Deposito('carga', 'deposito');

    expect($deposito->sobrantes())->toBe(['user-fotos/fffffffffffffffffffffff9.png'])
        ->and($deposito->faltantes())->toBeEmpty();

    $deposito->subir(aplicar: true);

    // ⚠️ Sobrar no autoriza a borrar: estas imágenes incluyen documentación de
    // identidad que no está en ningún otro respaldo. Lo resuelve una persona.
    Storage::disk('deposito')->assertExists('user-fotos/fffffffffffffffffffffff9.png');
});

test('uploading a disk onto itself means nothing and says so', function () {
    fotoDe('9999999999999999999999a1');

    expect(fn () => (new Deposito('carga', 'carga'))->subir(aplicar: true))
        ->toThrow(RuntimeException::class, 'son el mismo');

    expect((new Deposito('carga', 'carga'))->mismoDisco())->toBeTrue()
        ->and((new Deposito('carga', 'deposito'))->mismoDisco())->toBeFalse();

    // Comprobar, en cambio, sí tiene sentido con un disco solo: es la
    // configuración del ensayo, donde todavía no hay bucket.
    $this->artisan('etl:archivos', ['--aplicar' => true, '--origen' => 'carga', '--destino' => 'carga'])
        ->assertExitCode(1);

    $this->artisan('etl:archivos', ['--verificar' => true, '--origen' => 'carga', '--destino' => 'carga'])
        ->assertExitCode(0);
});

test('a row whose file never reached the load disk is named, not skipped', function () {
    $user = User::factory()->create();

    DB::table('user_fotos')->insert([
        'user_id' => $user->id,
        'ruta_archivo' => 'user-fotos/1111111111111111111111a1.png',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => (new Deposito('carga', 'deposito'))->subir(aplicar: true))
        ->toThrow(RuntimeException::class, 'Correr la carga antes de subir');
});
