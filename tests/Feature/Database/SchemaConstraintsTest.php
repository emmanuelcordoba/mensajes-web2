<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| These tests write straight to the tables, bypassing models and validation,
| to prove that the database itself enforces each rule. Every rejected write
| runs inside DB::transaction(), which opens a savepoint: a failed statement
| would otherwise abort the test's whole transaction, and every later query
| would fail for the wrong reason.
*/

function schemaInsertUser(array $attributes = []): int
{
    return DB::table('users')->insertGetId($attributes + [
        'name' => 'Usuario de prueba',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'secret',
    ]);
}

function schemaInsertCadete(array $attributes = []): int
{
    return DB::table('cadetes')->insertGetId($attributes + [
        'numero_movil' => fake()->unique()->numberBetween(1, 99999),
        'apellidos' => 'Pérez',
        'nombres' => 'Juan',
        'direccion' => 'San Martín 123',
        'telefono' => '3815000000',
        'fecha_nacimiento' => '1990-01-01',
        'estado' => 'inactivo',
        'tipo_vehiculo' => 'M',
        'user_id' => $attributes['user_id'] ?? schemaInsertUser(),
    ]);
}

function schemaInsertCliente(array $attributes = []): int
{
    return DB::table('clientes')->insertGetId($attributes + [
        'nombre' => 'Farmacia del Centro',
        'direccion' => 'Mendoza 456',
        'telefono' => '3814000000',
    ]);
}

function schemaInsertPedido(array $attributes = []): int
{
    return DB::table('pedidos')->insertGetId($attributes + [
        'plataforma_origen' => 'web',
        'estado' => 'Sin asignar',
    ]);
}

test('emails are only stored lowercase and without surrounding spaces', function () {
    expect(fn () => DB::transaction(fn () => schemaInsertUser(['email' => 'Juan@Mail.com'])))
        ->toThrow(QueryException::class, 'users_email_normalizado_check');

    expect(fn () => DB::transaction(fn () => schemaInsertUser(['email' => ' juan@mail.com'])))
        ->toThrow(QueryException::class, 'users_email_normalizado_check');

    expect(schemaInsertUser(['email' => 'juan@mail.com']))->toBeInt();
});

