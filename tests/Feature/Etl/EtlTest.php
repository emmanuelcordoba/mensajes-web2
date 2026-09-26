<?php

use App\Etl\MapaDeIds;
use App\Etl\Origen;
use App\Etl\Tablas\Cadetes;
use App\Etl\Tablas\Clientes;
use App\Etl\Tablas\Configuraciones;
use App\Etl\Tablas\ErrorLogs;
use App\Etl\Tablas\HorariosAtencion;
use App\Etl\Tablas\Pedidos;
use App\Etl\Tablas\Postulaciones;
use App\Etl\Tablas\Users;
use Illuminate\Database\QueryException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/*
| El ETL lee MongoDB, que no está disponible en CI. Lo que se prueba acá es lo
| que no depende de él: la traducción de ids y fechas, el mapa que sostiene
| todas las claves foráneas, y cómo cada migrador convierte un documento en una
| fila. Cargar de verdad se verifica corriendo el comando contra una copia
| restaurada (ETL-1).
*/

/**
 * Un migrador recibe un Origen, pero traducir un documento no lo usa nunca. El
 * driver conecta de forma perezosa, así que construirlo contra una dirección
 * que nadie atiende alcanza, y deja estas pruebas corriendo sin MongoDB.
 */
function origen(): Origen
{
    return new Origen('mensajes', 'mongodb://127.0.0.1:27017');
}

test('a mongo id is read from every shape the old system stores it in', function () {
    $oid = new ObjectId('64f2a1b2c3d4e5f6a7b8c9d0');

    expect(Origen::id($oid))->toBe('64f2a1b2c3d4e5f6a7b8c9d0')
        // json_decode leaves an ObjectId as ['$oid' => '...'].
        ->and(Origen::id(['$oid' => '64f2a1b2c3d4e5f6a7b8c9d0']))->toBe('64f2a1b2c3d4e5f6a7b8c9d0')
        // Foreign keys are plain strings in the old system (DATA-3).
        ->and(Origen::id('64f2a1b2c3d4e5f6a7b8c9d0'))->toBe('64f2a1b2c3d4e5f6a7b8c9d0')
        ->and(Origen::id('64F2A1B2C3D4E5F6A7B8C9D0'))->toBe('64f2a1b2c3d4e5f6a7b8c9d0');

    foreach ([null, '', 'no-es-un-id', 12345, '64f2a1b2c3d4e5f6a7b8c9', []] as $basura) {
        expect(Origen::id($basura))->toBeNull();
    }
});

test('a date keeps its milliseconds, which is why the columns are not timestamp(0)', function () {
    // 23:59:59.600 on the last day of a month: rounded to seconds it moves to
    // the next month, which is the reason for Schema::defaultTimePrecision(null).
    $filo = new UTCDateTime(strtotime('2026-01-31 23:59:59 UTC') * 1000 + 600);

    expect(Origen::fecha($filo))->toBe('2026-01-31 23:59:59.600+00')
        ->and(Origen::fecha(new UTCDateTime(0)))->toBe('1970-01-01 00:00:00.000+00')
        ->and(Origen::fecha(['$date' => ['$numberLong' => '1600000000123']]))->toBe('2020-09-13 12:26:40.123+00')
        ->and(Origen::fecha(null))->toBeNull()
        ->and(Origen::fecha('2020-09-13'))->toBeNull();
});

test('the id map answers what a document became, and says so only for that table', function () {
    MapaDeIds::crearSiFalta();

    MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 1, '64f2a1b2c3d4e5f6a7b8c9d1' => 2]);
    MapaDeIds::anotar('clientes', ['64f2a1b2c3d4e5f6a7b8c9d0' => 7]);

    // The same legacy id can exist in two collections and mean different rows.
    expect(MapaDeIds::id('roles', '64f2a1b2c3d4e5f6a7b8c9d0'))->toBe(1)
        ->and(MapaDeIds::id('clientes', '64f2a1b2c3d4e5f6a7b8c9d0'))->toBe(7)
        ->and(MapaDeIds::id('cadetes', '64f2a1b2c3d4e5f6a7b8c9d0'))->toBeNull()
        ->and(MapaDeIds::id('roles', null))->toBeNull()
        ->and(MapaDeIds::mapa('roles'))->toBe(['64f2a1b2c3d4e5f6a7b8c9d0' => 1, '64f2a1b2c3d4e5f6a7b8c9d1' => 2])
        ->and(MapaDeIds::cuantos('roles'))->toBe(2);
});

