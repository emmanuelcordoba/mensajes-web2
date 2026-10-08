<?php

use App\Models\ActividadCadete;
use App\Models\Cadete;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Models\UserFoto;
use App\Services\FotosDePerfil;
use App\Services\HistorialDelCadete;
use App\Support\FechaDeNacimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
| El perfil y el historial de la app del cadete.
|
| ⚠️ **Lo que estos tests fijan es un contrato que no podemos cambiar.** La app está
| publicada en los teléfonos y declara lo que lee en `interfaces.ts`: `RespuestaPerfil`,
| `RespuestaHistorial` y `TiempoActivo`. El punto más fino está en `tiempo_activo`, que allá
| era un `DateInterval` de PHP serializado y hoy no se puede copiar —PHP 8.2 borró siete de
| esas propiedades—, así que se arma a mano y hay que sujetar sus nombres.
|
| ⚠️ Y el **formato del 422**, que no es el que Laravel manda por omisión: la app lee
| `err.error.error.<campo>` para poner el texto rojo debajo de cada campo.
*/

function unCadeteDelPerfil(array $delCadete = [], array $delUsuario = []): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);

    $usuario = User::factory()->create(array_merge([
        'rol_id' => $rol->id,
        'name' => 'Juan Pérez',
        'email' => 'perfil@example.test',
    ], $delUsuario));

    return Cadete::factory()->create(array_merge([
        'user_id' => $usuario->id,
        'numero_movil' => 33,
        'nombres' => 'Juan',
        'apellidos' => 'Pérez',
        'dni' => 30111222,
        'direccion' => 'Balcarce 100',
        'telefono' => '3815551234',
        'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
        'fecha_nacimiento' => '1990-05-20',
        'estado' => Cadete::ESTADO_INACTIVO,
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
    ], $delCadete));
}

/** Los nueve campos que la app manda al guardar el perfil, con el perfil ya cargado. */
function elFormularioDelPerfil(array $cambios = []): array
{
    return array_merge([
        'nombres' => 'Juan',
        'apellidos' => 'Pérez',
        'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
        'direccion' => 'Balcarce 100',
        'telefono' => '3815551234',
        'fecha_nacimiento' => '1990-05-20',
        'dni' => 30111222,
        'email' => 'perfil@example.test',
        // La app devuelve este campo tal como lo recibió, incluso sin tocar la foto.
        'foto' => 'assets/images/avatar.png',
    ], $cambios);
}

/** Un JPEG mínimo de verdad, como `data:` URL, que es lo que manda el recortador. */
function unaFotoNueva(): string
{
    return 'data:image/jpeg;base64,'.base64_encode('no es un jpeg pero son bytes');
}

// --------------------------------------------------------------------- el perfil

test('the profile answers the account with the shape the app declares', function () {
    $cadete = unCadeteDelPerfil();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/getperfil')
        ->assertOk();

    // ⚠️ La app lee `resp['usuario']` y de ahí `usuario.cadete?.nombres`: el cadete tiene
    // que viajar ADENTRO del usuario, no al lado.
    expect($respuesta->json('usuario._id'))->toBe((string) $cadete->user_id)
        ->and($respuesta->json('usuario.email'))->toBe('perfil@example.test')
        ->and($respuesta->json('usuario.cadete._id'))->toBe((string) $cadete->id)
        ->and($respuesta->json('usuario.cadete.nombres'))->toBe('Juan')
        ->and($respuesta->json('usuario.cadete.dni'))->toBeInt()
        // `<ion-input type="date">` necesita el día sin la hora.
        ->and($respuesta->json('usuario.cadete.fecha_nacimiento'))->toBe('1990-05-20');
});

test('the profile does not promise the weekly report the old system never built', function () {
    $cadete = unCadeteDelPerfil();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/getperfil')->assertOk();

    // Allá venía con ceros fijos y la app no lo lee en ninguna pantalla.
    expect($respuesta->json())->not->toHaveKey('reporte_semanal');
});

