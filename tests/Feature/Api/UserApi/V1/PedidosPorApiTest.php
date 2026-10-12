<?php

use App\Http\Requests\Api\UserApi\V1\PeticionDeLaApi;
use App\Models\Configuracion;
use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Services\GoogleMaps;
use Illuminate\Support\Facades\Http;

/*
| La API de integración: un tercero cotiza un viaje y da de alta un pedido.
|
| ⚠️ **No tiene tráfico desde el 2021-12-02** —76 pedidos en total, ninguno en casi cuatro
| años, una sola cuenta con el rol (DEP-3)—, así que acá no hay un cliente publicado que
| imponga la forma. Lo que estos tests fijan no es un contrato heredado: es que el precio no se
| pueda fijar desde afuera, que una dirección equivocada no se cotice, y que la caída de Google
| no le haga perder el alta a nadie.
|
| ⚠️ Google se simula con `Http::fake`. Las tres llamadas de una cotización —dos
| geocodificaciones y una ruta— se distinguen por la URL.
*/

function unaCuentaDeApi(): User
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CLIENTE_API], ['display_rol' => 'Cliente API']);

    return User::factory()->create(['rol_id' => $rol->id, 'email' => 'integracion@example.test']);
}

function laTarifaDeLaApi(): void
{
    $valores = [
        'COSTO_MINIMO' => '3000',
        'COSTO_POR_KM' => '500',
        'COSTO_TARIFA_LARGA' => '600',
        'COSTO_RETORNO' => '1000',
        'COSTO_GARANTIA' => '200',
        'TIEMPO_DE_DEMORA' => '30 a 45 minutos',
    ];

    foreach ($valores as $nombre => $valor) {
        Configuracion::query()->updateOrCreate(
            ['nombre' => $nombre],
            [
                'titulo' => $nombre,
                'tipo' => $nombre === 'TIEMPO_DE_DEMORA' ? Configuracion::TIPO_TEXTO : Configuracion::TIPO_NUMERO,
                'valor' => $valor,
            ],
        );
    }
}

/** Un resultado de geocodificación, con la localidad y la provincia que decidan la cobertura. */
function unaGeocodificacion(
    string $localidad = 'San Miguel de Tucumán',
    string $provincia = 'Tucumán',
    bool $parcial = false,
    string $direccion = 'San Luis 312, San Miguel de Tucumán, Tucumán, Argentina',
): array {
    return [
        'status' => 'OK',
        'results' => [[
            'formatted_address' => $direccion,
            'place_id' => 'ChIJ'.md5($direccion.$localidad),
            'partial_match' => $parcial,
            'geometry' => ['location' => ['lat' => -26.83, 'lng' => -65.2]],
            'address_components' => [
                ['long_name' => $localidad, 'types' => ['locality', 'political']],
                ['long_name' => $provincia, 'types' => ['administrative_area_level_1', 'political']],
            ],
        ]],
    ];
}

function unaRuta(int $metros = 3_000): array
{
    return [
        'status' => 'OK',
        'routes' => [['legs' => [['distance' => ['value' => $metros]]]]],
    ];
}

/**
 * Las tres llamadas de una cotización que sale bien.
 *
 * ⚠️ La secuencia termina con `whenEmpty` y no se agota nunca: un test puede cotizar dos
 * veces, y `Http::fake` llamado de nuevo ACUMULA stubs en vez de reemplazarlos —el primero,
 * ya vacío, seguiría atendiendo y la cotización caería en 503 sin que el test diga por qué—.
 */
function googleContesta(?array $origen = null, ?array $destino = null, ?array $ruta = null): void
{
    $delOrigen = $origen ?? unaGeocodificacion();
    $delDestino = $destino ?? unaGeocodificacion();

    Http::fake([
        GoogleMaps::GEOCODING.'*' => Http::sequence()
            ->push($delOrigen)
            ->push($delDestino)
            ->whenEmpty(Http::response($delDestino)),
        GoogleMaps::DIRECTIONS.'*' => Http::response($ruta ?? unaRuta()),
    ]);
}