test('the id map refuses to record the same document twice', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 1]);

    // Running the ETL twice without --reiniciar would duplicate everything.
    expect(fn () => MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 9]))
        ->toThrow(QueryException::class, 'SQLSTATE[23505]');
});

test('creating the id map is safe to repeat', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 1]);

    MapaDeIds::crearSiFalta();

    expect(MapaDeIds::cuantos('roles'))->toBe(1);
});

test('a user is stored with the email normalised, which is what the check demands', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('roles', ['64f2a1b2c3d4e5f6a7b8c9d0' => 3]);

    $fila = (new Users(origen()))->fila([
        'name' => '  Ana Pérez ',
        'email' => '  Ana.Perez@Example.COM ',
        'password' => '$2y$10$loquesea',
        // Empty is not the same as absent in the old system, and neither one
        // may become an empty string in a column the schema wants NULL.
        'iniciales' => '',
        'color' => '#ff8800',
        'codigo_de_verificacion' => 123456,
        'bloqueado' => 'falta-de-pago',
        'rol_id' => '64f2a1b2c3d4e5f6a7b8c9d0',
        'created_at' => ['$date' => ['$numberLong' => '1600000000123']],
    ]);

    expect($fila['email'])->toBe('ana.perez@example.com')
        ->and($fila['name'])->toBe('Ana Pérez')
        ->and($fila['iniciales'])->toBeNull()
        ->and($fila['color'])->toBe('#ff8800')
        ->and($fila['codigo_de_verificacion'])->toBe(123456)
        ->and($fila['bloqueado'])->toBe('falta-de-pago')
        ->and($fila['rol_id'])->toBe(3)
        ->and($fila['created_at'])->toBe('2020-09-13 12:26:40.123+00')
        // El nudo circular: se resuelve después de cargar clientes.
        ->and($fila['cliente_restringido_id'])->toBeNull()
        // Columnas nuevas, sin equivalente viejo.
        ->and($fila['codigo_generado_at'])->toBeNull();
});

test('a client born in the app keeps no name of its own, so the generated column derives it', function () {
    MapaDeIds::crearSiFalta();

    $migrador = new Clientes(origen());

    // Lo que escribe la app: mantiene `nombre` igual al par, que es el
    // derivado guardado a mano que rompió DATA-8.
    $app = $migrador->fila([
        'numero' => 3303,
        'nombre' => 'Ana  María   Pérez',
        'nombres' => 'ana maría',
        'apellidos' => 'PÉREZ',
        'direccion' => 'Calle 1',
        'telefono' => '3511234567',
        'plataforma' => 'app',
    ]);

    // Se compara ignorando mayúsculas y espacios de más, igual que
    // Cliente::ponerNombresDePersona() en el sistema viejo.
    expect($app['nombre'])->toBeNull()
        ->and($app['nombres'])->toBe('ana maría')
        ->and($app['apellidos'])->toBe('PÉREZ');

    // Lo que escribe el panel: un nombre propio y ningún par del que derivar.
    expect($migrador->fila([
        'numero' => 12,
        'nombre' => 'Kiosco  El  Sol',
        'direccion' => 'Calle 2',
        'telefono' => '',
    ])['nombre'])->toBe('Kiosco El Sol');

    // Un cliente de la app al que el panel le puso un nombre distinto: manda
    // el del panel, y por eso no se puede migrar el derivado y listo.
    expect($migrador->fila([
        'numero' => 13,
        'nombre' => 'Ferretería Pérez',
        'nombres' => 'Ana',
        'apellidos' => 'Pérez',
        'direccion' => 'Calle 3',
        'telefono' => '3511234567',
    ])['nombre'])->toBe('Ferretería Pérez');
});

test('a client with no address or phone still loads, because empty is not null', function () {
    MapaDeIds::crearSiFalta();

    // direccion y telefono son NOT NULL, y en el origen hay 4 y 368 vacíos.
    // Convertirlos a NULL como se hace con el resto rompería la carga.
    $fila = (new Clientes(origen()))->fila([
        'numero' => 7,
        'nombre' => 'Sin datos',
        // `apellido` en singular es un error viejo de dos registros.
        'apellido' => 'Gómez',
        'direccion' => '   ',
        'nombre_empleado' => '',
    ]);

    expect($fila['direccion'])->toBe('')
        ->and($fila['telefono'])->toBe('')
        ->and($fila['apellidos'])->toBe('Gómez')
        ->and($fila['nombre_empleado'])->toBeNull()
        ->and($fila['user_id'])->toBeNull();
});

