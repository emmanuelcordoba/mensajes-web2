<?php

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;

/*
| La pantalla de Clientes de /panel.
|
| Las reglas de la baja ya estaban portadas y probadas en BajaDeCliente; lo que se
| comprueba acá es que esta pantalla las respete y que las diga antes de intentarlas.
*/

test('an employee reaches the listing and a shop does not', function () {
    Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->component('clientes/index'));

    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/clientes')
        ->assertForbidden();
});

test('the push token of a client never reaches the screen', function () {
    // Con ese token se le mandan notificaciones al teléfono de esa persona.
    $cliente = Cliente::factory()->create(['fcm_token' => 'token-secreto-del-telefono']);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes')
        ->assertDontSee('token-secreto-del-telefono');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get("/panel/clientes/{$cliente->id}/edit")
        ->assertDontSee('token-secreto-del-telefono');
});

test('the number is assigned by the database and refused in the form', function () {
    // ⚠️ Sale de `clientes_numero_seq`. Si el panel dejara escribirlo, la secuencia no
    // avanzaría y el choque aparecería en el alta de OTRO cliente.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/clientes', [
            'nombre' => 'Kiosco de la esquina',
            'direccion' => 'San Martín 1200',
            'telefono' => '3814643232',
            'numero' => 9999,
        ])
        ->assertSessionHasErrors('numero');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->post('/panel/clientes', [
            'nombre' => 'Kiosco de la esquina',
            'direccion' => 'San Martín 1200',
            'telefono' => '3814643232',
        ])
        ->assertRedirect('/panel/clientes');

    expect(Cliente::query()->where('nombre', 'Kiosco de la esquina')->sole()->numero)
        ->toBeGreaterThan(0);
});

test('a number taken by a client that was given up stays taken', function () {
    $baja = Cliente::factory()->create();
    $numero = $baja->numero;
    $baja->delete();

    $cliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/clientes/{$cliente->id}", [
            'nombre' => $cliente->nombre,
            'direccion' => $cliente->direccion,
            'telefono' => $cliente->telefono,
            'numero' => $numero,
        ])
        ->assertSessionHasErrors('numero');
});

test('searching by name finds the clients that came from the app', function () {
    // ⚠️ El listado viejo buscaba por `nombre`, que queda vacío en los 7.139 clientes que
    // vinieron de la app: el nombre de ésos sale de `nombres` y `apellidos`. O sea que
    // buscarlos por nombre no los encontraba nunca. Acá se busca por `nombre_mostrado`,
    // que es la columna generada que resuelve los dos casos, y la que tiene índice.
    $deLaApp = Cliente::factory()->deLaApp()->create([
        'nombres' => 'Marisol',
        'apellidos' => 'Quiroga',
    ]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes?campo=nombre&buscar=Quiroga')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('clientes.total', 1)
            ->where('clientes.data.0.id', $deLaApp->id)
        );
});

test('searching by number with text does not fall back to zero', function () {
    // El mismo error que tenía el buscador de comercios: `(int)` sobre un texto da 0.
    Cliente::factory()->create(['numero' => 0]);

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes?campo=numero&buscar=farmacia')
        ->assertInertia(fn ($pagina) => $pagina->where('clientes.total', 0));
});

test('the origin filter separates the app from the office', function () {
    $deLaApp = Cliente::factory()->deLaApp()->create();
    $delPanel = Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes?origen=app')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('clientes.total', 1)
            ->where('clientes.data.0.id', $deLaApp->id)
        );

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/clientes?origen=panel')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('clientes.total', 1)
            ->where('clientes.data.0.id', $delPanel->id)
        );
});

test('an employee does not give a client up, only an administrator does', function () {
    // El mismo recorte que el sistema viejo hacía con `rol:admin` sobre la ruta: la baja
    // se lleva los pedidos del cliente y su cuenta.
    $cliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->delete("/panel/clientes/{$cliente->id}")
        ->assertForbidden();

    expect($cliente->refresh()->trashed())->toBeFalse();
});

test('giving a client up takes its orders and its account with it', function () {
    $cuenta = User::factory()->create();
    $cliente = Cliente::factory()->create(['user_id' => $cuenta->id]);
    Pedido::factory()->count(2)->create(['cliente_id' => $cliente->id]);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/clientes/{$cliente->id}")
        ->assertRedirect('/panel/clientes');

    expect($cliente->refresh()->trashed())->toBeTrue()
        ->and($cuenta->refresh()->trashed())->toBeTrue()
        ->and(Pedido::query()->where('cliente_id', $cliente->id)->count())->toBe(0);
});

test('a client assigned to a panel account is refused, and the listing says why', function () {
    // ⚠️ PANEL-6. Esa cuenta no es la del cliente: es otra distinta, y si el cliente se va
    // por debajo entra al panel y ve los campos vacíos sin que nada se lo explique.
    $comercio = Cliente::factory()->create();
    usuarioCon(Rol::RESTRINGIDO, $comercio);

    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->delete("/panel/clientes/{$comercio->id}")
        ->assertForbidden();

    expect($comercio->refresh()->trashed())->toBeFalse();

    // Y el listado lo dice en lugar de ofrecer un botón que iba a fallar.
    $this->actingAs(usuarioCon(Rol::ADMIN))
        ->get('/panel/clientes')
        ->assertInertia(function ($pagina) use ($comercio) {
            $filas = collect($pagina->toArray()['props']['clientes']['data'])->keyBy('id');

            expect($filas[$comercio->id]['puede_darse_de_baja'])->toBeFalse()
                ->and($filas[$comercio->id]['por_que_no'])->toContain('cuenta de panel');
        });
});

test('the name the panel writes wins over the one the app wrote', function () {
    // `nombre_mostrado` lo calcula PostgreSQL: gana `nombre`, y si está vacío usa nombres
    // y apellidos. Es lo que DATA-8 vino a arreglar.
    $cliente = Cliente::factory()->deLaApp()->create([
        'nombres' => 'Ana',
        'apellidos' => 'Pérez',
    ]);

    expect($cliente->refresh()->nombre_mostrado)->toBe('Ana Pérez');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->patch("/panel/clientes/{$cliente->id}", [
            'nombre' => 'Farmacia del Pueblo',
            'direccion' => $cliente->direccion,
            'telefono' => $cliente->telefono,
            'numero' => $cliente->numero,
        ])
        ->assertRedirect('/panel/clientes');

    expect($cliente->refresh()->nombre_mostrado)->toBe('Farmacia del Pueblo');
});
