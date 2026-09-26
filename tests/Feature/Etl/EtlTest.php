<?php

use App\Etl\Archivos;
use App\Etl\MapaDeIds;
use App\Etl\Origen;
use App\Etl\Tablas\Cadetes;
use App\Etl\Tablas\Clientes;
use App\Etl\Tablas\Configuraciones;
use App\Etl\Tablas\ErrorLogs;
use App\Etl\Tablas\HorariosAtencion;
use App\Etl\Tablas\Pedidos;
use App\Etl\Tablas\PostulacionDocumentos;
use App\Etl\Tablas\Postulaciones;
use App\Etl\Tablas\PublicidadAppImagenes;
use App\Etl\Tablas\UserFotos;
use App\Etl\Tablas\Users;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

/*
| Las imágenes son la única parte del ETL que escribe fuera de la base, así que
| son la única que necesita un disco falso. `Storage::fake` reemplaza el disco
| `local`, que es el que config('etl.disco') nombra por omisión.
*/

/** Un PNG de 1x1 real, para que los bytes escritos sean una imagen de verdad. */
function pngDeUnPixel(): string
{
    return 'data:image/png;base64,'
        .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';
}

test('the extension comes from the declared type, and only three types are accepted', function () {
    Storage::fake('local');
    $archivos = new Archivos;

    // El esquema no tiene columna mime: el tipo del archivo ES su extensión.
    expect($archivos->desdeDataUri(pngDeUnPixel(), 'user-fotos/7'))->toBe('user-fotos/7.png');
    Storage::disk('local')->assertExists('user-fotos/7.png');
    expect(Storage::disk('local')->get('user-fotos/7.png'))->toStartWith("\x89PNG");

    // image/jpg no es un tipo válido, pero el sistema viejo lo guarda igual.
    expect($archivos->desdeDataUri('data:image/jpg;base64,'.base64_encode('x'), 'user-fotos/8'))
        ->toBe('user-fotos/8.jpg');

    // Una extensión que sale de un valor del origen es una extensión que
    // alguien puede elegir, y estas imágenes las suben los postulantes.
    expect(fn () => $archivos->desdeDataUri('data:image/svg+xml;base64,'.base64_encode('<svg/>'), 'user-fotos/9'))
        ->toThrow(RuntimeException::class, 'no es JPEG ni PNG')
        ->and(fn () => $archivos->desdeDataUri('data:text/html;base64,'.base64_encode('<b>'), 'user-fotos/9'))
        ->toThrow(RuntimeException::class, 'no es JPEG ni PNG')
        ->and(fn () => $archivos->desdeDataUri('iVBORw0KGgo=', 'user-fotos/9'))
        ->toThrow(RuntimeException::class, 'no es un data URI')
        ->and(fn () => $archivos->desdeDataUri('data:image/png;base64,', 'user-fotos/9'))
        ->toThrow(RuntimeException::class, 'no se pudo decodificar');

    Storage::disk('local')->assertMissing('user-fotos/9.png');
});