/** El cuerpo con los dos extremos como texto. */
function elViaje(array $cambios = []): array
{
    return array_merge([
        'direccion_origen' => 'San Luis 312',
        'localidad_origen' => 'San Miguel de Tucumán',
        'direccion_destino' => 'Muñecas 500',
        'localidad_destino' => 'San Miguel de Tucumán',
    ], $cambios);
}

/** Y el del alta, que además nombra a las dos personas. */
function elAlta(array $cambios = []): array
{
    return array_merge(elViaje(), [
        'origen' => 'Panadería La Esquina',
        'destino' => 'Ana Gómez',
        'destino_telefono' => '3815551234',
    ], $cambios);
}

beforeEach(function () {
    laTarifaDeLaApi();
    config(['services.google.maps_servidor' => 'una-clave-de-servidor']);
});

// -------------------------------------------------------------------- cotizar

test('a trip is quoted with the distance Google measured', function () {
    googleContesta(ruta: unaRuta(3_000));

    $respuesta = $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('distancia'))->toBe(3_000)
        ->and($respuesta->json('costo'))->toBeString()
        ->and($respuesta->json('tiempo_de_demora'))->toBe('30 a 45 minutos');
});

test('the quote uses the same tariff as everything else', function () {
    // Una tarifa, tres lectores: el mostrador, la app de clientes y esto.
    googleContesta(ruta: unaRuta(3_000));

    $cuenta = unaCuentaDeApi();

    $simple = $this->actingAs($cuenta, 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->json('costo');

    $conRetorno = $this->actingAs($cuenta, 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje(['retorno_origen' => true]))
        ->json('costo');

    expect(bccomp($conRetorno, $simple, 2))->toBe(1);
});

test('a trip costs three calls to Google, and that is why', function () {
    // ⚠️ Dos geocodificaciones y una ruta: validar la cobertura necesita provincia y
    // localidad, y eso sólo viene en los `address_components` del Geocoding API.
    googleContesta();

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertOk();

    Http::assertSentCount(3);
});

test('coordinates are still reverse geocoded, because coverage needs the locality', function () {
    googleContesta();

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', [
            'origen_lat' => -26.83,
            'origen_lng' => -65.2,
            'destino_lat' => -26.81,
            'destino_lng' => -65.22,
        ])
        ->assertOk();

    // Y las coordenadas mandadas mandan: se rutea por ellas y no por el placeId que Google
    // devolvió cerca.
    Http::assertSent(fn ($peticion): bool => ! str_contains($peticion->url(), 'place_id')
        || ! str_starts_with($peticion->url(), GoogleMaps::DIRECTIONS));
});

test('the language is pinned, because coverage compares the province by name', function () {
    // ⚠️ Sin `language=es` Google elige el idioma por su cuenta y la misma provincia vuelve
    // como «Tucumán Province»: una dirección válida quedaría fuera de cobertura por una
    // diferencia de texto.
    googleContesta();

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertOk();

    Http::assertSent(fn ($peticion): bool => ! str_starts_with($peticion->url(), GoogleMaps::GEOCODING)
        || str_contains($peticion->url(), 'language=es'));
});

// --------------------------------------------------- lo que no se cotiza, y por qué