test('the photo travels as a signed url and not as the image', function () {
    Storage::fake(FotosDePerfil::disco());

    $cadete = unCadeteDelPerfil();

    Storage::disk(FotosDePerfil::disco())->put(FotosDePerfil::CARPETA.'/x.jpg', 'bytes');
    UserFoto::query()->create([
        'user_id' => $cadete->user_id,
        'ruta_archivo' => FotosDePerfil::CARPETA.'/x.jpg',
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/getperfil')->assertOk();

    expect($respuesta->json('usuario.foto'))->toBeString()
        ->and($respuesta->json('usuario.foto'))->not->toStartWith('data:');
});

test('a cadete without a photo gets null, so the app shows its own avatar', function () {
    $cadete = unCadeteDelPerfil();

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/getperfil')
        ->assertOk()
        // La app hace `usuario.foto || 'assets/images/avatar.png'`.
        ->assertJsonPath('usuario.foto', null);
});

test('the profile needs a token', function () {
    $this->getJson('/api/getperfil')->assertUnauthorized();
    $this->postJson('/api/editarperfil', elFormularioDelPerfil())->assertUnauthorized();
    $this->getJson('/api/historial')->assertUnauthorized();
});

test('an account that is not a cadete cannot read the profile', function () {
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::EMPLEADO], ['display_rol' => 'Empleado']);
    $empleado = User::factory()->create(['rol_id' => $rol->id]);

    $this->actingAs($empleado, 'sanctum')->getJson('/api/getperfil')->assertForbidden();
});

// ------------------------------------------------------------- guardar el perfil

test('the cadete saves his profile and gets the account back with the cadete inside', function () {
    $cadete = unCadeteDelPerfil();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil([
            'nombres' => 'Juan Carlos',
            'telefono' => '3819998888',
        ]))
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('usuario.cadete.nombres'))->toBe('Juan Carlos')
        ->and($respuesta->json('usuario.cadete.telefono'))->toBe('3819998888');

    $cadete->refresh();

    expect($cadete->nombres)->toBe('Juan Carlos')
        ->and($cadete->telefono)->toBe('3819998888')
        // ⚠️ El nombre de la cuenta se rearma: es el que el panel muestra en la lista de
        // cuentas y en el chat. El sistema viejo lo hacía acá y la edición del panel no.
        ->and($cadete->user->name)->toBe('Juan Carlos Pérez');
});

test('what the app does not send stays untouched', function () {
    $cadete = unCadeteDelPerfil([
        'estado' => Cadete::ESTADO_ACTIVO_APP,
        'orden_cola' => 7,
        'cobranza_saldo' => '5000.00',
        'modalidad_cobranza' => Cadete::COBRANZA_SALDO,
    ]);

    $administrador = Rol::query()->firstOrCreate(['rol' => Rol::ADMIN], ['display_rol' => 'Admin']);

    // ⚠️ Es el SEC-4 del sistema viejo: allá la edición llenaba el modelo con todo lo que
    // viniera en el cuerpo, y `rol_id` está en el `fillable` de User.
    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil([
            'numero_movil' => 1,
            'estado' => Cadete::ESTADO_INACTIVO,
            'orden_cola' => 1,
            'cobranza_saldo' => '999999.00',
            'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
            'rol_id' => $administrador->id,
            'password' => 'otra-clave',
        ]))
        ->assertOk();

    $cadete->refresh();

    expect($cadete->numero_movil)->toBe(33)
        ->and($cadete->estado)->toBe(Cadete::ESTADO_ACTIVO_APP)
        ->and($cadete->orden_cola)->toBe(7)
        ->and($cadete->cobranza_saldo)->toBe('5000.00')
        ->and($cadete->modalidad_cobranza)->toBe(Cadete::COBRANZA_SALDO)
        ->and($cadete->user->rol_id)->not->toBe($administrador->id);
});

test('the validation errors come in the shape the app paints under each field', function () {
    $cadete = unCadeteDelPerfil();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil(['nombres' => '', 'dni' => 'veinte']))
        ->assertStatus(422);

    // ⚠️ `{error: {campo: [mensajes]}, code: 422}`, y NO el `{message, errors}` de Laravel:
    // la app hace `if (err.error.code == 422) { this.respuesta_messages = err.error.error }`
    // y después recorre `respuesta_messages.nombres`. Con el otro formato muestra «Hubo un
    // error» y el cadete no se entera de qué campo está mal.
    expect($respuesta->json('code'))->toBe(422)
        ->and($respuesta->json('error.nombres'))->toBeArray()->not->toBeEmpty()
        ->and($respuesta->json('error.dni.0'))->toBeString()
        ->and($respuesta->json())->not->toHaveKey('errors')
        ->and($respuesta->json())->not->toHaveKey('message');
});

