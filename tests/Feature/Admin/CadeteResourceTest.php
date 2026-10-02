<?php

use App\Filament\Resources\Cadetes\Pages\CreateCadete;
use App\Filament\Resources\Cadetes\Pages\ListCadetes;
use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\Pedido;
use App\Models\Postulacion;
use App\Models\Rol;
use App\Models\User;
use App\Services\BajaDeCadete;
use Livewire\Livewire;

/*
| La pantalla de cadetes de /admin.
|
| Dos cosas distintas de las otras dos pantallas: el alta escribe DOS filas —la cuenta y
| el cadete—, y la baja deja los pedidos donde están, porque son pedidos de otros.
*/

beforeEach(function () {
    $this->actingAs(usuarioCon(Rol::ADMIN));
    Rol::query()->firstOrCreate(['rol' => 'cadete'], ['display_rol' => 'Cadete']);
});

test('the operational columns the generator added are not on the screen', function () {
    // ⚠️ El `--generate` las pone porque son columnas. La ubicación en vivo y el token de
    // notificaciones no se editan a mano, `orden_cola` no es una posición sino un
    // timestamp Unix, y los montos se tocan desde las pantallas de cobranza, que tienen
    // sus reglas: cambiar una deuda acá es cómo se descuadra sin que nadie se entere.
    Livewire::test(CreateCadete::class)
        ->assertFormFieldDoesNotExist('fcm_token')
        ->assertFormFieldDoesNotExist('ubicacion_lat')
        ->assertFormFieldDoesNotExist('ubicacion_lon')
        ->assertFormFieldDoesNotExist('orden_cola')
        ->assertFormFieldDoesNotExist('monto_deuda')
        ->assertFormFieldDoesNotExist('cobranza_saldo')
        ->assertFormFieldDoesNotExist('user_id');
});