test('a path that could climb out of the directory never reaches the disk', function () {
    Storage::fake('local');
    $archivos = new Archivos;

    // La misma regla que el CHECK de las tres columnas. Se comprueba en PHP
    // además de en la base porque el error de PostgreSQL no diría cuál era.
    expect(fn () => $archivos->desdeDataUri(pngDeUnPixel(), 'user-fotos/../../../etc/passwd'))
        ->toThrow(RuntimeException::class, 'no pasa el CHECK')
        ->and(fn () => $archivos->copiando('../../etc/passwd', 'user-fotos/7'))
        ->toThrow(RuntimeException::class, 'no es una ruta relativa segura');

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('a file the tar did not bring stops the load instead of leaving a path to nothing', function () {
    Storage::fake('local');
    $raiz = Storage::fake('etl-origen')->path('');
    config(['etl.archivos' => $raiz]);

    @mkdir($raiz.'/postulaciones/abc', 0777, true);
    file_put_contents($raiz.'/postulaciones/abc/frente.jpg', 'unos bytes');

    $archivos = new Archivos;

    expect($archivos->copiando('postulaciones/abc/frente.jpg', 'postulacion-documentos/3/dni_frente'))
        ->toBe('postulacion-documentos/3/dni_frente.jpg')
        ->and(Storage::disk('local')->get('postulacion-documentos/3/dni_frente.jpg'))->toBe('unos bytes');

    // DATA-9: los 1.749 archivos no están en ningún backup de la base. Cargar
    // una fila cuya ruta apunta a la nada es reproducir el problema que DATA-9
    // describe, así que si falta uno la carga entera se cae.
    expect(fn () => $archivos->copiando('postulaciones/abc/no-existe.jpg', 'postulacion-documentos/3/dni_dorso'))
        ->toThrow(RuntimeException::class, 'Falta el archivo');
});

test('the default avatar is nobody photo, so it is filtered out in mongo', function () {
    // 27 de los 1.615 usuarios con algo en `foto` tienen el placeholder de la
    // aplicación vieja. Descartarlos en el filtro y no en fila() es lo que hace
    // que enOrigen() cuente 1.588: la verificación compara contra la cifra real.
    expect((new UserFotos(origen()))->filtro()['foto']['$nin'])
        ->toContain('assets/images/avatar.png')
        ->toContain('')
        ->toContain(null);
});

test('an image file is named after the ObjectId, so it can be uploaded before the load', function () {
    Storage::fake('local');
    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('users', ['64f2a1b2c3d4e5f6a7b8c9d0' => 42]);
    MapaDeIds::anotar('publicidades_app', ['64f2a1b2c3d4e5f6a7b8c9d1' => 7]);

    $fila = (new UserFotos(origen()))->fila([
        '_id' => new ObjectId('64f2a1b2c3d4e5f6a7b8c9d0'),
        'foto' => pngDeUnPixel(),
    ]);

    // La fila apunta al id nuevo, pero el ARCHIVO se llama como el ObjectId: el
    // id nuevo no existe hasta que corre la carga, y con nombres que dependen de
    // él no se puede subir nada por adelantado.
    expect($fila['user_id'])->toBe(42)
        ->and($fila['ruta_archivo'])->toBe('user-fotos/64f2a1b2c3d4e5f6a7b8c9d0.png');

    expect((new PublicidadAppImagenes(origen()))->fila([
        '_id' => new ObjectId('64f2a1b2c3d4e5f6a7b8c9d1'),
        'img_base64' => pngDeUnPixel(),
    ])['ruta_archivo'])->toBe('publicidad-app-imagenes/64f2a1b2c3d4e5f6a7b8c9d1.png');

    // Una imagen sin padre no puede quedar colgando: la columna es NOT NULL y
    // tiene clave foránea.
    expect(fn () => (new UserFotos(origen()))->fila([
        '_id' => new ObjectId('64f2a1b2c3d4e5f6a7b8c9d9'),
        'foto' => pngDeUnPixel(),
    ]))->toThrow(RuntimeException::class, 'no está en migracion_ids');
});

test('one application gives up to four rows, and each one picks decoding or copying', function () {
    Storage::fake('local');
    $raiz = Storage::fake('etl-origen')->path('');
    config(['etl.archivos' => $raiz]);

    @mkdir($raiz.'/postulaciones/64f2a1b2c3d4e5f6a7b8c9d0', 0777, true);
    file_put_contents($raiz.'/postulaciones/64f2a1b2c3d4e5f6a7b8c9d0/dni_frente.jpg', 'el frente');

    MapaDeIds::crearSiFalta();
    MapaDeIds::anotar('postulaciones', ['64f2a1b2c3d4e5f6a7b8c9d0' => 3]);

    // El origen tiene las dos formas mezcladas porque el sistema viejo convierte
    // a archivo al contratar: una ruta se copia, un data URI se decodifica.
    $filas = (new PostulacionDocumentos(origen()))->filas([
        '_id' => new ObjectId('64f2a1b2c3d4e5f6a7b8c9d0'),
        'dni_frente' => 'postulaciones/64f2a1b2c3d4e5f6a7b8c9d0/dni_frente.jpg',
        'foto' => pngDeUnPixel(),
        // Los campos vacíos no dan fila: 142 de las 725 no tienen boleta.
        'dni_dorso' => '',
        'boleta_de_servicio' => '   ',
    ]);

    // ⚠️ La ruta nueva es la vieja cambiando el prefijo. Eso es lo que hace que
    // subir los 1.749 archivos sea un `sync` de un directorio a otro, sin
    // renombrar nada, y esta prueba es lo que lo sostiene.
    expect($filas)->toHaveCount(2)
        ->and(array_column($filas, 'tipo'))->toBe(['dni_frente', 'foto'])
        ->and(array_column($filas, 'ruta_archivo'))->toBe([
            'postulacion-documentos/64f2a1b2c3d4e5f6a7b8c9d0/dni_frente.jpg',
            'postulacion-documentos/64f2a1b2c3d4e5f6a7b8c9d0/foto.png',
        ])
        ->and(array_column($filas, 'postulacion_id'))->toBe([3, 3]);

    // El tipo va en el nombre del archivo, así que las cuatro imágenes de una
    // misma postulación conviven en su carpeta sin pisarse.
    expect(Storage::disk('local')->allFiles('postulacion-documentos/64f2a1b2c3d4e5f6a7b8c9d0'))
        ->toHaveCount(2);

    // Un migrador de varias filas no puede anotar ids —la clave del mapa es
    // (tabla, legacy_id) y habría cuatro filas con el mismo legacy_id—, así que
    // fila() no se usa y devuelve null.
    expect((new PostulacionDocumentos(origen()))->fila([]))->toBeNull();
});

test('emptying the destination also removes the files, or the next load leaves orphans', function () {
    Storage::fake('local');
    Storage::disk('local')->put('user-fotos/64f2a1b2c3d4e5f6a7b8c9d0.png', 'x');
    Storage::disk('local')->put('postulacion-documentos/64f2a1b2c3d4e5f6a7b8c9d0/dni_frente.jpg', 'x');
    Storage::disk('local')->put('publicidad-app-imagenes/64f2a1b2c3d4e5f6a7b8c9d1.png', 'x');
    // Lo que no escribió el ETL no se toca.
    Storage::disk('local')->put('otra-cosa/importante.txt', 'x');

    Archivos::vaciar();

    // Hace falta aunque los nombres ya no dependan de la secuencia: si el origen
    // dejó de tener una imagen su archivo queda sin fila, y si una pasó de PNG a
    // JPEG quedan las dos para una sola fila.
    expect(Storage::disk('local')->allFiles('user-fotos'))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles('postulacion-documentos'))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles('publicidad-app-imagenes'))->toBeEmpty();

    Storage::disk('local')->assertExists('otra-cosa/importante.txt');
});

