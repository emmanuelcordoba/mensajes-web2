<?php

use App\Models\Cadete;
use App\Models\Rol;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

/*
| El tope de pedidos por minuto de la API.
|
| Son 120 generales y 10 de ubicación, y están en
| `AppServiceProvider::configureRateLimiting()`. Lo que estos tests sujetan no es el
| número: es **contra qué se cuentan**, que es lo único que no se lee en esa línea.
|
| Por cuenta en lo que lleva token, y por IP en lo que no. La parte que no es obvia es por
| qué: el tope entra `prepend` en el grupo, o sea antes que el `auth:sanctum` de la ruta,
| así que uno esperaría que contara siempre por IP —cuando corre, todavía no hay nadie
| autenticado—. No es así porque el router ordena los middlewares por la prioridad de
| `Kernel::$middlewarePriority`, y ahí `AuthenticatesRequests` va **antes** que
| `ThrottleRequests`: en una ruta con `auth:`, la cuenta ya está resuelta cuando el tope
| arma su clave. En una ruta sin `auth:` no hay nada que ordenar, y cuenta por IP.
|
| ⚠️ Esto importa de verdad: si contara por IP, dos cadetes en la misma antena del celular
| compartirían los 120 y el segundo comería 429 sin haber hecho nada. Si alguna vez alguien
| saca el `auth:sanctum` de una ruta, o toca esa prioridad, estos tests avisan.
|
| ⚠️ **Y la ubicación tiene su propio balde, más chico.** El 17 y el 18 de noviembre un solo
| teléfono mandó hasta 817 ubicaciones en un minuto y se comió sus propios 120: después le
| rebotaron `getcadete` y `pedidos`, o sea que no podía trabajar por culpa de su GPS. El
| último test de este archivo es ése, y es el que de verdad hay que no romper.
|
| ⚠️ Los tests usan un token de verdad en la cabecera y NO `Sanctum::actingAs`, y resetean
| el guard entre pedidos: los dos atajos hacen que el test cuente por cuenta aunque el
| sistema contara por IP, que es justo lo que hay que distinguir. Ver `comoUnPedidoNuevo()`.
*/

/** Los dos topes, leídos de donde están definidos y no copiados. */
const PEDIDOS_POR_MINUTO = AppServiceProvider::PEDIDOS_POR_MINUTO;
const UBICACIONES_POR_MINUTO = AppServiceProvider::UBICACIONES_POR_MINUTO;

function unCadeteConToken(string $email, int $movil): string
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);

    $usuario = User::factory()->create(['rol_id' => $rol->id, 'email' => $email]);

    Cadete::factory()->create([
        'user_id' => $usuario->id,
        'numero_movil' => $movil,
        'estado' => Cadete::ESTADO_ACTIVO_APP,
    ]);

    return $usuario->createToken('la app')->plainTextToken;
}

/**
 * Deja el proceso como si fuera el primer pedido del día.
 *
 * ⚠️ **Sin esto el test miente, y de dos maneras.** Los pedidos de un test van todos
 * dentro del mismo proceso: el guard de sanctum se guarda la cuenta que resolvió la
 * primera vez —así el token del segundo cadete entra y el sistema sigue viendo al
 * primero—, y además `auth:sanctum`, cuando autentica, deja a sanctum como guard de
 * omisión para todo lo que venga después. En producción cada pedido arranca limpio.
 */
function comoUnPedidoNuevo(): void
{
    app('auth')->forgetGuards();
    config(['auth.defaults.guard' => 'web']);
}

/** Una ubicación, que es lo que la app manda sola y lo que tiene su propio balde. */
function mandarUbicacion(string $token): int
{
    comoUnPedidoNuevo();

    return test()
        ->withToken($token)
        ->postJson('/api/actualizarubicacion', ['lat' => '-31.4', 'lon' => '-64.2'])
        ->getStatusCode();
}

/**
 * Pedir el cadete, que es lo que la pantalla principal refresca.
 *
 * ⚠️ Es lo que se usa para medir el balde general, y antes se medía con la ubicación. No da
 * lo mismo desde que la ubicación tiene su propio tope: con ella, el test se chocaría con el
 * de 10 y nunca llegaría a los 120.
 */
function pedirElCadete(string $token): int
{
    comoUnPedidoNuevo();

    return test()->withToken($token)->getJson('/api/getcadete')->getStatusCode();
}

test('each account has its own quota, even from the same IP', function () {
    $uno = unCadeteConToken('tope-uno@example.test', 901);
    $otro = unCadeteConToken('tope-dos@example.test', 902);

    for ($i = 0; $i < PEDIDOS_POR_MINUTO; $i++) {
        expect(pedirElCadete($uno))->toBe(200);
    }

    // El primero ya gastó lo suyo.
    expect(pedirElCadete($uno))->toBe(429)
        // Y el segundo no gastó nada, aunque salga por la misma IP.
        ->and(pedirElCadete($otro))->toBe(200);
});