test('two users cannot share an email', function () {
    schemaInsertUser(['email' => 'juan@mail.com']);

    expect(fn () => DB::transaction(fn () => schemaInsertUser(['email' => 'juan@mail.com'])))
        ->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('a user can only be blocked for one of the known reasons', function () {
    expect(DB::table('motivos_bloqueo')->orderBy('codigo')->pluck('codigo')->all())
        ->toBe(['falta-de-pago', 'otro-motivo', 'reclamo-pedido']);

    // The value BloqueoCadetePedidoEnCurso used to write (PANEL-12).
    expect(fn () => DB::transaction(fn () => schemaInsertUser(['bloqueado' => 'bloqueado'])))
        ->toThrow(QueryException::class, 'SQLSTATE[23503]');

    expect(schemaInsertUser(['bloqueado' => 'falta-de-pago']))->toBeInt();
});

test('orders only take the known states', function () {
    expect(fn () => DB::transaction(fn () => schemaInsertPedido(['estado' => 'Entregado'])))
        ->toThrow(QueryException::class, 'pedidos_estado_check');

    expect(fn () => DB::transaction(fn () => schemaInsertPedido(['plataforma_origen' => 'whatsapp'])))
        ->toThrow(QueryException::class, 'pedidos_plataforma_origen_check');
});

test('order and client numbers come from their own sequences', function () {
    $pedidos = [schemaInsertPedido(), schemaInsertPedido()];
    $numerosDePedido = DB::table('pedidos')->whereIn('id', $pedidos)->orderBy('id')->pluck('numero')->all();

    $clientes = [schemaInsertCliente(), schemaInsertCliente()];
    $numerosDeCliente = DB::table('clientes')->whereIn('id', $clientes)->orderBy('id')->pluck('numero')->all();

    expect($numerosDePedido[1])->toBe($numerosDePedido[0] + 1)
        ->and($numerosDeCliente[1])->toBe($numerosDeCliente[0] + 1);
});

test('client numbers and cadete DNIs cannot repeat', function () {
    schemaInsertCliente(['numero' => 701]);
    expect(fn () => DB::transaction(fn () => schemaInsertCliente(['numero' => 701])))
        ->toThrow(QueryException::class, 'SQLSTATE[23505]');

    schemaInsertCadete(['dni' => 30111222]);
    expect(fn () => DB::transaction(fn () => schemaInsertCadete(['dni' => 30111222])))
        ->toThrow(QueryException::class, 'SQLSTATE[23505]');

    // Emptying the DNI is one way to solve a repeated one, so NULLs never clash.
    expect(schemaInsertCadete(['dni' => null]))->toBeInt()
        ->and(schemaInsertCadete(['dni' => null]))->toBeInt();
});

test('a cadete starts with weekly collection and a known vehicle', function () {
    $cadete = schemaInsertCadete();

    expect(DB::table('cadetes')->where('id', $cadete)->value('modalidad_cobranza'))->toBe('Semanal');

    expect(fn () => DB::transaction(fn () => schemaInsertCadete(['tipo_vehiculo' => 'A'])))
        ->toThrow(QueryException::class, 'cadetes_tipo_vehiculo_check');
});

test('an order is discounted from a balance only once', function () {
    $cadete = schemaInsertCadete();
    $pedido = schemaInsertPedido();
    $descuento = [
        'cadete_id' => $cadete,
        'monto' => 1500,
        'monto_positivo' => false,
        'tipo' => 'Pedido finalizado',
        'pedido_id' => $pedido,
    ];

    DB::table('cobranza_saldo_movimientos')->insert($descuento);

    // COB-1: the old system charged 438 orders twice.
    expect(fn () => DB::transaction(fn () => DB::table('cobranza_saldo_movimientos')->insert($descuento)))
        ->toThrow(QueryException::class, 'cobranza_mov_un_descuento_por_pedido');

    $carga = ['cadete_id' => $cadete, 'monto' => 5000, 'monto_positivo' => true, 'tipo' => 'Carga de saldo'];
    DB::table('cobranza_saldo_movimientos')->insert([$carga, $carga]);

    expect(DB::table('cobranza_saldo_movimientos')->count())->toBe(3);
});

test('numeric settings only hold a non-negative number', function () {
    $ajuste = ['titulo' => 'Costo mínimo', 'tipo' => 'numero'];

    // PANEL-16: the panel used to accept text, and quoting turned it into 0.
    expect(fn () => DB::transaction(fn () => DB::table('configuraciones')->insert($ajuste + ['nombre' => 'COSTO_MINIMO', 'valor' => 'abc'])))
        ->toThrow(QueryException::class, 'configuraciones_valor_numerico_check');

    DB::table('configuraciones')->insert($ajuste + ['nombre' => 'COSTO_MINIMO', 'valor' => '1200.50']);

    expect(DB::table('configuraciones')->value('valor'))->toBe('1200.50');
});

test('an application has at most one document of each kind', function () {
    $postulacion = DB::table('postulaciones')->insertGetId([
        'nombres' => 'Ana',
        'apellidos' => 'Gómez',
        'dni' => 35123456,
        'fecha_nacimiento' => '1995-05-05',
        'direccion' => 'Las Piedras 789',
        'telefono' => '3816000000',
        'email' => 'ana@mail.com',
        'tipo_vehiculo' => 'B',
    ]);
    $foto = ['postulacion_id' => $postulacion, 'tipo' => 'foto', 'ruta_archivo' => 'postulaciones/1/foto.png'];

    DB::table('postulacion_documentos')->insert($foto);

    expect(fn () => DB::transaction(fn () => DB::table('postulacion_documentos')->insert($foto)))
        ->toThrow(QueryException::class, 'SQLSTATE[23505]');

    expect(fn () => DB::transaction(fn () => DB::table('postulacion_documentos')->insert(['tipo' => 'selfie', 'ruta_archivo' => 'postulaciones/1/selfie.png'] + $foto)))
        ->toThrow(QueryException::class, 'postulacion_documentos_tipo_check');
});

test('the shown name comes from the panel name, or from the person when there is none', function () {
    $nombreMostrado = fn (int $id) => DB::table('clientes')->where('id', $id)->value('nombre_mostrado');

    // A shop: the panel gave it a name and the app never touches it.
    $comercio = schemaInsertCliente(['nombre' => 'Kiosco 24']);

    // Born in the app: no name of its own, so the person's name is shown.
    $persona = schemaInsertCliente(['nombre' => null, 'nombres' => 'Ana', 'apellidos' => 'Pérez', 'plataforma' => 'app']);

    // DATA-8: the app used to overwrite the name of a shop that signed up.
    $ambos = schemaInsertCliente(['nombre' => 'Farmacia San Juan', 'nombres' => 'Luis', 'apellidos' => 'Gómez', 'plataforma' => 'app']);

    expect($nombreMostrado($comercio))->toBe('Kiosco 24')
        ->and($nombreMostrado($persona))->toBe('Ana Pérez')
        ->and($nombreMostrado($ambos))->toBe('Farmacia San Juan');

    // Editing the profile in the app moves the shown name only where it should.
    DB::table('clientes')->whereIn('id', [$persona, $ambos])->update(['apellidos' => 'López']);

    expect($nombreMostrado($persona))->toBe('Ana López')
        ->and($nombreMostrado($ambos))->toBe('Farmacia San Juan');
});

test('the shown name never keeps spare spaces nor comes out empty', function () {
    $nombreMostrado = fn (int $id) => DB::table('clientes')->where('id', $id)->value('nombre_mostrado');

    // A single double space kept the panel from linking an order (DATA-8).
    $conEspacios = schemaInsertCliente(['nombre' => null, 'nombres' => '  Ana  ', 'apellidos' => 'Pérez', 'plataforma' => 'app']);

    // A blank name is treated as absent, not as a name made of spaces.
    $enBlanco = schemaInsertCliente(['nombre' => '   ', 'nombres' => 'Eva', 'apellidos' => 'Díaz', 'plataforma' => 'app']);

    expect($nombreMostrado($conEspacios))->toBe('Ana Pérez')
        ->and($nombreMostrado($enBlanco))->toBe('Eva Díaz');

    // Neither side filled in: the client would have no name at all.
    expect(fn () => DB::transaction(fn () => schemaInsertCliente(['nombre' => null])))
        ->toThrow(QueryException::class, 'SQLSTATE[23502]');
});

test('an image row holds a relative path, and no two rows share a file', function () {
    $foto = fn (string $ruta) => ['user_id' => schemaInsertUser(), 'ruta_archivo' => $ruta];

    // These paths are joined to a root directory to serve the file (PERF-2).
    foreach (['/etc/passwd', 'fotos/../../etc/passwd', '', 'fotos/mi foto.png'] as $ruta) {
        expect(fn () => DB::transaction(fn () => DB::table('user_fotos')->insert($foto($ruta))))
            ->toThrow(QueryException::class, 'user_fotos_ruta_relativa_check');
    }

    DB::table('user_fotos')->insert($foto('user-fotos/2026/09/1540.jpg'));

    // Deleting one row would leave the other pointing at nothing.
    expect(fn () => DB::transaction(fn () => DB::table('user_fotos')->insert($foto('user-fotos/2026/09/1540.jpg'))))
        ->toThrow(QueryException::class, 'SQLSTATE[23505]');
});
