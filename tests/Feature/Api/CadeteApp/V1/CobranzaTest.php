<?php

use App\Models\Cadete;
use App\Models\MovimientoCobranzaSaldo;
use App\Models\Rol;
use App\Models\User;
use App\Services\MovimientosDeSaldo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| La cobranza del cadete: cuánto saldo le queda y en qué se fue.
|
| ⚠️ Lo que viaja es plata. Viaja como TEXTO de punta a punta, sin pasar por float en ningún
| punto del camino, y con el formato que el cadete venía leyendo en el teléfono: «$ 3000» y
| no «$ 3000.00».
|
| ⚠️ Y el signo NO va en el monto: va en `monto_positivo`, porque el «- » lo escribe la
| plantilla de la app. Mandar el monto negativo pondría dos signos.
*/

function unCadeteDeCobranza(string $modalidad = Cadete::COBRANZA_SALDO, array $extra = [], string $email = 'cobranza@example.test'): Cadete
{
    $rol = Rol::query()->firstOrCreate(['rol' => Rol::CADETE], ['display_rol' => 'Cadete']);

    $usuario = User::factory()->create(['rol_id' => $rol->id, 'email' => $email]);

    return Cadete::factory()->create(array_merge([
        'user_id' => $usuario->id,
        'numero_movil' => 66,
        'estado' => Cadete::ESTADO_INACTIVO,
        'modalidad_cobranza' => $modalidad,
        'cobranza_saldo' => '12500.00',
    ], $extra));
}

/**
 * Movimientos del más viejo al más nuevo, uno por minuto, para que el orden de la respuesta
 * sea el inverso y se note si se pierde.
 *
 * ⚠️ Con la factory: `created_at` no está en el `#[Fillable]` del modelo.
 */
function unosMovimientos(Cadete $cadete, int $cuantos): void
{
    foreach (range(1, $cuantos) as $n) {
        MovimientoCobranzaSaldo::factory()->create([
            'cadete_id' => $cadete->id,
            'monto' => '100.00',
            'monto_positivo' => false,
            'saldo_parcial' => '12500.00',
            'tipo' => MovimientoCobranzaSaldo::PEDIDO_FINALIZADO,
            'created_at' => Carbon::now()->subMinutes($cuantos - $n),
        ]);
    }
}

// ------------------------------------------------------------------ el saldo

test('the balance screen comes with the shape the app declares', function () {
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, 3);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();

    expect($respuesta->json('ok'))->toBeTrue()
        // La app compara contra la cadena 'Saldo' para decidir si dibuja la pantalla.
        ->and($respuesta->json('modalidad'))->toBe(Cadete::COBRANZA_SALDO)
        // Sin los dos ceros de atrás: la app lo imprime tal cual, `$ {{ saldo }}`.
        ->and($respuesta->json('cobranza.saldo'))->toBe('12500')
        ->and($respuesta->json('cobranza.movimientos'))->toHaveCount(3)
        ->and($respuesta->json('cobranza.mas_movimientos'))->toBeFalse();
});

test('a movement keeps the five fields the app declares and no more', function () {
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, 1);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();

    $movimiento = $respuesta->json('cobranza.movimientos.0');

    expect($movimiento)->toHaveKeys(['tipo', 'monto', 'monto_positivo', 'saldo_parcial', 'created_at'])
        ->and($movimiento)->toHaveCount(5)
        // ⚠️ `fecha` NUNCA existió: la tabla no tiene esa columna, así que a la app le llega
        // `undefined` desde el primer día y la plantilla usa `created_at`. Inventarlo ahora
        // sería agregar al contrato un campo que nadie pidió.
        ->and($movimiento)->not->toHaveKey('fecha')
        // Y quién en la oficina cargó el saldo no es asunto del teléfono del cadete.
        ->and($movimiento)->not->toHaveKey('user_id');
});

test('the sign lives in monto_positivo and not in the amount', function () {
    $cadete = unCadeteDeCobranza();

    MovimientoCobranzaSaldo::factory()->create([
        'cadete_id' => $cadete->id,
        'monto' => '540.50',
        'monto_positivo' => false,
        'tipo' => MovimientoCobranzaSaldo::PEDIDO_FINALIZADO,
    ]);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();

    // ⚠️ El «- » lo escribe la plantilla: `<span *ngIf="!movimiento.monto_positivo">- </span>`.
    // Un monto negativo acá pondría dos signos.
    expect($respuesta->json('cobranza.movimientos.0.monto'))->toBe('540.5')
        ->and($respuesta->json('cobranza.movimientos.0.monto_positivo'))->toBeFalse();
});