test('a dni another cadete already has is refused, even if that one was let go', function () {
    $cadete = unCadeteDelPerfil();

    $otro = unCadeteDelPerfil(
        ['numero_movil' => 34, 'dni' => 29888777],
        ['email' => 'otro@example.test'],
    );
    $otro->delete();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil(['dni' => 29888777]))
        ->assertStatus(422);

    expect($respuesta->json('error.dni.0'))->toBe('Ese DNI ya lo tiene otro cadete.');
});

test('his own dni is not a duplicate of himself', function () {
    $cadete = unCadeteDelPerfil();

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil())
        ->assertOk();
});

test('the email is compared in lowercase, so a capital letter is not a free pass', function () {
    $cadete = unCadeteDelPerfil();
    unCadeteDelPerfil(['numero_movil' => 35, 'dni' => 29777666], ['email' => 'ocupado@example.test']);

    // ⚠️ Sin normalizar antes de validar, `unique` no encuentra «ocupado@example.test» y
    // la escritura revienta contra el índice. Ver DATA-7.
    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil(['email' => 'Ocupado@Example.Test']))
        ->assertStatus(422);

    expect($respuesta->json('error.email.0'))->toBe('Ese email ya está en uso.');
});

test('an impossible birth date is refused with the same range as the panel', function () {
    $cadete = unCadeteDelPerfil();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil(['fecha_nacimiento' => '0190-01-01']))
        ->assertStatus(422);

    expect($respuesta->json('error.fecha_nacimiento.0'))
        ->toBe('La fecha de nacimiento no puede ser de hace más de '.FechaDeNacimiento::ANOS_MAXIMOS.' años.');
});

test('only a B or an M is a vehicle', function () {
    $cadete = unCadeteDelPerfil();

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil(['tipo_vehiculo' => 'Z']))
        ->assertStatus(422);
});

// ------------------------------------------------------------------------ la foto

test('a new photo is stored as a file and the row points at it', function () {
    Storage::fake(FotosDePerfil::disco());

    $cadete = unCadeteDelPerfil();

    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil(['foto' => unaFotoNueva()]))
        ->assertOk();

    $foto = UserFoto::query()->where('user_id', $cadete->user_id)->first();

    expect($foto)->not->toBeNull();
    Storage::disk(FotosDePerfil::disco())->assertExists($foto->ruta_archivo);
});

test('the url the app sends back is not mistaken for a new photo', function () {
    Storage::fake(FotosDePerfil::disco());

    $cadete = unCadeteDelPerfil();

    Storage::disk(FotosDePerfil::disco())->put(FotosDePerfil::CARPETA.'/vieja.jpg', 'bytes');
    UserFoto::query()->create([
        'user_id' => $cadete->user_id,
        'ruta_archivo' => FotosDePerfil::CARPETA.'/vieja.jpg',
    ]);

    $url = FotosDePerfil::url(FotosDePerfil::CARPETA.'/vieja.jpg');

    // ⚠️ `perfil-editar` manda SIEMPRE el campo `foto`, con lo que recibió al cargar la
    // pantalla. Si eso se tomara por una foto nueva, cada guardado escribiría un archivo.
    $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil(['foto' => $url]))
        ->assertOk();

    expect(UserFoto::query()->where('user_id', $cadete->user_id)->first()->ruta_archivo)
        ->toBe(FotosDePerfil::CARPETA.'/vieja.jpg')
        ->and(Storage::disk(FotosDePerfil::disco())->files(FotosDePerfil::CARPETA))->toHaveCount(1);
});

test('a broken data url is a 422 with the reason and not a 500', function () {
    Storage::fake(FotosDePerfil::disco());

    $cadete = unCadeteDelPerfil();

    // ⚠️ Es la misma pregunta que contesta el que escribe, hecha antes: `interpretar()`
    // frena con una excepción, y sin `porQueNoSirve()` eso le llega al cadete como un 500.
    $respuesta = $this->actingAs($cadete->user, 'sanctum')
        ->postJson('/api/editarperfil', elFormularioDelPerfil([
            'foto' => 'data:application/pdf;base64,'.base64_encode('no es una imagen'),
        ]))
        ->assertStatus(422);

    expect($respuesta->json('error.foto.0'))->toContain('application/pdf');
});