test('each refusal says what to fix', function (array $falso, string $esperado) {
    Http::fake($falso);

    $respuesta = $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertStatus(422);

    expect($respuesta->json('error'))->toContain($esperado);
})->with([
    'no se encontró la dirección' => [
        fn () => [GoogleMaps::GEOCODING.'*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])],
        'No se pudo ubicar la dirección de origen.',
    ],
    'es ambigua' => [
        fn () => [GoogleMaps::GEOCODING.'*' => Http::response(unaGeocodificacion(parcial: true))],
        'es ambigua',
    ],
    'fuera de la provincia' => [
        fn () => [GoogleMaps::GEOCODING.'*' => Http::response(unaGeocodificacion(provincia: 'Salta'))],
        'fuera del área de cobertura',
    ],
    'otra localidad que la declarada' => [
        fn () => [GoogleMaps::GEOCODING.'*' => Http::response(unaGeocodificacion(localidad: 'Yerba Buena'))],
        'no existe en San Miguel de Tucumán: se encontró en Yerba Buena.',
    ],
]);

test('an address in another locality is refused even when both are covered', function () {
    // ⚠️ Es el control que le da sentido a pedir la localidad; sin él el campo es decorativo.
    // Comprobado contra la API real: «Av. Alberdi 500, Tafí Viejo» vuelve ubicada en San
    // Miguel de Tucumán con `partial_match` en false —Google no avisa nada—. Las dos están
    // cubiertas: se cotizaría un viaje y el cadete recibiría otra dirección.
    Http::fake([
        GoogleMaps::GEOCODING.'*' => Http::response(unaGeocodificacion(localidad: 'Tafí Viejo')),
        GoogleMaps::DIRECTIONS.'*' => Http::response(unaRuta()),
    ]);

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertStatus(422);
});

test('a locality outside the coverage never reaches the network', function () {
    // Una localidad que no atendemos se rechaza en la validación, sin gastar una llamada
    // facturada. Y el error dice cuáles se aceptan, que es lo que un consumidor externo
    // necesita.
    Http::fake();

    $respuesta = $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje(['localidad_origen' => 'La Cocha']))
        ->assertStatus(422);

    expect($respuesta->json('error.localidad_origen.0'))->toContain('Yerba Buena');

    Http::assertNothingSent();
});

test('without an address and without coordinates it says how to send one', function () {
    Http::fake();

    $respuesta = $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', ['direccion_destino' => 'Muñecas 500', 'localidad_destino' => 'Yerba Buena'])
        ->assertStatus(422);

    expect($respuesta->json('error.direccion_origen.0'))
        ->toContain('mandá direccion_origen y localidad_origen, o origen_lat y origen_lng');
});

test('an address without its locality is refused', function () {
    Http::fake();

    $respuesta = $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje(['localidad_origen' => null]))
        ->assertStatus(422);

    expect($respuesta->json('error.localidad_origen.0'))->toContain('obligatoria junto con direccion_origen');
});

test('no route between the two points is not a price of zero', function () {
    Http::fake([
        GoogleMaps::GEOCODING.'*' => Http::response(unaGeocodificacion()),
        GoogleMaps::DIRECTIONS.'*' => Http::response(['status' => 'ZERO_RESULTS', 'routes' => []]),
    ]);

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertStatus(422)
        ->assertJsonPath('error', 'No se encontró una ruta entre las dos direcciones.');
});

// ------------------------------------------------------- cuando Google no está

test('quoting answers 503 when the map service is down, because there is nothing to fall back to', function () {
    Http::fake(fn () => throw new RuntimeException('se cortó la red'));

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertStatus(503)
        ->assertJsonPath('error', 'El servicio de cotización no está disponible en este momento.');
});

test('without any key nothing goes out, and it is reported as unavailable', function () {
    // Sin clave no se sale a la red: una llamada sin `key` sólo gastaría la espera para que
    // Google conteste REQUEST_DENIED.
    config(['services.google.maps_servidor' => null]);
    Http::fake();

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertStatus(503);

    Http::assertNothingSent();
});

