<?php

use App\Models\Cadete;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Models\Rol;
use App\Models\User;
use App\Services\DocumentosDePostulacion;
use App\Services\ImagenesDeLaApp;
use App\Support\PinDeCadete;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Las postulaciones para cadete.
|
| Lo que esta pantalla tiene que garantizar: que una postulación no se contrate dos veces,
| que no se contrate si el DNI o el email ya son de otro, que los documentos de identidad
| no viajen como rutas, y que rechazar no destruya los papeles de nadie.
*/

beforeEach(function () {
    Storage::fake(ImagenesDeLaApp::disco());
});

/** El rol con el que entra a la app el cadete que salga de una contratación. */
function elRolDeCadeteParaContratar(): Rol
{
    return Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
}

/**
 * Una postulación con sus cuatro documentos, con los archivos puestos.
 */
function unaPostulacionCompleta(array $atributos = []): Postulacion
{
    $postulacion = Postulacion::factory()->create($atributos);

    foreach (PostulacionDocumento::TIPOS as $tipo) {
        DocumentosDePostulacion::guardar(
            $postulacion,
            $tipo,
            UploadedFile::fake()->image($tipo.'.jpg', 600, 400),
        );
    }

    return $postulacion->refresh();
}

/**
 * Un cuerpo válido para el alta, con las cuatro imágenes.
 *
 * @return array<string, mixed>
 */
function datosDePostulacion(array $cambios = []): array
{
    $datos = [
        'nombres' => 'Ana',
        'apellidos' => 'Quiroga',
        'dni' => '31222333',
        'fecha_nacimiento' => '1994-03-20',
        'direccion' => 'San Martín 100',
        'telefono' => '3815550000',
        'email' => 'ana.quiroga@example.com',
        'tipo_vehiculo' => Cadete::VEHICULO_BICICLETA,
        'recomendado_por' => 'Un conocido',
    ];

    foreach (array_keys(Postulacion::PREGUNTAS) as $pregunta) {
        $datos[$pregunta] = Postulacion::SI;
    }

    foreach (PostulacionDocumento::TIPOS as $tipo) {
        $datos[$tipo] = UploadedFile::fake()->image($tipo.'.jpg', 600, 400);
    }

    return array_merge($datos, $cambios);
}

test('an employee reaches the list and a shop does not', function () {
    Postulacion::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/postulaciones')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('postulaciones/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/cadetes/postulaciones')
        ->assertForbidden();
});

test('by default it shows the ones still waiting for a decision', function () {
    Postulacion::factory()->count(2)->create();
    Postulacion::factory()->contratada()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/postulaciones')
        ->assertInertia(function ($pagina) {
            $props = $pagina->toArray()['props'];

            expect($props['postulaciones']['data'])->toHaveCount(2)
                ->and($props['pendientes'])->toBe(2);
        });
});