test('what the validation refuses and what the writer refuses are the same thing', function () {
    // Una regla, dos lectores: la validación pregunta `porQueNoSirve()` y el que escribe
    // confía. Si se separan, un `data:` que pasa la validación revienta al guardar.
    $roto = 'data:application/pdf;base64,'.base64_encode('no');
    $grande = 'data:image/jpeg;base64,'.base64_encode(str_repeat('x', FotosDePerfil::BYTES_MAXIMOS + 1));
    $bueno = unaFotoNueva();

    expect(FotosDePerfil::porQueNoSirve($roto))->toBeString()
        ->and(FotosDePerfil::porQueNoSirve($grande))->toBeString()
        ->and(FotosDePerfil::porQueNoSirve($bueno))->toBeNull()
        // Y lo que no es una foto nueva no se revisa: es la URL que emitimos nosotros.
        ->and(FotosDePerfil::porQueNoSirve('assets/images/avatar.png'))->toBeNull()
        ->and(FotosDePerfil::porQueNoSirve(null))->toBeNull();
});

// --------------------------------------------------------------------- el historial

test('the history comes in the three groups the app reads', function () {
    $cadete = unCadeteDelPerfil();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    expect($respuesta->json('historial.hoy'))->toBeArray()
        ->and($respuesta->json('historial.ultimos_dias'))->toHaveCount(HistorialDelCadete::DIAS)
        ->and($respuesta->json('historial.ultimos_meses'))->toHaveCount(HistorialDelCadete::MESES);
});