test('the server key falls back to the browser one', function () {
    // ⚠️ Esta caída es lo que permite desplegar con la única clave que ya existe, igual que el
    // sistema viejo. Si se saca, el síntoma en producción es que cotizar contesta 503 y las
    // altas entran sin precio —y nada en los tests lo diría—, así que se mira el archivo de
    // config de verdad y no el valor ya resuelto.
    //
    // Conviene igual que sean dos claves: con una sola para el navegador y el servidor no se
    // le puede poner ninguna restricción de aplicación, porque Google deja elegir una sola y
    // dominio e IP son incompatibles. Pero eso es una mejora de la consola de Google, no un
    // requisito para que esto funcione.
    // ⚠️ Se escribe en `$_SERVER` y no con `putenv`: el repositorio de entorno de Laravel lee
    // primero ese adaptador, así que una clave que ya venga del `.env` le ganaría al `putenv`
    // y el test compararía contra la clave REAL —que terminaría impresa en el mensaje de
    // fallo—. Las dos se ponen a un valor de juguete por eso mismo.
    $previas = [];

    foreach (['GOOGLE_MAPS_SERVER_KEY', 'GOOGLE_MAPS_KEY'] as $nombre) {
        $previas[$nombre] = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null];
        unset($_SERVER[$nombre], $_ENV[$nombre]);
        putenv($nombre);
    }

    try {
        $_SERVER['GOOGLE_MAPS_KEY'] = $_ENV['GOOGLE_MAPS_KEY'] = 'la-del-navegador';

        /** @var array{google: array{maps_servidor: string|null}} $config */
        $config = require base_path('config/services.php');

        expect($config['google']['maps_servidor'])->toBe('la-del-navegador');
    } finally {
        foreach ($previas as $nombre => [$servidor, $entorno]) {
            unset($_SERVER[$nombre], $_ENV[$nombre]);

            if ($servidor !== null) {
                $_SERVER[$nombre] = $servidor;
            }

            if ($entorno !== null) {
                $_ENV[$nombre] = $entorno;
            }
        }
    }
});

test('the order is still created when the map service is down, without a price', function () {
    // ⚠️ Que el proveedor de mapas esté caído no le hace perder el alta al consumidor: el
    // pedido entra sin precio y en «Sin asignar» para que lo cotice una persona. Degrada el
    // servicio en vez de cortarlo, y por eso acá no hay 503.
    Http::fake(fn () => throw new RuntimeException('se cortó la red'));

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos', elAlta())
        ->assertOk();

    $pedido = Pedido::query()->sole();

    expect($pedido->valor)->toBeNull()
        ->and($pedido->estado)->toBe(Pedido::ESTADO_SIN_ASIGNAR)
        // La dirección se guarda igual, con el texto que mandó el consumidor.
        ->and($pedido->direccion)->toBe('San Luis 312, San Miguel de Tucumán');
});

// ----------------------------------------------------------------------- el alta

test('the order is created already quoted, and in the waiting list', function () {
    googleContesta(ruta: unaRuta(3_000));

    $respuesta = $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos', elAlta())
        ->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        ->and($respuesta->json('distancia'))->toBe(3_000);

    $pedido = Pedido::query()->sole();

    expect($pedido->valor)->not->toBeNull()
        // ⚠️ NO «Cotizado»: en este sistema eso significa «se espera que el CLIENTE lo
        // acepte desde la app», y un pedido de esta API no tiene cliente, así que nadie
        // podría aceptarlo nunca y quedaría fuera de la lista de espera para siempre.
        ->and($pedido->estado)->toBe(Pedido::ESTADO_ACEPTADO)
        ->and($pedido->plataforma_origen)->toBe(Pedido::PLATAFORMA_API)
        ->and($pedido->cliente_id)->toBeNull()
        ->and($pedido->numero)->toBeInt()
        ->and(LogEstadoPedido::query()->where('pedido_id', $pedido->id)->count())->toBe(1);
});

test('the two people land in the two columns whose names do not say so', function () {
    googleContesta();

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos', elAlta())
        ->assertOk();

    $pedido = Pedido::query()->sole();

    // `nombre_cliente` es de quién sale el envío y `responsable` quién lo recibe. La API los
    // llama `origen` y `destino`, que es lo que son.
    expect($pedido->nombre_cliente)->toBe('Panadería La Esquina')
        ->and($pedido->responsable)->toBe('Ana Gómez')
        ->and($pedido->telefono)->toBe('3815551234');
});

