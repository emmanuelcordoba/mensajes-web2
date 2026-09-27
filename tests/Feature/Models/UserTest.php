<?php

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\MotivoBloqueo;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| El usuario, que es donde estaban los agujeros de seguridad: SEC-3, SEC-7, SEC-9 y
| SEC-10. Lo que se prueba acá es sobre todo que ya no estén.
*/

test('a user with no role does not blow up when asked about one', function () {
    // 20 usuarios activos no tienen rol_id, y la columna es nullable a propósito. El
    // método viejo hacía $this->rol->rol == $rol sobre una relación que puede faltar.
    $sinRol = User::factory()->create();

    expect($sinRol->tieneRol(Rol::ADMIN))->toBeFalse()
        ->and($sinRol->tieneRol([Rol::ADMIN, Rol::EMPLEADO]))->toBeFalse();

    $admin = User::factory()->create(['rol_id' => Rol::factory()->llamado(Rol::ADMIN)->create()->id]);

    expect($admin->tieneRol(Rol::ADMIN))->toBeTrue()
        ->and($admin->tieneRol([Rol::EMPLEADO, Rol::ADMIN]))->toBeTrue()
        ->and($admin->tieneRol(Rol::EMPLEADO))->toBeFalse();
});

test('a restricted account only reaches the orders of its own shop', function () {
    // SEC-7: el middleware de rol no alcanza para las rutas que un comercio comparte
    // con la administración. Sin esto, un comercio veía, imprimía y borraba los
    // pedidos de cualquier otro mandando el id.
    $restringido = Rol::factory()->llamado(Rol::RESTRINGIDO)->create();
    $comercio = Cliente::factory()->create();
    $otroComercio = Cliente::factory()->create();

    $cuenta = User::factory()->create([
        'rol_id' => $restringido->id,
        'cliente_restringido_id' => $comercio->id,
    ]);

    $propio = Pedido::factory()->create(['cliente_id' => $comercio->id]);
    $ajeno = Pedido::factory()->create(['cliente_id' => $otroComercio->id]);
    $sinCliente = Pedido::factory()->create(['cliente_id' => null]);

    expect($cuenta->alcanzaPedido($propio))->toBeTrue()
        ->and($cuenta->alcanzaPedido($ajeno))->toBeFalse()
        ->and($cuenta->alcanzaPedido($sinCliente))->toBeFalse();

    // Sin comercio asignado, ninguno: sin cliente no hay pertenencia que comprobar.
    $huerfana = User::factory()->create(['rol_id' => $restringido->id]);

    expect($huerfana->alcanzaPedido($propio))->toBeFalse();

    // Un empleado alcanza todos.
    $empleado = User::factory()->create([
        'rol_id' => Rol::factory()->llamado(Rol::EMPLEADO)->create()->id,
    ]);

    expect($empleado->alcanzaPedido($ajeno))->toBeTrue();
});

test('an admin account is only editable by another admin', function () {
    // SEC-9: si un empleado pudiera cambiarle la contraseña o el email a un admin,
    // todo lo que el panel reserva a admin quedaría a un paso de él.
    $admin = User::factory()->create(['rol_id' => Rol::factory()->llamado(Rol::ADMIN)->create()->id]);
    $empleado = User::factory()->create([
        'rol_id' => Rol::factory()->llamado(Rol::EMPLEADO)->create()->id,
    ]);
    $sinRol = User::factory()->create();

    expect($admin->puedeAdministrarA($empleado))->toBeTrue()
        ->and($admin->puedeAdministrarA($admin))->toBeTrue()
        ->and($empleado->puedeAdministrarA($admin))->toBeFalse()
        // Una cuenta sin rol no es admin, así que un empleado sí la puede tocar.
        ->and($empleado->puedeAdministrarA($sinRol))->toBeTrue();
});

test('a user with no pending code never matches one', function () {
    // SEC-3, la mitad importante: se comparaba con ==, y null == 0 es true en PHP. Un
    // usuario sin código pendiente aceptaba el número 0 como código válido, y el
    // validador no lo frenaba porque el entero 0 pasa `required`.
    $sinCodigo = User::factory()->create(['codigo_de_verificacion' => null]);

    expect($sinCodigo->codigoDeVerificacionCoincide(0))->toBeFalse()
        ->and($sinCodigo->codigoDeVerificacionCoincide('0'))->toBeFalse()
        ->and($sinCodigo->codigoDeVerificacionCoincide(null))->toBeFalse();
});