test('tiempo_activo keeps the names and types the app declares', function () {
    $cadete = unCadeteDelPerfil();

    ActividadCadete::factory()->create([
        'cadete_id' => $cadete->id,
        'inicio' => Carbon::today()->setTime(8, 0),
        'fin' => Carbon::today()->setTime(11, 30),
        'duracion' => 3 * 3_600 + 30 * 60,
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    // ⚠️ La app lee `tiempo_activo.d`, `.h` y `.i`, y declara `days: boolean`. Allá esto era
    // un `DateInterval` serializado por `json_encode`; PHP 8.2 borró siete de sus
    // propiedades, así que la forma se arma a mano y éstos son los nombres que importan.
    $tiempo = $respuesta->json('historial.hoy.tiempo_activo');

    expect($tiempo['d'])->toBe(0)
        ->and($tiempo['h'])->toBe(3)
        ->and($tiempo['i'])->toBe(30)
        ->and($tiempo['s'])->toBe(0)
        ->and($tiempo['days'])->toBeFalse()
        ->and($tiempo)->toHaveKeys(['y', 'm', 'd', 'h', 'i', 's', 'f', 'invert', 'days']);
});

test('the day in progress counts, so a cadete at work does not read 0m', function () {
    $this->freezeTime();

    $cadete = unCadeteDelPerfil(['estado' => Cadete::ESTADO_ACTIVO_APP]);

    // Abierto: `duracion` es NULL hasta que se cierra.
    ActividadCadete::factory()->create([
        'cadete_id' => $cadete->id,
        'inicio' => Carbon::now()->subHours(2)->subMinutes(15),
        'fin' => null,
        'duracion' => null,
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    expect($respuesta->json('historial.hoy.tiempo_activo.h'))->toBe(2)
        ->and($respuesta->json('historial.hoy.tiempo_activo.i'))->toBe(15);
});

test('a stretch left open years ago does not add years to a month that already closed', function () {
    $cadete = unCadeteDelPerfil();

    // ⚠️ Hay 113.065 de éstos, del bug de PANEL-17: el fin se escribía sólo en un camino.
    // Calcularlos contra «ahora» le sumaría años a cada mes del historial.
    ActividadCadete::factory()->create([
        'cadete_id' => $cadete->id,
        'inicio' => Carbon::today()->subMonths(2),
        'fin' => null,
        'duracion' => null,
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    $hace2 = collect($respuesta->json('historial.ultimos_meses'))
        ->firstWhere('mes', Carbon::today()->subMonths(2)->month);

    expect($hace2['tiempo_activo']['d'])->toBe(0)
        ->and($hace2['tiempo_activo']['h'])->toBe(0);
});

test('the money adds up and travels in one format', function () {
    $cadete = unCadeteDelPerfil();

    foreach (['3000.00', '4500.50'] as $valor) {
        Pedido::factory()->create([
            'cadete_id' => $cadete->id,
            'estado' => Pedido::ESTADO_FINALIZADO,
            'valor' => $valor,
            'created_at' => Carbon::today()->setTime(10, 0),
        ]);
    }

    // Y uno que no cuenta: la pantalla dice lo que el cadete hizo, no lo que le asignaron.
    Pedido::factory()->create([
        'cadete_id' => $cadete->id,
        'estado' => Pedido::ESTADO_EN_CURSO,
        'valor' => '9000.00',
        'created_at' => Carbon::today()->setTime(11, 0),
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    expect($respuesta->json('historial.hoy.cant_pedidos'))->toBe(2)
        // Sin los dos ceros de atrás, como en toda pantalla del proyecto: la app lo imprime
        // tal cual. Ver `Pedido::montoParaMostrar`.
        ->and($respuesta->json('historial.hoy.recaudacion'))->toBe('7500.5');
});

test('a day with nothing is zeros and not a hole', function () {
    $cadete = unCadeteDelPerfil();

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    // La app imprime `$ {{ dia.recaudacion }}` sin mirar si vino: con null diría «$ ».
    foreach ($respuesta->json('historial.ultimos_dias') as $dia) {
        expect($dia['recaudacion'])->toBe('0')
            ->and($dia['cant_pedidos'])->toBe(0)
            ->and($dia['tiempo_activo']['i'])->toBe(0);
    }
});

test('an order at 23:30 belongs to its own day and not to the next one', function () {
    $cadete = unCadeteDelPerfil();

    // ⚠️ `date(created_at)` corta el día en la zona de la aplicación porque la conexión sale
    // con `set time zone`. Si eso cambiara, los pedidos de la noche se irían al día
    // siguiente y nadie se daría cuenta. Es el mismo test que sujeta las estadísticas.
    Pedido::factory()->create([
        'cadete_id' => $cadete->id,
        'estado' => Pedido::ESTADO_FINALIZADO,
        'valor' => '3000.00',
        'created_at' => Carbon::today()->subDay()->setTime(23, 30),
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    expect($respuesta->json('historial.hoy.cant_pedidos'))->toBe(0)
        ->and($respuesta->json('historial.ultimos_dias.0.cant_pedidos'))->toBe(1);
});

test('the months start at the current one, which the old system skipped the first five days', function () {
    // ⚠️ Allá los meses arrancan en el mes del ÚLTIMO de los cinco días, porque reusaba la
    // misma variable y ya le había restado cinco: del 1 al 5 de cada mes el mes en curso no
    // aparecía en la lista y el cadete no veía nada de lo que había hecho ese mes.
    $this->travelTo(Carbon::parse('2026-03-03 10:00:00'));

    $cadete = unCadeteDelPerfil();

    Pedido::factory()->create([
        'cadete_id' => $cadete->id,
        'estado' => Pedido::ESTADO_FINALIZADO,
        'valor' => '3000.00',
        'created_at' => Carbon::parse('2026-03-02 09:00:00'),
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    $meses = collect($respuesta->json('historial.ultimos_meses'));

    expect($meses->first()['mes'])->toBe(3)
        ->and($meses->first()['cant_pedidos'])->toBe(1)
        ->and($meses->last()['mes'])->toBe(10);
});

test('the history asks the database and does not drag every order of the cadete', function () {
    $cadete = unCadeteDelPerfil();

    Pedido::factory()->count(30)->create([
        'cadete_id' => $cadete->id,
        'estado' => Pedido::ESTADO_FINALIZADO,
        'valor' => '3000.00',
        'created_at' => Carbon::today()->subYears(2),
    ]);

    // ⚠️ El sistema viejo hacía `$cadete->pedidos` y filtraba en PHP: para el cadete con más
    // viajes son 11.966 filas traídas enteras, en cada entrada y en cada refresco, para
    // informar doce números. Lo que se mide acá es que lo viejo no entra a memoria.
    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    $this->actingAs($cadete->user, 'sanctum')->getJson('/api/historial')->assertOk();

    expect(Pedido::query()->where('cadete_id', $cadete->id)->count())->toBe(30)
        ->and($consultas)->toBeLessThan(15);
});