test('searching by name does not drag in the ones already hired', function () {
    // ⚠️ El listado viejo encadenaba where(nombres)->orWhere(apellidos)->doesntHave(),
    // y como AND liga más fuerte que OR eso es «nombres LIKE x OR (apellidos LIKE y AND no
    // contratada)»: buscar por nombre devolvía TAMBIÉN las ya contratadas, en la pantalla
    // cuyo sentido es ver las que esperan decisión.
    Postulacion::factory()->create(['nombres' => 'Marta', 'apellidos' => 'Ibáñez']);
    Postulacion::factory()->contratada()->create(['nombres' => 'Marta', 'apellidos' => 'Suárez']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/postulaciones?buscar=marta')
        ->assertInertia(function ($pagina) {
            $filas = $pagina->toArray()['props']['postulaciones']['data'];

            expect($filas)->toHaveCount(1)
                ->and($filas[0]['nombre'])->toBe('Marta Ibáñez');
        });
});

test('the hired ones can be looked up, with the mobile they became', function () {
    // El listado viejo no las mostraba nunca, y ahí viven los documentos de esa persona.
    $cadete = Cadete::factory()->create(['numero_movil' => 77]);
    Postulacion::factory()->create(['cadete_id' => $cadete->id]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes/postulaciones?estado=contratadas')
        ->assertInertia(function ($pagina) {
            $filas = $pagina->toArray()['props']['postulaciones']['data'];

            expect($filas)->toHaveCount(1)
                ->and($filas[0]['movil'])->toBe(77);
        });
});

test('loading one writes the row and its four documents', function () {
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes/postulaciones', datosDePostulacion())
        ->assertRedirect();

    $postulacion = Postulacion::query()->sole();

    expect($postulacion->dni)->toBe(31222333)
        ->and($postulacion->email)->toBe('ana.quiroga@example.com')
        ->and($postulacion->documentos)->toHaveCount(4);

    foreach ($postulacion->documentos as $documento) {
        Storage::disk(ImagenesDeLaApp::disco())->assertExists($documento->ruta_archivo);
    }
});

test('without the images nothing is written at all', function () {
    // ⚠️ El formulario viejo mandaba un campo de archivo y un campo oculto con el base64
    // que completaba el JavaScript, y validaba el de archivo mientras guardaba el oculto:
    // si ese JS fallaba, la postulación se guardaba SIN imágenes.
    $datos = datosDePostulacion();
    unset($datos[PostulacionDocumento::TIPO_DNI_DORSO]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes/postulaciones', $datos)
        ->assertSessionHasErrors(PostulacionDocumento::TIPO_DNI_DORSO);

    expect(Postulacion::query()->count())->toBe(0)
        ->and(PostulacionDocumento::query()->count())->toBe(0);
});

test('a dni or an email already taken does not get in', function (string $campo, string $valor, Closure $ocupar) {
    $ocupar($valor);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes/postulaciones', datosDePostulacion([$campo => $valor]))
        ->assertSessionHasErrors($campo);

    expect(Postulacion::query()->count())->toBe(1);
})->with([
    'el DNI, de otra postulación' => [
        'dni', '31222333',
        fn (string $valor) => Postulacion::factory()->create(['dni' => (int) $valor]),
    ],
    'el DNI, de un cadete' => [
        'dni', '31222333',
        function (string $valor) {
            Cadete::factory()->create(['dni' => (int) $valor]);
            Postulacion::factory()->create();
        },
    ],
    'el email, de otra postulación' => [
        'email', 'ocupado@example.com',
        fn (string $valor) => Postulacion::factory()->create(['email' => $valor]),
    ],
    'el email, de una cuenta' => [
        'email', 'ocupado@example.com',
        function (string $valor) {
            User::factory()->create(['email' => $valor]);
            Postulacion::factory()->create();
        },
    ],
]);

test('the documents travel as urls and never as the stored path', function () {
    // ⚠️ El disco es privado y acá están los DNI de esa persona, que no están en ningún
    // otro respaldo (DATA-9). Mandar la ruta dice dónde están guardados.
    //
    // Que el endpoint exija firma se comprueba en PublicidadAppTest, contra el endpoint:
    // con Storage::fake el temporaryUrl() es un doble que devuelve una URL sin firma.
    $postulacion = unaPostulacionCompleta();
    $rutas = $postulacion->documentos->pluck('ruta_archivo')->all();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/postulaciones/{$postulacion->id}")
        ->assertInertia(function ($pagina) use ($rutas) {
            $documentos = $pagina->toArray()['props']['postulacion']['documentos'];

            expect(array_keys($documentos))->toBe(PostulacionDocumento::TIPOS);

            foreach ($documentos as $url) {
                expect($url)->toBeString()
                    ->and($rutas)->not->toContain($url);
            }
        });
});

test('the screen shows the questionnaire the old panel never showed', function () {
    // El panel viejo guardaba las cuatro respuestas y no las mostraba en ningún lado:
    // quien decidía contratar no llegaba a verlas.
    $postulacion = Postulacion::factory()->create([
        'pregunta_experiencia_app' => Postulacion::NO,
        'pregunta_tiene_celular' => Postulacion::SI,
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/postulaciones/{$postulacion->id}")
        ->assertInertia(function ($pagina) {
            $respuestas = $pagina->toArray()['props']['postulacion']['respuestas'];

            expect($respuestas)->toHaveCount(4)
                ->and($respuestas[0]['respuesta'])->toBe('No')
                ->and($respuestas[1]['respuesta'])->toBe('Sí');
        });
});

test('hiring creates the cadete with its account, and leaves the application pointing at it', function () {
    elRolDeCadeteParaContratar();
    $postulacion = unaPostulacionCompleta();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", [
            'numero_movil' => 310,
        ])
        ->assertRedirect('/panel/cadetes/postulaciones')
        // La contraseña se muestra una sola vez, y es lo único que vuelve de ella.
        //
        // ⚠️ Y es un PIN numérico de seis dígitos, no una contraseña cualquiera: el cadete
        // la escribe en el mostrador con un teclado numérico. Ver App\Support\PinDeCadete.
        ->assertSessionHas('success', fn (string $mensaje): bool => str_contains($mensaje, 'no se vuelve a mostrar')
            && preg_match('/Contraseña de ingreso: [0-9]{'.PinDeCadete::DIGITOS_GENERADOS.'} /', $mensaje) === 1);

    $cadete = Cadete::query()->where('numero_movil', 310)->sole();

    expect($postulacion->refresh()->cadete_id)->toBe($cadete->id)
        ->and($cadete->dni)->toBe($postulacion->dni)
        ->and($cadete->estado)->toBe(Cadete::ESTADO_INACTIVO)
        ->and($cadete->modalidad_cobranza)->toBe(Cadete::COBRANZA_SEMANAL)
        ->and($cadete->user?->email)->toBe($postulacion->email)
        ->and($cadete->user?->rol?->rol)->toBe(Rol::CADETE);
});

test('the same application cannot be hired twice', function () {
    // ⚠️ El panel viejo no lo preguntaba: dos envíos del mismo formulario creaban dos
    // cadetes —con dos cuentas— a partir de la misma persona, y cadete_id terminaba
    // apuntando al segundo. El primero quedaba suelto, con su móvil ocupado y sin legajo.
    elRolDeCadeteParaContratar();
    $postulacion = unaPostulacionCompleta();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", ['numero_movil' => 311])
        ->assertRedirect();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", ['numero_movil' => 312])
        ->assertForbidden();

    expect(Cadete::query()->count())->toBe(1);
});

test('without the three documents it cannot be hired, and the screen says which ones', function () {
    elRolDeCadeteParaContratar();
    $postulacion = Postulacion::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/postulaciones/{$postulacion->id}")
        ->assertInertia(function ($pagina) {
            $contratar = $pagina->toArray()['props']['contratar'];

            expect($contratar['permitido'])->toBeFalse()
                ->and($contratar['motivo'])->toContain('dni frente');
        });

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", ['numero_movil' => 313])
        ->assertForbidden();

    expect(Cadete::query()->count())->toBe(0);
});

test('a row whose file was never uploaded counts as missing', function () {
    // El panel viejo miraba si la columna empezaba con «data:», o sea si tenía base64.
    // Acá la fila puede existir y el archivo no: lo que importa es el archivo.
    elRolDeCadeteParaContratar();
    $postulacion = unaPostulacionCompleta();

    $dorso = $postulacion->documentos->firstWhere('tipo', PostulacionDocumento::TIPO_DNI_DORSO);
    Storage::disk(ImagenesDeLaApp::disco())->delete((string) $dorso?->ruta_archivo);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", ['numero_movil' => 314])
        ->assertForbidden();
});

test('it is not hired when the dni or the email already belong to somebody else', function (Closure $ocupar, string $enElMotivo) {
    elRolDeCadeteParaContratar();
    $postulacion = unaPostulacionCompleta();
    $ocupar($postulacion);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/postulaciones/{$postulacion->id}")
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['contratar']['motivo'],
        )->toContain($enElMotivo));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", ['numero_movil' => 315])
        ->assertForbidden();
})->with([
    // El DNI se comprobó al postularse, pero hasta la contratación alguien pudo cargarlo
    // en otro cadete.
    'el DNI, en un cadete dado de baja' => [
        function (Postulacion $postulacion) {
            $cadete = Cadete::factory()->create(['dni' => $postulacion->dni]);
            $cadete->delete();
        },
        'DNI',
    ],
    // ⚠️ Esto el panel viejo no lo miraba. En MongoDB no había índice único y creaba una
    // segunda cuenta con el mismo email; acá users.email es UNIQUE y sin esta pregunta
    // contratar respondería 500 contra el índice.
    'el email, en otra cuenta' => [
        fn (Postulacion $postulacion) => User::factory()->create(['email' => $postulacion->email]),
        'email',
    ],
]);

test('a mobile number already taken is refused, counting cadetes given up', function () {
    elRolDeCadeteParaContratar();
    $postulacion = unaPostulacionCompleta();
    $otro = Cadete::factory()->create(['numero_movil' => 320]);
    $otro->delete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", ['numero_movil' => 320])
        ->assertSessionHasErrors('numero_movil');

    expect($postulacion->refresh()->cadete_id)->toBeNull();
});

test('rejecting takes it off the list and keeps the papers', function () {
    // ⚠️ El panel viejo hacía forceDelete(): la postulación se borraba para siempre, con
    // los documentos de identidad de esa persona, que no están en ningún otro respaldo
    // (DATA-9). Acá es baja lógica y los archivos no se tocan.
    $postulacion = unaPostulacionCompleta();
    $rutas = $postulacion->documentos->pluck('ruta_archivo')->all();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/cadetes/postulaciones/{$postulacion->id}")
        ->assertRedirect('/panel/cadetes/postulaciones');

    expect(Postulacion::query()->count())->toBe(0)
        ->and(Postulacion::withTrashed()->count())->toBe(1);

    foreach ($rutas as $ruta) {
        Storage::disk(ImagenesDeLaApp::disco())->assertExists($ruta);
    }
});

test('an application that is already a cadete file cannot be rejected', function () {
    $postulacion = Postulacion::factory()->contratada()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/postulaciones/{$postulacion->id}")
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['rechazar']['permitido'],
        )->toBeFalse());

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/cadetes/postulaciones/{$postulacion->id}")
        ->assertForbidden();

    expect(Postulacion::query()->count())->toBe(1);
});

test('a shop cannot write at any of the routes', function () {
    $postulacion = Postulacion::factory()->create();
    $comercio = usuarioCon(Rol::RESTRINGIDO);

    $this->actingAs($comercio)->get("/panel/cadetes/postulaciones/{$postulacion->id}")->assertForbidden();
    $this->actingAs($comercio)->post('/panel/cadetes/postulaciones', datosDePostulacion())->assertForbidden();
    $this->actingAs($comercio)->post("/panel/cadetes/postulaciones/{$postulacion->id}/contratacion", ['numero_movil' => 9])->assertForbidden();
    $this->actingAs($comercio)->delete("/panel/cadetes/postulaciones/{$postulacion->id}")->assertForbidden();
});