test('the code has to match exactly, not loosely', function () {
    $user = User::factory()->create(['codigo_de_verificacion' => 123_456]);

    expect($user->codigoDeVerificacionCoincide(123_456))->toBeTrue()
        ->and($user->codigoDeVerificacionCoincide('123456'))->toBeTrue()
        // Todos éstos daban true con ==.
        ->and($user->codigoDeVerificacionCoincide('123456abc'))->toBeFalse()
        ->and($user->codigoDeVerificacionCoincide(' 123456'))->toBeFalse()
        ->and($user->codigoDeVerificacionCoincide('0123456'))->toBeFalse()
        // Un arreglo pasa `required` y castearlo sería un error de PHP.
        ->and($user->codigoDeVerificacionCoincide(['123456']))->toBeFalse();
});

test('the verification code never travels in a serialised user', function () {
    // SEC-10: viajaba en todo JSON que incluyera un usuario —el pedido que ve el
    // cadete trae a quien lo cargó—, y con ese código se le cambia la contraseña.
    $user = User::factory()->create(['codigo_de_verificacion' => 123_456]);

    expect($user->toArray())->not->toHaveKey('codigo_de_verificacion')
        ->and($user->toArray())->not->toHaveKey('password');
});

test('a blocked account can only be blocked for a known reason', function () {
    // En el sistema viejo el motivo era texto libre. PANEL-12 salió de ahí: el bloqueo
    // automático comparaba contra un campo que no existía.
    $user = User::factory()->create(['bloqueado' => MotivoBloqueo::FALTA_DE_PAGO]);

    expect($user->estaBloqueado())->toBeTrue()
        ->and($user->motivoBloqueo->descripcion)->toBe('Bloqueado por falta de pago.')
        ->and(User::factory()->create()->estaBloqueado())->toBeFalse();

    expect(fn () => DB::transaction(
        fn () => User::factory()->create(['bloqueado' => 'porque-si'])
    ))->toThrow(QueryException::class);
});

test('the two client relations point in opposite directions', function () {
    // cliente() es el cliente de la app que cuelga del usuario: la FK vive en
    // clientes. clienteRestringido() es al revés, el usuario apunta a un cliente que
    // ya existe para mirar sus pedidos sin ser su dueño. Ver PANEL-3.
    $user = User::factory()->create();
    $propio = Cliente::factory()->create(['user_id' => $user->id]);
    $ajeno = Cliente::factory()->create();

    $user->update(['cliente_restringido_id' => $ajeno->id]);

    expect($user->cliente->id)->toBe($propio->id)
        ->and($user->clienteRestringido->id)->toBe($ajeno->id);
});

test('a user knows their cadete, their photo and their orders', function () {
    $user = User::factory()->create();
    $cadete = Cadete::factory()->create(['user_id' => $user->id]);
    $user->foto()->create(['ruta_archivo' => 'user-fotos/prueba.png']);
    Pedido::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->cadete->id)->toBe($cadete->id)
        ->and($user->foto->ruta_archivo)->toBe('user-fotos/prueba.png')
        ->and($user->pedidos()->count())->toBe(2);
});

test('looking a user up by email ignores case and spacing', function () {
    // Los datos viejos no están normalizados, y en 56 casos hay dos cuentas activas
    // que sólo difieren en eso. Ver DATA-7.
    User::factory()->create(['email' => 'juan.gomez@example.com']);

    expect(User::query()->conEmail('  Juan.Gomez@Example.COM  ')->count())->toBe(1)
        ->and(User::query()->conEmail('otro@example.com')->count())->toBe(0);
});

test('the contrast colour is black or white, and nothing when there is no colour', function () {
    // Lo usa el avatar de la topbar del panel: 32 cuentas. Los 9.206 usuarios de las
    // apps no tienen color.
    expect(User::factory()->create(['color' => '#FFFFFF'])->color_contraste)->toBe('#000000')
        ->and(User::factory()->create(['color' => '#000000'])->color_contraste)->toBe('#FFFFFF')
        ->and(User::factory()->create(['color' => null])->color_contraste)->toBeNull()
        // El sistema viejo hacía hexdec() sobre cualquier cosa.
        ->and(User::factory()->create(['color' => 'azul'])->color_contraste)->toBeNull();
});
