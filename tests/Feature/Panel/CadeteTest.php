<?php

use App\Models\ActividadCadete;
use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\MotivoBloqueo;
use App\Models\Postulacion;
use App\Models\Rol;
use App\Models\User;
use App\Services\BajaDeCadete;
use Illuminate\Support\Facades\Hash;

/*
| Los cadetes, desde /panel.
|
| Lo que esta pantalla tiene que garantizar no es que los campos se guarden —eso lo prueba
| cualquier formulario— sino tres cosas que el panel viejo no garantizaba: que un cadete
| nazca con su cuenta y con el rol que le corresponde y no con el que venga en el cuerpo
| (SEC-4), que la baja no se lleve los pedidos ni se haga con la cobranza abierta, y que lo
| que el listado ofrece sea exactamente lo que el servidor después acepta.
*/

/** El rol con el que entra a la app. Sin él, el alta no puede escribir la cuenta. */
function elRolDeCadete(): Rol
{
    return Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);
}

/**
 * Un cadete con su cuenta, como los crea el alta.
 *
 * @param  array<string, mixed>  $atributos
 * @param  array<string, mixed>  $deLaCuenta
 */
function unCadete(array $atributos = [], array $deLaCuenta = []): Cadete
{
    $cuenta = User::factory()->create(array_merge(['rol_id' => elRolDeCadete()->id], $deLaCuenta));

    return Cadete::factory()->create(array_merge($atributos, ['user_id' => $cuenta->id]));
}

