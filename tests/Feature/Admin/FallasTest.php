<?php

use App\Filament\Pages\Fallas;
use App\Models\Rol;
use App\Services\FallasDeLaApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| La pantalla de fallas de /admin.
|
| Lee lo que guardó Telescope y lo cuenta. Telescope está apagado en los tests
| —`TELESCOPE_ENABLED` en phpunit.xml—, y está bien que así sea: lo que hay que probar es
| la lectura, y que las filas las escriba Telescope o este archivo da lo mismo. Lo que
| Telescope guarda y lo que etiqueta se prueba en producción el día que falle algo, que
| es para lo que está.
|
| ⚠️ Las consultas son de PostgreSQL y entran al JSON de una columna de TEXTO, que es como
| Telescope la crea: sin el `::jsonb` la base contesta «operator does not exist: text ->>».
| Eso es lo que estos tests sujetan de verdad.
*/

beforeEach(function () {
    $this->actingAs(usuarioCon(Rol::ADMIN));
});

/** Una petición como la guarda Telescope. */
function unaPeticionGuardada(string $uri, int $codigo, ?int $movil = null, ?Carbon $cuando = null): string
{
    $uuid = (string) Str::uuid();

    DB::table('telescope_entries')->insert([
        'uuid' => $uuid,
        'batch_id' => (string) Str::uuid(),
        'type' => 'request',
        'should_display_on_index' => true,
        'content' => json_encode([
            'uri' => $uri,
            'method' => 'POST',
            'response_status' => $codigo,
        ]),
        'created_at' => $cuando ?? Carbon::now(),
    ]);

    DB::table('telescope_entries_tags')->insert([
        'entry_uuid' => $uuid,
        'tag' => 'codigo:'.$codigo,
    ]);

    if ($movil !== null) {
        DB::table('telescope_entries_tags')->insert([
            'entry_uuid' => $uuid,
            'tag' => 'movil:'.$movil,
        ]);
    }

    return $uuid;
}

test('it counts what failed by code, route and movil', function () {
    unaPeticionGuardada('api/actualizarubicacion', 429, 142);
    unaPeticionGuardada('api/actualizarubicacion', 429, 142);
    unaPeticionGuardada('api/actualizarubicacion', 401);
    unaPeticionGuardada('api/getcadete', 500, 77);

    $fallas = app(FallasDeLaApi::class);

    expect($fallas->total(7))->toBe(4);

    // Como mapa y con `toEqual`: entre dos códigos que empataron en uno, cuál sale
    // primero lo decide PostgreSQL, y no es algo que este archivo tenga que fijar.
    expect($fallas->porCodigo(7)->pluck('cuantas', 'codigo')->all())
        ->toEqual([429 => 2, 500 => 1, 401 => 1]);

    expect($fallas->porRuta(7)->first())
        ->toBe(['ruta' => 'api/actualizarubicacion', 'codigo' => 429, 'cuantas' => 2]);

    // ⚠️ La pregunta que motivó todo esto: si son muchos cadetes con un problema cada uno
    // o siempre el mismo teléfono. El 401 no tiene móvil porque no se supo quién era.
    expect($fallas->porMovil(7)->all())->toBe([
        ['movil' => 142, 'cuantas' => 2],
        ['movil' => 77, 'cuantas' => 1],
    ]);
});

test('what came out fine is not a failure', function () {
    unaPeticionGuardada('api/actualizarubicacion', 200, 142);
    unaPeticionGuardada('api/actualizarubicacion', 302);

    expect(app(FallasDeLaApi::class)->total(7))->toBe(0);
});

test('what is older than the days being looked at does not count', function () {
    unaPeticionGuardada('api/getcadete', 500, null, Carbon::now()->subDays(3));

    $fallas = app(FallasDeLaApi::class);

    expect($fallas->total(1))->toBe(0)
        ->and($fallas->total(7))->toBe(1);
});

test('the screen shows the codes, the movil and a way into Telescope', function () {
    $uuid = unaPeticionGuardada('api/actualizarubicacion', 429, 142);

    Livewire::test(Fallas::class)
        ->assertOk()
        ->assertSee('429')
        ->assertSee('api/actualizarubicacion')
        ->assertSee('Móvil 142')
        // El enlace al pedido entero, que es la otra mitad de esto.
        ->assertSee('/telescope/requests/'.$uuid, escape: false);
});

test('the days being looked at can be changed, and one day is not seven', function () {
    unaPeticionGuardada('api/getcadete', 500, null, Carbon::now()->subDays(3));

    Livewire::test(Fallas::class)
        ->assertSee('api/getcadete')
        ->set('dias', 1)
        ->assertDontSee('api/getcadete')
        ->assertSee('Nada falló');
});

test('an employee does not get in', function () {
    // ⚠️ A /admin entra sólo `admin`, y acá se ven cuerpos de pedidos de todo el sistema.
    // El recorte lo hace `User::canAccessPanel()`, no esta pantalla.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/admin/fallas')
        ->assertForbidden();
});