test('a cadete keeps the defaults the old system never wrote, and its dni as a number', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('users', ['64f2a1b2c3d4e5f6a7b8c9d0' => 5]);

    $fila = (new Cadetes(origen()))->fila([
        'numero_movil' => 1203,
        'apellidos' => 'Gómez',
        'nombres' => 'Juan',
        'direccion' => 'Calle 1',
        'telefono' => '3511234567',
        'fecha_nacimiento' => '1990-05-12',
        // Guardado con ceros a la izquierda, que es una de las formas en que
        // DATA-4 encontró el mismo DNI dos veces.
        'dni' => '0030111222',
        'estado' => 'inactivo',
        'tipo_vehiculo' => 'M',
        'user_id' => '64f2a1b2c3d4e5f6a7b8c9d0',
        // `monto_semanal_personal` es un booleano pese al nombre (DATA-3), y
        // 13 cadetes no lo tienen.
        // `modalidad_cobranza` tampoco: la migración de PANEL-8 no corrió acá.
    ]);

    expect($fila['dni'])->toBe(30111222)
        ->and($fila['tiene_monto_semanal_personal'])->toBeFalse()
        ->and($fila['modalidad_cobranza'])->toBe('Semanal')
        ->and($fila['fecha_nacimiento'])->toBe('1990-05-12')
        ->and($fila['user_id'])->toBe(5)
        ->and($fila['fecha_vencimiento'])->toBeNull();
});

test('a cadete with an unreadable birth date stops the load instead of guessing', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('users', ['64f2a1b2c3d4e5f6a7b8c9d0' => 5]);

    $cadete = [
        'numero_movil' => 1204,
        'apellidos' => 'Gómez',
        'nombres' => 'Juan',
        'direccion' => 'Calle 1',
        'telefono' => '3511234567',
        'estado' => 'inactivo',
        'tipo_vehiculo' => 'M',
        'user_id' => '64f2a1b2c3d4e5f6a7b8c9d0',
    ];

    // En dd/mm/aaaa no se sabe si 05/11 es mayo o noviembre. Son 3 cadetes
    // dados de baja, y aun así el ETL no elige por ellos.
    expect(fn () => (new Cadetes(origen()))->fila($cadete + ['fecha_nacimiento' => '05/11/1990']))
        ->toThrow(RuntimeException::class, 'no es aaaa-mm-dd');

    // El mensaje no puede llevar la fecha: es un dato de una persona.
    try {
        (new Cadetes(origen()))->fila($cadete + ['fecha_nacimiento' => '05/11/1990']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('99/99/9999')->not->toContain('05/11/1990');
    }

    // Un DNI que no son 7 u 8 dígitos tampoco se inventa ni se vacía solo.
    expect(fn () => (new Cadetes(origen()))->fila($cadete + ['fecha_nacimiento' => '1990-05-12', 'dni' => '123']))
        ->toThrow(RuntimeException::class, '7 u 8 dígitos');
});

test('an order missing half its fields still loads, because the schema has defaults for them', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('users', ['64f2a1b2c3d4e5f6a7b8c9d0' => 5]);

    // Un pedido del panel: sin cliente vinculado, sin número, y sin los campos
    // que casi ningún documento tiene. `gastronomia` está en 72 de 959.973.
    $fila = (new Pedidos(origen()))->fila([
        'direccion' => 'Calle 1',
        'destino' => 'Calle 2',
        'nombre_cliente' => 'Kiosco El Sol',
        'plataforma_origen' => 'web',
        'estado' => 'Finalizado',
        'user_id' => '64f2a1b2c3d4e5f6a7b8c9d0',
    ]);

    expect($fila['gastronomia'])->toBeFalse()
        ->and($fila['retorno_origen'])->toBeFalse()
        ->and($fila['numero'])->toBeNull()
        ->and($fila['cliente_id'])->toBeNull()
        ->and($fila['cadete_id'])->toBeNull()
        ->and($fila['nombre_cliente'])->toBe('Kiosco El Sol')
        ->and($fila['user_id'])->toBe(5);

    // Una referencia presente que no resuelve no puede volverse NULL sola: el
    // pedido perdería a quién pertenece.
    expect(fn () => (new Pedidos(origen()))->fila([
        'plataforma_origen' => 'web',
        'estado' => 'Finalizado',
        'cliente_id' => '64f2a1b2c3d4e5f6a7b8c9d9',
    ]))->toThrow(RuntimeException::class, 'no está en migracion_ids');
});