/**
 * Un cuerpo válido para el alta.
 *
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosDeCadete(array $cambios = []): array
{
    return array_merge([
        'nombres' => 'Juan',
        'apellidos' => 'Pérez',
        'direccion' => 'San Martín 100',
        'telefono' => '3815550000',
        'fecha_nacimiento' => '1995-06-15',
        'dni' => '30111222',
        'numero_movil' => 777,
        'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
        'email' => 'juan.perez@example.com',
        'password' => '4821',
        'password_confirmation' => '4821',
        'observaciones' => 'Entra los martes.',
    ], $cambios);
}

test('an employee reaches the list and a shop does not', function () {
    unCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('cadetes/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/cadetes')
        ->assertForbidden();
});

test('the list sends the fields it needs and not the ones it must not', function () {
    // ⚠️ `cadetes` tiene la ubicación en vivo, el `fcm_token` —con el que se le manda una
    // notificación al teléfono de esa persona— y los cinco montos de cobranza. Mandar el
    // modelo entero es la forma de SEC-14.
    unCadete([
        'fcm_token' => 'un-token-de-firebase',
        'ubicacion_lat' => -26.8,
        'monto_deuda' => '1234.56',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes')
        ->assertInertia(function ($pagina) {
            $fila = $pagina->toArray()['props']['cadetes']['data'][0];

            expect(array_keys($fila))->not->toContain(
                'fcm_token',
                'ubicacion_lat',
                'ubicacion_lon',
                'monto_deuda',
                'cobranza_saldo',
                'orden_cola',
            )->and($fila)->toHaveKeys(['numero_movil', 'nombre', 'email', 'estado']);
        });
});

test('searching a word does not bring the mobile number zero', function () {
    // ⚠️ El listado viejo hacía `orWhere('numero_movil', intval($buscar))` suelto, y
    // `intval('juan')` es 0: buscar un nombre traía además al móvil 0.
    unCadete(['numero_movil' => 0, 'apellidos' => 'Quiroga', 'nombres' => 'Ana']);
    unCadete(['numero_movil' => 12, 'apellidos' => 'Juárez', 'nombres' => 'Luis']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes?buscar=juárez')
        ->assertInertia(function ($pagina) {
            $filas = $pagina->toArray()['props']['cadetes']['data'];

            expect($filas)->toHaveCount(1)
                ->and($filas[0]['numero_movil'])->toBe(12);
        });
});

test('the accent counts when searching, as it did in the old panel', function () {
    // ⚠️ Esto NO es lo que esta pantalla quisiera hacer: «juarez» no encuentra a «Juárez»,
    // y en este país eso es la mitad de los apellidos. `ilike` ignora las mayúsculas y no
    // los acentos; resolverlo de verdad es `unaccent` con su índice, que es una extensión y
    // una migración, y no se agrega al pasar. El panel viejo tenía exactamente el mismo
    // límite, así que esto no empeora nada —pero deja escrito qué falta y dónde—.
    unCadete(['apellidos' => 'Juárez', 'nombres' => 'Luis']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes?buscar=juarez')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['cadetes']['data'],
        )->toHaveCount(0));
});

test('searching a number finds the mobile', function () {
    unCadete(['numero_movil' => 440, 'apellidos' => 'Gómez']);
    unCadete(['numero_movil' => 441, 'apellidos' => 'López']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes?buscar=441')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['cadetes']['data'],
        )->toHaveCount(1));
});

test('creating one writes the cadete and its account, inactive and with a collection mode', function () {
    elRolDeCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes', datosDeCadete())
        ->assertRedirect('/panel/cadetes');

    $cadete = Cadete::query()->where('numero_movil', 777)->sole();

    expect($cadete->estado)->toBe(Cadete::ESTADO_INACTIVO)
        // ⚠️ Sin modalidad el cadete no aparece en ninguno de los dos listados de cobranzas
        // y no hay forma de asignársela desde el panel. Ver PANEL-8.
        ->and($cadete->modalidad_cobranza)->toBe(Cadete::COBRANZA_SEMANAL)
        // Entero, no texto: es lo que hacía imposible comparar DNIs en el sistema viejo.
        ->and($cadete->dni)->toBe(30111222)
        ->and($cadete->user?->email)->toBe('juan.perez@example.com')
        ->and($cadete->user?->rol?->rol)->toBe(Rol::CADETE);
});

test('the body cannot choose the role of the new account', function () {
    // ⚠️ SEC-4: en el panel viejo el alta pasaba `$request->all()` al modelo, y como
    // `rol_id` está en el `fillable` de User alcanzaba con agregar ese campo al cuerpo para
    // que la cuenta de un cadete naciera administrador.
    $admin = Rol::query()->firstOrCreate(['rol' => Rol::ADMIN], ['display_rol' => 'Admin']);
    elRolDeCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes', datosDeCadete(['rol_id' => $admin->id]))
        ->assertRedirect('/panel/cadetes');

    expect(Cadete::query()->where('numero_movil', 777)->sole()->user?->rol?->rol)
        ->toBe(Rol::CADETE);
});

test('a mobile number or a dni already taken does not get in, even by a cadete given up', function (string $campo, mixed $valor) {
    elRolDeCadete();

    $otro = unCadete(['numero_movil' => 777, 'dni' => 30111222]);
    (new BajaDeCadete)->dar($otro);

    expect($otro->fresh()?->trashed())->toBeTrue();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes', datosDeCadete([$campo => $valor]))
        ->assertSessionHasErrors($campo);

    expect(Cadete::query()->count())->toBe(0);
})->with([
    'el móvil' => ['numero_movil', 777],
    'el DNI' => ['dni', '30111222'],
]);

test('an impossible birth date does not get in', function (string $fecha) {
    // ⚠️ La columna es DATE NOT NULL sin CHECK de rango, así que la base acepta el año 190:
    // hay 32 cadetes con fechas imposibles, 41 personas contando las postulaciones
    // (DATA-10). El panel viejo no validaba más que `date`.
    elRolDeCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes', datosDeCadete(['fecha_nacimiento' => $fecha]))
        ->assertSessionHasErrors('fecha_nacimiento');
})->with([
    'el año 190' => '0190-01-01',
    'nacido ayer' => '2026-10-02',
    'más de 90 años' => '1900-01-01',
]);

test('editing writes what the screen edits and nothing else', function () {
    $cadete = unCadete([
        'numero_movil' => 50,
        'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
        'monto_deuda' => '900.00',
        'fcm_token' => 'un-token',
    ]);
    $hash = $cadete->user?->password;

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}", datosDeCadete([
            'numero_movil' => 50,
            'email' => $cadete->user?->email,
            'password' => '',
            'password_confirmation' => '',
            // Campos de más, de los que el `fillable` acepta: no los escribe ninguno.
            'monto_deuda' => '0.00',
            'modalidad_cobranza' => Cadete::COBRANZA_SALDO,
            'fcm_token' => 'otro-token',
            'estado' => Cadete::ESTADO_ACTIVO_APP,
        ]))
        ->assertRedirect('/panel/cadetes');

    $cadete->refresh();

    expect($cadete->nombres)->toBe('Juan')
        ->and($cadete->monto_deuda)->toBe('900.00')
        ->and($cadete->modalidad_cobranza)->toBe(Cadete::COBRANZA_SEMANAL)
        ->and($cadete->fcm_token)->toBe('un-token')
        ->and($cadete->estado)->toBe(Cadete::ESTADO_INACTIVO)
        // En blanco significa «no la toques»: sin esto, corregir un teléfono le cambiaría
        // la contraseña a la persona.
        ->and($cadete->user?->password)->toBe($hash);
});

test('a new password is stored hashed', function () {
    $cadete = unCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}", datosDeCadete([
            'numero_movil' => $cadete->numero_movil,
            'email' => $cadete->user?->email,
            'password' => '95130',
            'password_confirmation' => '95130',
        ]))
        ->assertSessionHasNoErrors();

    $guardada = (string) $cadete->user?->fresh()?->password;

    expect($guardada)->not->toBe('95130')
        ->and(Hash::check('95130', $guardada))->toBeTrue();
});

test('an email already taken by another account is rejected', function () {
    $otro = unCadete([], ['email' => 'ocupado@example.com']);
    $cadete = unCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}", datosDeCadete([
            'numero_movil' => $cadete->numero_movil,
            'email' => 'OCUPADO@example.com',
        ]))
        ->assertSessionHasErrors('email');

    expect($cadete->fresh()?->nombres)->not->toBe('Juan')
        ->and($otro->user?->fresh()?->email)->toBe('ocupado@example.com');
});

test('a shop cannot write at the routes either', function () {
    $cadete = unCadete();
    $comercio = usuarioCon(Rol::RESTRINGIDO);

    $this->actingAs($comercio)->post('/panel/cadetes', datosDeCadete())->assertForbidden();
    $this->actingAs($comercio)->patch("/panel/cadetes/{$cadete->id}", datosDeCadete())->assertForbidden();
    $this->actingAs($comercio)->delete("/panel/cadetes/{$cadete->id}")->assertForbidden();
    $this->actingAs($comercio)->patch("/panel/cadetes/{$cadete->id}/estado")->assertForbidden();
});

test('only an administrator gives a cadete up', function () {
    $cadete = unCadete();

    // ⚠️ SEC-6: la baja vivía en el grupo ajax, que hasta el 2026-08-20 incluía al rol
    // `restringido`, así que una cuenta de comercio podía dar de baja cadetes.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/cadetes/{$cadete->id}")
        ->assertForbidden();

    expect($cadete->fresh()?->trashed())->toBeFalse();

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$cadete->id}")
        ->assertRedirect('/panel/cadetes');

    expect($cadete->fresh()?->trashed())->toBeTrue();
});

test('an employee is not told ten times that they are not an administrator', function () {
    // ⚠️ El listado muestra el motivo de la negativa en lugar del botón, y eso sirve para
    // los motivos que esa persona puede ir a arreglar. «Sólo un administrador» repetido en
    // las diez filas no es información: es ruido en la columna de acciones.
    unCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes')
        ->assertInertia(function ($pagina) {
            $fila = $pagina->toArray()['props']['cadetes']['data'][0];

            expect($fila['baja']['permitido'])->toBeFalse()
                ->and($fila['baja']['motivo'])->toBeNull();
        });
});

test('giving one up takes its messages and its application, and leaves the orders', function () {
    $cadete = unCadete();
    Mensaje::factory()->count(3)->create(['cadete_id' => $cadete->id]);
    Postulacion::factory()->create(['cadete_id' => $cadete->id]);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$cadete->id}")
        ->assertSessionHas('success');

    expect(Mensaje::query()->where('cadete_id', $cadete->id)->count())->toBe(0)
        ->and(Postulacion::query()->where('cadete_id', $cadete->id)->count())->toBe(0)
        ->and(User::query()->whereKey($cadete->user_id)->count())->toBe(0);
});

test('a cadete with the collection open is not offered the button, and the route refuses it', function () {
    // ⚠️ La fila quedaría invisible para Eloquent y nadie volvería a reclamar esa plata.
    $cadete = unCadete(['monto_deuda' => '4500.00']);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/cadetes')
        ->assertInertia(function ($pagina) use ($cadete) {
            $fila = $pagina->toArray()['props']['cadetes']['data'][0];

            expect($fila['baja']['permitido'])->toBeFalse()
                // Una regla, dos lectores: el listado muestra el mismo motivo que el
                // servicio aplica antes de escribir.
                ->and($fila['baja']['motivo'])->toBe(BajaDeCadete::porQueNo($cadete));
        });

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/cadetes/{$cadete->id}")
        ->assertForbidden();

    expect($cadete->fresh()?->trashed())->toBeFalse();
});

test('putting one in the queue leaves it active with its arrival time', function () {
    $cadete = unCadete(['estado' => Cadete::ESTADO_INACTIVO, 'orden_cola' => null]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/estado")
        ->assertRedirect();

    $cadete->refresh();

    expect($cadete->estado)->toBe(Cadete::ESTADO_ACTIVO_WEB)
        ->and($cadete->orden_cola)->not->toBeNull();
});

test('taking one out of the queue closes the open stretch of activity', function () {
    // ⚠️ PANEL-17: el cierre vive en el `saving` del modelo, así que lo hace cualquier
    // camino que lo saque de la app. Antes lo escribía sólo `cambiarEstado()` y sólo desde
    // `activo-app` —la condición comparaba ese estado dos veces—, y el tramo quedaba
    // abierto para siempre: 112.779 sin cerrar en los datos migrados.
    $cadete = unCadete(['estado' => Cadete::ESTADO_ACTIVO_APP]);
    $actividad = ActividadCadete::factory()->abierta()->create(['cadete_id' => $cadete->id]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/estado")
        ->assertRedirect();

    expect($cadete->fresh()?->estado)->toBe(Cadete::ESTADO_INACTIVO)
        ->and($actividad->fresh()?->fin)->not->toBeNull();
});

test('a cadete with an order in hand can still be taken out', function () {
    // Es la forma que tiene la central de bajar a alguien que dejó el pedido colgado, y es
    // lo que hacía el panel viejo.
    $cadete = unCadete(['estado' => Cadete::ESTADO_CON_PEDIDO_APP]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/estado")
        ->assertRedirect();

    expect($cadete->fresh()?->estado)->toBe(Cadete::ESTADO_INACTIVO);
});

test('a blocked cadete is not offered the queue, and the route says why', function () {
    // ⚠️ El panel viejo comprobaba esto en silencio: `if($cadete->user->bloqueado == null)`,
    // y si estaba bloqueado el controlador redirigía sin hacer nada y sin decir nada.
    $cadete = unCadete(
        ['estado' => Cadete::ESTADO_INACTIVO],
        ['bloqueado' => MotivoBloqueo::FALTA_DE_PAGO],
    );

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes')
        ->assertInertia(function ($pagina) {
            $fila = $pagina->toArray()['props']['cadetes']['data'][0];

            expect($fila['cambiar_estado']['permitido'])->toBeFalse()
                ->and($fila['cambiar_estado']['motivo'])->toContain('bloqueado')
                ->and($fila['bloqueo'])->not->toBeNull();
        });

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/estado")
        ->assertForbidden();

    expect($cadete->fresh()?->estado)->toBe(Cadete::ESTADO_INACTIVO);
});

test('blocking writes the reason, and an invented one does not get in', function () {
    $cadete = unCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/bloqueo", ['motivo' => 'por-las-dudas'])
        ->assertSessionHasErrors('motivo');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/bloqueo", ['motivo' => MotivoBloqueo::FALTA_DE_PAGO])
        ->assertRedirect();

    expect($cadete->user?->fresh()?->bloqueado)->toBe(MotivoBloqueo::FALTA_DE_PAGO);
});

test('with other reason the message is required, and it is the only one that keeps it', function () {
    $cadete = unCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/bloqueo", ['motivo' => MotivoBloqueo::OTRO_MOTIVO])
        ->assertSessionHasErrors('mensaje');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/bloqueo", [
            'motivo' => MotivoBloqueo::OTRO_MOTIVO,
            'mensaje' => 'Pasá por administración.',
        ])
        ->assertRedirect();

    expect($cadete->user?->fresh()?->bloqueado_mensaje)->toBe('Pasá por administración.');

    // Con un motivo del catálogo el mensaje no corresponde: el que lee la app es la
    // descripción del motivo.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/bloqueo", [
            'motivo' => MotivoBloqueo::RECLAMO_PEDIDO,
            'mensaje' => 'Esto no se guarda.',
        ]);

    expect($cadete->user?->fresh()?->bloqueado_mensaje)->toBeNull();
});

test('unblocking clears the reason and the message', function () {
    // ⚠️ El desbloqueo viejo ponía `bloqueado` en null y dejaba `bloqueado_mensaje`
    // escrito, así que el motivo del bloqueo anterior quedaba colgado en la cuenta.
    $cadete = unCadete([], [
        'bloqueado' => MotivoBloqueo::OTRO_MOTIVO,
        'bloqueado_mensaje' => 'Un motivo viejo.',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/cadetes/{$cadete->id}/bloqueo")
        ->assertRedirect();

    $cuenta = $cadete->user?->fresh();

    expect($cuenta?->bloqueado)->toBeNull()
        ->and($cuenta?->bloqueado_mensaje)->toBeNull();
});

test('a cadete whose account is gone is not offered the block, and its email is not asked', function () {
    // ⚠️ Pasa aunque `cadetes.user_id` sea NOT NULL: `User` usa bajas lógicas, así que la
    // relación no devuelve la cuenta y el bloqueo no tiene dónde escribirse.
    $cadete = unCadete();
    $cadete->user?->delete();
    $cadete->unsetRelation('user');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/cadetes')
        ->assertInertia(function ($pagina) {
            $fila = $pagina->toArray()['props']['cadetes']['data'][0];

            expect($fila['email'])->toBeNull()
                ->and($fila['bloquear']['permitido'])->toBeFalse()
                ->and($fila['bloquear']['motivo'])->toContain('cuenta');
        });

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/cadetes/{$cadete->id}/bloqueo", ['motivo' => MotivoBloqueo::FALTA_DE_PAGO])
        ->assertForbidden();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/{$cadete->id}/edit")
        ->assertInertia(fn ($pagina) => $pagina->where('cadete.cuenta', null));
});

test('the edit screen does not send the password of the account', function () {
    $cadete = unCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/cadetes/{$cadete->id}/edit")
        ->assertInertia(function ($pagina) {
            $props = $pagina->toArray()['props']['cadete'];

            expect(array_keys($props))->not->toContain('password', 'fcm_token', 'monto_deuda');
        });
});

test('the password of a cadete is a numeric pin, because the counter is a numpad', function (string $password) {
    // ⚠️ La pantalla de la cola vive en un monitor al lado del mostrador y se maneja con
    // un teclado numérico: ahí el cadete escribe su móvil y su contraseña. Con un teclado
    // así no hay forma de escribir una letra, así que una contraseña con letras es una
    // cuenta que no puede ponerse en la cola. Ver App\Support\PinDeCadete.
    elRolDeCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes', datosDeCadete([
            'password' => $password,
            'password_confirmation' => $password,
        ]))
        ->assertSessionHasErrors('password');

    expect(Cadete::query()->count())->toBe(0);
})->with([
    'con letras' => 'una-contrasena-larga',
    'demasiado corta' => '12',
    'demasiado larga' => '123456789',
    'con un signo' => '+1234',
]);

test('a pin inside the range gets in', function (string $password) {
    elRolDeCadete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/cadetes', datosDeCadete([
            'password' => $password,
            'password_confirmation' => $password,
        ]))
        ->assertSessionHasNoErrors();

    expect(Cadete::query()->count())->toBe(1);
})->with([
    'el mínimo' => '123',
    'el máximo' => '12345678',
    'con un cero adelante' => '0123',
]);
