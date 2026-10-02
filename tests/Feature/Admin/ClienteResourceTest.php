<?php

use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Services\BajaDeCliente;
use Livewire\Livewire;

/*
| La pantalla de clientes de /admin.
|
| Lo que más vale acá es la baja. Dar de baja un cliente no es borrar una fila: se lleva
| puestos sus pedidos y su cuenta de la app, y hay un caso en que no se puede hacer.
*/

beforeEach(function () {
    $this->actingAs(usuarioCon(Rol::ADMIN));
});

test('the push token of a client is never on the screen', function () {
    // ⚠️ El `--generate` lo pone, porque es una columna más. Con ese token se le mandan
    // notificaciones al teléfono de esa persona.
    Livewire::test(CreateCliente::class)
        ->assertFormFieldDoesNotExist('fcm_token');
});

test('the number is assigned by the database, not typed in', function () {
    // ⚠️ El panel viejo dejaba escribirlo. Acá sale de `clientes_numero_seq`: si se
    // escribe a mano la secuencia no avanza, y el choque aparece después, en el alta de
    // OTRO cliente, que es donde nadie lo va a entender.
    Livewire::test(CreateCliente::class)
        ->assertFormFieldDoesNotExist('numero')
        ->fillForm([
            'nombre' => 'Kiosco de la esquina',
            'direccion' => 'San Martín 1200',
            'telefono' => '3814643232',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Cliente::query()->where('nombre', 'Kiosco de la esquina')->sole()->numero)
        ->toBeGreaterThan(0);
});

test('the number can be corrected when editing, but not onto one already taken', function () {
    $otro = Cliente::factory()->create();
    $cliente = Cliente::factory()->create();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['numero' => $otro->numero])
        ->call('save')
        ->assertHasFormErrors(['numero']);
});

test('a number taken by a client that was given up stays taken', function () {
    // ⚠️ Mismo caso que con los emails: `clientes.numero` es UNIQUE a secas, así que un
    // cliente dado de baja sigue ocupando su número.
    $baja = Cliente::factory()->create();
    $numero = $baja->numero;
    $baja->delete();

    $cliente = Cliente::factory()->create();

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['numero' => $numero])
        ->call('save')
        ->assertHasFormErrors(['numero']);
});

test('the name the system reads is the one the panel writes', function () {
    // `nombre_mostrado` lo calcula PostgreSQL: gana `nombre`, y si está vacío usa
    // nombres y apellidos. No se escribe desde el formulario.
    $cliente = Cliente::factory()->create([
        'nombre' => null,
        'nombres' => 'Ana',
        'apellidos' => 'Pérez',
    ]);

    expect($cliente->refresh()->nombre_mostrado)->toBe('Ana Pérez');

    Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
        ->fillForm(['nombre' => 'Farmacia del Pueblo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cliente->refresh()->nombre_mostrado)->toBe('Farmacia del Pueblo');
});

test('giving up a client takes its orders and its app account with it', function () {
    $cuenta = User::factory()->create();
    $cliente = Cliente::factory()->create(['user_id' => $cuenta->id]);
    $pedidos = Pedido::factory()->count(3)->create(['cliente_id' => $cliente->id]);

    $llevo = (new BajaDeCliente)->dar($cliente);

    expect($llevo['pedidos'])->toBe(3)
        ->and($llevo['cuenta'])->toBe($cuenta->email)
        ->and($cliente->refresh()->trashed())->toBeTrue()
        ->and($cuenta->refresh()->trashed())->toBeTrue()
        ->and(Pedido::query()->whereIn('id', $pedidos->modelKeys())->count())->toBe(0);
});

test('a client assigned to a panel account cannot be given up', function () {
    // ⚠️ PANEL-6. La cuenta `restringido` que lo tiene asignado NO es la cuenta del
    // cliente: es otra distinta. Si el cliente se va por debajo, esa cuenta entra al
    // panel, ve los campos vacíos y en sólo lectura, y nada le explica por qué.
    $comercio = Cliente::factory()->create();
    usuarioCon(Rol::RESTRINGIDO, $comercio);

    expect(BajaDeCliente::porQueNo($comercio))->toContain('cuenta de panel');

    expect(fn () => (new BajaDeCliente)->dar($comercio))
        ->toThrow(RuntimeException::class);

    expect($comercio->refresh()->trashed())->toBeFalse();
});

test('the listing and the rule agree on whether a client can be given up', function () {
    // Una regla, dos lectores: el botón pregunta lo mismo que el servicio aplica. El que
    // se desincroniza en silencio es el listado, que ofrece algo que después falla.
    $sePuede = Cliente::factory()->create();
    $noSePuede = Cliente::factory()->create();
    usuarioCon(Rol::RESTRINGIDO, $noSePuede);

    Livewire::test(ListClientes::class)
        ->callTableAction('baja', $noSePuede)
        ->assertNotified();

    expect($noSePuede->refresh()->trashed())->toBeFalse();

    Livewire::test(ListClientes::class)
        ->callTableAction('baja', $sePuede);

    expect($sePuede->refresh()->trashed())->toBeTrue();
});

test('the origin filter separates the app from the office', function () {
    $deLaApp = Cliente::factory()->create(['plataforma' => Cliente::PLATAFORMA_APP]);
    $delPanel = Cliente::factory()->create(['plataforma' => null]);

    Livewire::test(ListClientes::class)
        ->filterTable('plataforma', Cliente::PLATAFORMA_APP)
        ->assertCanSeeTableRecords([$deLaApp])
        ->assertCanNotSeeTableRecords([$delPanel])
        ->filterTable('plataforma', 'panel')
        ->assertCanSeeTableRecords([$delPanel])
        ->assertCanNotSeeTableRecords([$deLaApp]);
});