test('what comes with no token is counted by IP', function () {
    // El login, la recuperación de contraseña y la postulación no tienen cuenta contra la
    // cual contar. La postulación acepta cuatro imágenes de 4 MB sin pedir nada, así que
    // la IP es lo único que la protege.
    for ($i = 0; $i < PEDIDOS_POR_MINUTO; $i++) {
        comoUnPedidoNuevo();
        $this->postJson('/api/cadetes/login', ['email' => 'nadie@example.test', 'password' => 'x']);
    }

    comoUnPedidoNuevo();

    $this->postJson('/api/cadetes/login', ['email' => 'nadie@example.test', 'password' => 'x'])
        ->assertStatus(429);
});

test('every 429 leaves a line saying whose it was', function () {
    // ⚠️ Es lo único que contesta de quién fue. El monitoreo de Cloud cuenta los 429 por
    // ruta y nada más. El renglón se escribe en `bootstrap/app.php`.
    Log::spy();

    $token = unCadeteConToken('tope-log@example.test', 904);

    for ($i = 0; $i < UBICACIONES_POR_MINUTO; $i++) {
        mandarUbicacion($token);
    }

    expect(mandarUbicacion($token))->toBe(429);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $mensaje, array $contexto): bool {
            return $mensaje === 'Tope de pedidos superado'
                && $contexto['ruta'] === 'api/actualizarubicacion'
                && $contexto['metodo'] === 'POST'
                && $contexto['movil'] === 904;
        })
        ->once();
});

test('the 429 of something with no account also leaves its line, with the IP', function () {
    Log::spy();

    for ($i = 0; $i < PEDIDOS_POR_MINUTO + 1; $i++) {
        comoUnPedidoNuevo();
        $this->postJson('/api/cadetes/login', ['email' => 'nadie@example.test', 'password' => 'x']);
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $mensaje, array $contexto): bool {
            return $mensaje === 'Tope de pedidos superado'
                && $contexto['cuenta_id'] === null
                && $contexto['movil'] === null
                && $contexto['ip'] === '127.0.0.1';
        })
        ->once();
});

// --------------------------------------------------------------- el balde de la ubicación

test('the location has its own smaller bucket', function () {
    $token = unCadeteConToken('tope-ubicacion@example.test', 905);

    for ($i = 0; $i < UBICACIONES_POR_MINUTO; $i++) {
        expect(mandarUbicacion($token))->toBe(200);
    }

    expect(mandarUbicacion($token))->toBe(429);
});

test('a phone flooding locations keeps the quota it needs to work', function () {
    // ⚠️ **Esto es el motivo de todo.** Pasó de verdad: móvil 1716, 3.179 rechazos en 19
    // horas, hasta 817 ubicaciones en un minuto. El tope cuenta por cuenta, así que ningún
    // otro cadete se vio afectado, pero ESE cadete se comía sus propios 120 con ubicaciones
    // y después no podía ver ni aceptar un pedido.
    //
    // Depende de un detalle de `ThrottleRequests::handleRequest()`: comprueba TODOS los
    // límites en un primer recorrido y recién suma en un segundo, así que el pedido que se
    // pasa de ubicaciones se cae sin incrementar el balde general.
    $token = unCadeteConToken('tope-inundacion@example.test', 906);

    // Las diez que le tocan, y cien más rebotando.
    for ($i = 0; $i < UBICACIONES_POR_MINUTO + 100; $i++) {
        mandarUbicacion($token);
    }

    // Y después puede trabajar, que es lo único que importa.
    expect(mandarUbicacion($token))->toBe(429)
        ->and(pedirElCadete($token))->toBe(200);
});

test('the general bucket only counts what it let through', function () {
    // El corolario del anterior, por el otro lado: las ubicaciones rechazadas no gastan del
    // balde general, así que después de la inundación siguen quedando casi los 120 enteros.
    $token = unCadeteConToken('tope-resto@example.test', 907);

    for ($i = 0; $i < UBICACIONES_POR_MINUTO + 200; $i++) {
        mandarUbicacion($token);
    }

    // Las 10 que pasaron también gastaron del general, así que quedan 110.
    for ($i = 0; $i < PEDIDOS_POR_MINUTO - UBICACIONES_POR_MINUTO; $i++) {
        expect(pedirElCadete($token))->toBe(200);
    }

    expect(pedirElCadete($token))->toBe(429);
});