test('a balance that was never loaded is zero and not a blank', function () {
    // El sistema viejo manda el null tal cual y la pantalla muestra «$ » a secas.
    $cadete = unCadeteDeCobranza(Cadete::COBRANZA_SALDO, ['cobranza_saldo' => null]);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza')
        ->assertOk()
        ->assertJsonPath('cobranza.saldo', '0');
});

test('a movement without a previous balance does not break the list', function () {
    // Son 44, todos «Carga de saldo» y todos la primera de su cadete: el sistema viejo
    // escribe `saldo_parcial => $cadete->cobranza_saldo` y ahí todavía está en null.
    $cadete = unCadeteDeCobranza();

    MovimientoCobranzaSaldo::factory()->create([
        'cadete_id' => $cadete->id,
        'monto' => '5000.00',
        'monto_positivo' => true,
        'saldo_parcial' => null,
        'tipo' => MovimientoCobranzaSaldo::CARGA_SALDO,
    ]);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza')
        ->assertOk()
        ->assertJsonPath('cobranza.movimientos.0.saldo_parcial', null);
});

// --------------------------------------------------------- el que cobra por semana

test('a cadete paid weekly gets nothing to show, and that is the contract', function () {
    $cadete = unCadeteDeCobranza(Cadete::COBRANZA_SEMANAL);

    // Aunque tenga movimientos viejos de cuando cobraba por saldo.
    unosMovimientos($cadete, 4);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();

    // `cobranza` en null, y la pantalla de la app no se dibuja: `*ngIf="modalidad === 'Saldo'"`.
    expect($respuesta->json('cobranza'))->toBeNull()
        ->and($respuesta->json('modalidad'))->toBe(Cadete::COBRANZA_SEMANAL);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza/saldo/movimientos?offset=0')
        ->assertOk()
        ->assertJsonPath('movimientos', [])
        ->assertJsonPath('mas_movimientos', false);
});

// ------------------------------------------------------------------- el paginado

test('the newest come first', function () {
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, 3);

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();

    $cuando = array_column($respuesta->json('cobranza.movimientos'), 'created_at');
    $alReves = $cuando;
    rsort($alReves);

    expect($cuando)->toBe($alReves);
});

test('the first page is ten and each more is five, like the old one', function () {
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, 20);

    $primera = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();

    expect($primera->json('cobranza.movimientos'))->toHaveCount(MovimientosDeSaldo::PRIMERA_PAGINA)
        ->and($primera->json('cobranza.mas_movimientos'))->toBeTrue();

    // La app llama con `this.movimientos.length`.
    $segunda = $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza/saldo/movimientos?offset=10')
        ->assertOk();

    expect($segunda->json('movimientos'))->toHaveCount(MovimientosDeSaldo::SIGUIENTES)
        ->and($segunda->json('mas_movimientos'))->toBeTrue();
});

test('the pages do not overlap and do not skip anything', function () {
    $cadete = unCadeteDeCobranza();

    foreach (range(1, 18) as $n) {
        MovimientoCobranzaSaldo::factory()->create([
            'cadete_id' => $cadete->id,
            'monto' => (string) $n.'.00',
            'monto_positivo' => false,
            'tipo' => MovimientoCobranzaSaldo::PEDIDO_FINALIZADO,
            'created_at' => Carbon::now()->subMinutes(18 - $n),
        ]);
    }

    $vistos = [];

    $pagina = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();
    $vistos = array_merge($vistos, array_column($pagina->json('cobranza.movimientos'), 'monto'));
    $hayMas = $pagina->json('cobranza.mas_movimientos');

    while ($hayMas) {
        $pagina = $this->actingAs($cadete->user, 'sanctum')
            ->getJson('/api/cobranza/saldo/movimientos?offset='.count($vistos))
            ->assertOk();

        $vistos = array_merge($vistos, array_column($pagina->json('movimientos'), 'monto'));
        $hayMas = $pagina->json('mas_movimientos');
    }

    // Los 18, una sola vez cada uno, del más nuevo al más viejo.
    expect($vistos)->toHaveCount(18)
        ->and(array_unique($vistos))->toHaveCount(18)
        ->and($vistos[0])->toBe('18')
        ->and($vistos[17])->toBe('1');
});