test('the two parameter tables convert what mongo stored loosely into what the columns demand', function () {
    // horarios_atencion.desde/hasta son TIME, y en MongoDB son texto. El panel
    // no valida el formato, así que cualquier cosa podría estar guardada ahí.
    $horarios = new HorariosAtencion(origen());

    expect($horarios->fila(['desde' => ' 08:00 ', 'hasta' => '21:00'])['desde'])->toBe('08:00')
        ->and(fn () => $horarios->fila(['desde' => '8am', 'hasta' => '21:00']))
        ->toThrow(RuntimeException::class, 'fuera de HH:MM');

    // configuraciones.valor es TEXT, pero las de tipo `numero` tienen un CHECK
    // que no admite notación científica ni un 250.0 que no es lo guardado.
    $configuraciones = new Configuraciones(origen());

    expect($configuraciones->fila(['nombre' => 'COSTO_MINIMO', 'tipo' => 'numero', 'valor' => 3000.0])['valor'])
        ->toBe('3000')
        ->and($configuraciones->fila(['nombre' => 'COSTO_GARANTIA', 'tipo' => 'numero', 'valor' => 0.18])['valor'])
        ->toBe('0.18')
        ->and($configuraciones->fila(['nombre' => 'TIEMPO_DE_DEMORA', 'tipo' => 'texto', 'valor' => ''])['valor'])
        ->toBeNull();
});

test('telemetry may lose its user, but an application may not lose its birth date', function () {
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('users', ['64f2a1b2c3d4e5f6a7b8c9d0' => 5]);

    // error_logs es telemetría y su user_id va sin clave foránea a propósito:
    // un error puede llegar de una sesión ya cerrada. Acá sí se deja NULL lo
    // que no resuelve, al revés que en todos los demás migradores.
    $logs = new ErrorLogs(origen());

    expect($logs->fila(['message' => 'boom', 'user_id' => '64f2a1b2c3d4e5f6a7b8c9d0'])['user_id'])->toBe(5)
        ->and($logs->fila(['message' => 'boom', 'user_id' => '64f2a1b2c3d4e5f6a7b8c9d9'])['user_id'])->toBeNull()
        ->and($logs->fila(['message' => 'boom'])['user_id'])->toBeNull();

    // Una postulación, en cambio, no puede perder nada: el DNI y el email son
    // UNIQUE y la fecha de nacimiento es NOT NULL.
    $postulaciones = new Postulaciones(origen());

    $fila = $postulaciones->fila([
        'nombres' => 'Juan', 'apellidos' => 'Gómez',
        // Guardado con ceros a la izquierda.
        'dni' => '0030111222',
        'fecha_nacimiento' => '1990-05-12',
        'direccion' => 'Calle 1', 'telefono' => '3511234567',
        // 35 de las 725 tienen mayúsculas, y el CHECK no las admite.
        'email' => ' Juan.Gomez@Example.COM ',
        'tipo_vehiculo' => 'M',
    ]);

    expect($fila['dni'])->toBe(30111222)
        ->and($fila['email'])->toBe('juan.gomez@example.com')
        ->and($fila['cadete_id'])->toBeNull();

    // DATA-10: ni se interpreta un formato ambiguo, ni se inventa un DNI.
    expect(fn () => $postulaciones->fila(['dni' => '30111222', 'fecha_nacimiento' => '12/05/1990']))
        ->toThrow(RuntimeException::class, 'no es aaaa-mm-dd');
});

test('a user with no role is fine, but one whose role cannot be resolved stops the load', function () {
    MapaDeIds::crearSiFalta();

    // 20 usuarios activos no tienen rol, y la columna es nullable.
    expect((new Users(origen()))->fila(['name' => 'Sin rol', 'email' => 'sin@rol.test'])['rol_id'])
        ->toBeNull();

    // Pero un rol que no se puede resolver dejaría al usuario sin permisos en
    // silencio, y el rol es lo que decide qué puede hacer.
    expect(fn () => (new Users(origen()))->fila([
        'name' => 'Rol perdido',
        'email' => 'rol@perdido.test',
        'rol_id' => '64f2a1b2c3d4e5f6a7b8c9d9',
    ]))->toThrow(RuntimeException::class, 'no está en migracion_ids');
});