test('the path does not depend on the row id, which is what allows a pre-upload', function () {
    Storage::fake('local');
    MapaDeIds::crearSiFalta();

    // El mismo documento, cargado en una base donde la secuencia está en otro
    // número: la fila cambia de id y el archivo se llama igual. Es la propiedad
    // de la que depende subir los 2,2 GB antes del corte, así que se prueba.
    $documento = ['_id' => new ObjectId('64f2a1b2c3d4e5f6a7b8c9d0'), 'foto' => pngDeUnPixel()];

    MapaDeIds::anotar('users', ['64f2a1b2c3d4e5f6a7b8c9d0' => 1]);
    $primera = (new UserFotos(origen()))->fila($documento);

    DB::table(MapaDeIds::TABLA)->truncate();
    MapaDeIds::anotar('users', ['64f2a1b2c3d4e5f6a7b8c9d0' => 98_765]);
    $segunda = (new UserFotos(origen()))->fila($documento);

    expect($primera['user_id'])->toBe(1)
        ->and($segunda['user_id'])->toBe(98_765)
        ->and($segunda['ruta_archivo'])->toBe($primera['ruta_archivo'])
        ->and($segunda['ruta_archivo'])->toBe('user-fotos/64f2a1b2c3d4e5f6a7b8c9d0.png');
});