test('a full last page does not promise more', function () {
    // ⚠️ Comparar el tamaño de la página contra el límite daría «hay más» cada vez que la
    // página viene completa, aunque sea la última: el cadete toca «Cargar más» y no pasa
    // nada. Por eso se pide uno de más.
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, MovimientosDeSaldo::PRIMERA_PAGINA);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza')
        ->assertOk()
        ->assertJsonPath('cobranza.mas_movimientos', false);
});

test('answering whether there is more does not drag every movement into memory', function () {
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, 40);

    // ⚠️ El sistema viejo contesta esto con
    // `count($cadete->movimientosCobranzaSaldo) > 10`: trae TODOS los movimientos del cadete
    // —1.173 para el que más tiene— para informar un booleano, y en una consulta aparte,
    // porque usa la propiedad y no el método.
    $traidas = 0;
    DB::listen(function (object $consulta) use (&$traidas): void {
        if (str_contains($consulta->sql, 'cobranza_saldo_movimientos')) {
            $traidas++;
        }
    });

    $respuesta = $this->actingAs($cadete->user, 'sanctum')->getJson('/api/cobranza')->assertOk();

    expect($traidas)->toBe(1)
        ->and($respuesta->json('cobranza.movimientos'))->toHaveCount(10)
        ->and($respuesta->json('cobranza.mas_movimientos'))->toBeTrue();
});

test('an offset past the end is an empty page and not an error', function () {
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, 3);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza/saldo/movimientos?offset=500')
        ->assertOk()
        ->assertJsonPath('movimientos', [])
        ->assertJsonPath('mas_movimientos', false);
});

test('an offset that is not a number is refused instead of reaching the database', function () {
    // ⚠️ El sistema viejo hace `$request->offset ?? 0` y se lo pasa a `offset()`. En MongoDB
    // eso se convertía a número; en PostgreSQL es un error de SQL, o sea un 500. La app
    // siempre manda un entero: esto lo rompe cualquiera con un token.
    $cadete = unCadeteDeCobranza();

    foreach (['cualquiera', '-5', '1.5'] as $malo) {
        $respuesta = $this->actingAs($cadete->user, 'sanctum')
            ->getJson('/api/cobranza/saldo/movimientos?offset='.$malo)
            ->assertStatus(422);

        expect($respuesta->json('code'))->toBe(422, $malo)
            ->and($respuesta->json('error.offset'))->toBeArray();
    }
});

test('a missing offset is the first page, like the old one', function () {
    $cadete = unCadeteDeCobranza();
    unosMovimientos($cadete, 12);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza/saldo/movimientos')
        ->assertOk()
        ->assertJsonPath('mas_movimientos', true)
        ->assertJsonCount(MovimientosDeSaldo::PRIMERA_PAGINA, 'movimientos');
});

// ---------------------------------------------------------------------- la puerta

test('a cadete only sees his own movements', function () {
    $cadete = unCadeteDeCobranza();
    $otro = unCadeteDeCobranza(Cadete::COBRANZA_SALDO, ['numero_movil' => 67], 'otro-cobranza@example.test');

    unosMovimientos($otro, 5);

    $this->actingAs($cadete->user, 'sanctum')
        ->getJson('/api/cobranza')
        ->assertOk()
        ->assertJsonPath('cobranza.movimientos', [])
        ->assertJsonPath('cobranza.mas_movimientos', false);
});

test('the balance needs a token and a cadete', function () {
    $this->getJson('/api/cobranza')->assertUnauthorized();
    $this->getJson('/api/cobranza/saldo/movimientos')->assertUnauthorized();

    // ⚠️ El rol se exige además del token: el mismo guard lo usan las apps de clientes. Ver
    // SEC-13. Y acá lo que se protege es la plata de una persona.
    $cliente = User::factory()->create([
        'rol_id' => Rol::query()->firstOrCreate(
            ['rol' => Rol::CLIENTE_APP],
            ['display_rol' => 'Cliente app'],
        )->id,
    ]);

    $this->actingAs($cliente, 'sanctum')->getJson('/api/cobranza')->assertForbidden();
    $this->actingAs($cliente, 'sanctum')
        ->getJson('/api/cobranza/saldo/movimientos')
        ->assertForbidden();
});