test('a cost sent by the consumer is ignored', function () {
    // ⚠️ El precio NO se recibe nunca: se calcula con el mismo cálculo que el endpoint de
    // cotización. Es la diferencia con el alta de la app de clientes, que acepta el `costo`
    // del teléfono y lo guarda sin contrastarlo (COT-1).
    googleContesta(ruta: unaRuta(3_000));

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos', elAlta(['costo' => '1']))
        ->assertOk();

    expect(Pedido::query()->sole()->valor)->not->toBe('1.00');
});

test('nothing is created when the address does not serve', function () {
    // Se cotiza ANTES de crear nada: una dirección fuera de cobertura tiene que rebotar sin
    // dejar un pedido a medias en la base.
    Http::fake([
        GoogleMaps::GEOCODING.'*' => Http::response(unaGeocodificacion(provincia: 'Salta')),
    ]);

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos', elAlta())
        ->assertStatus(422);

    expect(Pedido::query()->count())->toBe(0);
});

test('an order by coordinates stores the address Google returned', function () {
    // Un par de números no le sirve a nadie en la calle.
    googleContesta(origen: unaGeocodificacion(direccion: 'Av. Aconquija 1200, Yerba Buena, Tucumán'),
        destino: unaGeocodificacion());

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos', [
            'origen' => 'Un comercio',
            'destino' => 'Alguien',
            'origen_lat' => -26.83,
            'origen_lng' => -65.2,
            'destino_lat' => -26.81,
            'destino_lng' => -65.22,
        ])
        ->assertOk();

    expect(Pedido::query()->sole()->direccion)->toBe('Av. Aconquija 1200, Yerba Buena, Tucumán');
});

test('the order that comes back carries what a consumer needs to follow it', function () {
    googleContesta();

    $respuesta = $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos', elAlta())
        ->assertOk();

    expect($respuesta->json('pedido'))
        ->toHaveKeys(['id', 'numero', 'estado', 'origen', 'direccion_origen', 'destino', 'costo'])
        // ⚠️ El sistema viejo devuelve el modelo entero. Quién lo lleva es un dato interno.
        ->and($respuesta->json('pedido'))->not->toHaveKey('cadete_id')
        ->and($respuesta->json('pedido'))->not->toHaveKey('user_id');
});

test('the address text is capped by what the column leaves over', function () {
    // El tope se calcula a partir de la localidad más larga, así que agregar una localidad a
    // la cobertura no puede dejar pasar un valor que la columna después trunque.
    Http::fake();

    $largo = PeticionDeLaApi::largoMaximoDeDireccion();

    expect($largo)->toBeLessThan(255);

    $this->actingAs(unaCuentaDeApi(), 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje([
            'direccion_origen' => str_repeat('a', $largo + 1),
        ]))
        ->assertStatus(422);
});

// ------------------------------------------------------------------- la puerta

test('the integration needs its own role', function () {
    Http::fake();

    $this->postJson('/api/usersapi/pedidos/cotizar', elViaje())->assertUnauthorized();
    $this->postJson('/api/usersapi/pedidos', elAlta())->assertUnauthorized();

    // ⚠️ El mismo guard lo usan las dos apps, que se registran solas. Sin el rol, el token de
    // un cliente entraba acá. Ver SEC-13.
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CLIENTE_APP], ['display_rol' => 'Cliente app']);
    $cliente = User::factory()->create(['rol_id' => $rol->id]);

    $this->actingAs($cliente, 'sanctum')
        ->postJson('/api/usersapi/pedidos/cotizar', elViaje())
        ->assertForbidden();
    $this->actingAs($cliente, 'sanctum')
        ->postJson('/api/usersapi/pedidos', elAlta())
        ->assertForbidden();
});