test('creating a cadete creates the account it needs to enter the app', function () {
    // ⚠️ `cadetes.user_id` es NOT NULL: no hay cadete sin cuenta. Son dos escrituras.
    Livewire::test(CreateCadete::class)
        ->fillForm([
            'nombres' => 'Rodrigo',
            'apellidos' => 'Sosa',
            'direccion' => 'San Martín 1200',
            'telefono' => '3814643232',
            'fecha_nacimiento' => '1995-04-12',
            'numero_movil' => 1724,
            'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
            'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
            'email' => 'Rodrigo@Mensajes.Test',
            'password' => 'una-contrasena-larga',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $cadete = Cadete::query()->where('numero_movil', 1724)->sole();

    expect($cadete->user)->not->toBeNull()
        ->and($cadete->user->email)->toBe('rodrigo@mensajes.test')
        ->and($cadete->user->rol->rol)->toBe('cadete')
        // Nace inactivo: lo activa él desde la app o la central desde la cola.
        ->and($cadete->estado)->toBe(Cadete::ESTADO_INACTIVO)
        // Sin modalidad no aparecería en ninguno de los dos listados de cobranzas, y no
        // habría forma de asignársela. Ver PANEL-8.
        ->and($cadete->modalidad_cobranza)->toBe(Cadete::COBRANZA_SEMANAL);
});

test('the role of the new account does not come from the form', function () {
    // ⚠️ SEC-4. En el sistema viejo el alta pasaba $request->all() al modelo, y como
    // rol_id está en el fillable de User alcanzaba con agregarlo al cuerpo para que la
    // cuenta de un cadete naciera administrador.
    $admin = Rol::query()->where('rol', Rol::ADMIN)->value('id');

    Livewire::test(CreateCadete::class)
        ->fillForm([
            'nombres' => 'Intruso',
            'apellidos' => 'Sosa',
            'direccion' => 'San Martín 1200',
            'telefono' => '3814643232',
            'fecha_nacimiento' => '1995-04-12',
            'numero_movil' => 1725,
            'tipo_vehiculo' => Cadete::VEHICULO_BICICLETA,
            'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
            'email' => 'intruso@mensajes.test',
            'password' => 'una-contrasena-larga',
            'rol_id' => $admin,
        ])
        ->call('create');

    expect(Cadete::query()->where('numero_movil', 1725)->sole()->user->rol->rol)
        ->toBe('cadete');
});

test('a mobile number taken by a cadete that was given up stays taken', function () {
    $baja = Cadete::factory()->create();
    $movil = $baja->numero_movil;
    $baja->delete();

    Livewire::test(CreateCadete::class)
        ->fillForm([
            'nombres' => 'Otro',
            'apellidos' => 'Sosa',
            'direccion' => 'San Martín 1200',
            'telefono' => '3814643232',
            'fecha_nacimiento' => '1995-04-12',
            'numero_movil' => $movil,
            'tipo_vehiculo' => Cadete::VEHICULO_BICICLETA,
            'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
            'email' => 'otro@mensajes.test',
            'password' => 'una-contrasena-larga',
        ])
        ->call('create')
        ->assertHasFormErrors(['numero_movil']);
});

test('an impossible birth date does not get in', function () {
    // ⚠️ La columna es DATE NOT NULL sin CHECK de rango, así que la base acepta el año
    // 190: hay 32 cadetes con fechas imposibles, 41 personas contando las postulaciones
    // (DATA-10). El rango lo pone el formulario mientras eso no se arregle.
    Livewire::test(CreateCadete::class)
        ->fillForm(['fecha_nacimiento' => '0190-01-01'])
        ->call('create')
        ->assertHasFormErrors(['fecha_nacimiento']);
});

test('giving up a cadete leaves the orders alone', function () {
    // ⚠️ La diferencia con un cliente. Los pedidos de un cliente son suyos; los de un
    // cadete son pedidos DE OTROS que él entregó, con su valor y su garantía. Borrarlos
    // sería borrar facturación ajena.
    $cadete = Cadete::factory()->create();
    $pedidos = Pedido::factory()->count(3)->create(['cadete_id' => $cadete->id]);
    Mensaje::factory()->count(2)->create(['cadete_id' => $cadete->id]);

    $llevo = (new BajaDeCadete)->dar($cadete);

    expect($llevo['mensajes'])->toBe(2)
        ->and($cadete->refresh()->trashed())->toBeTrue()
        ->and(User::withTrashed()->find($cadete->user_id)->trashed())->toBeTrue()
        // Los pedidos siguen enteros.
        ->and(Pedido::query()->whereIn('id', $pedidos->modelKeys())->count())->toBe(3);
});

test('the file with the identity documents goes with the cadete', function () {
    $cadete = Cadete::factory()->create();
    Postulacion::factory()->create(['cadete_id' => $cadete->id]);

    $llevo = (new BajaDeCadete)->dar($cadete);

    expect($llevo['postulacion'])->toBeTrue()
        ->and(Postulacion::query()->where('cadete_id', $cadete->id)->count())->toBe(0);
});

test('a cadete with the collection still open is not given up', function (string $campo, string $valor, string $enElMensaje) {
    // ⚠️ La fila quedaría invisible para Eloquent y nadie volvería a reclamar esa plata.
    $cadete = Cadete::factory()->create([$campo => $valor]);

    expect(BajaDeCadete::porQueNo($cadete))->toContain($enElMensaje);

    expect(fn () => (new BajaDeCadete)->dar($cadete))->toThrow(RuntimeException::class);

    expect($cadete->refresh()->trashed())->toBeFalse();
})->with([
    'con deuda' => ['monto_deuda', '1500.00', 'una deuda'],
    'con saldo a favor' => ['cobranza_saldo', '800.00', 'a favor'],
    'con saldo en contra' => ['cobranza_saldo', '-800.00', 'en contra'],
]);

test('a cadete with the collection closed can be given up', function () {
    // Deuda en cero y saldo en cero: no hay nada que reclamar.
    $cadete = Cadete::factory()->create(['monto_deuda' => '0.00', 'cobranza_saldo' => '0.00']);

    expect(BajaDeCadete::porQueNo($cadete))->toBeNull();
});

test('the listing and the rule agree on whether a cadete can be given up', function () {
    $sePuede = Cadete::factory()->create();
    $noSePuede = Cadete::factory()->create(['monto_deuda' => '2500.00']);

    Livewire::test(ListCadetes::class)
        ->callTableAction('baja', $noSePuede)
        ->assertNotified();

    expect($noSePuede->refresh()->trashed())->toBeFalse();

    Livewire::test(ListCadetes::class)
        ->callTableAction('baja', $sePuede);

    expect($sePuede->refresh()->trashed())->toBeTrue();
});
