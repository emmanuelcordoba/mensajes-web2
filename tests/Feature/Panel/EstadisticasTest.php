<?php

use App\Models\Pedido;
use App\Models\Rol;
use App\Models\User;
use App\Support\Navegacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Estadísticas: pedidos finalizados por día del mes.
|
| Lo que esta pantalla tiene que garantizar es que la cuenta sea la del sistema viejo —estado
| Finalizado, por `created_at`— y que la haga en una consulta y no en una por día. El mes,
| aparte, en `tests/Feature/Support/MesTest.php`.
*/

/** Un pedido finalizado ese día a esa hora. */
function unPedidoFinalizadoEl(string $momento): Pedido
{
    return Pedido::factory()->create([
        'estado' => Pedido::ESTADO_FINALIZADO,
        'created_at' => Carbon::parse($momento),
    ]);
}

/** Si el menú lateral le muestra la entrada de Estadísticas a ese usuario. */
function elMenuMuestraEstadisticas(User $user): bool
{
    foreach (Navegacion::para($user) as $grupo) {
        foreach ($grupo['items'] as $item) {
            if ($item['tipo'] === 'enlace' && $item['titulo'] === 'Estadísticas') {
                return true;
            }
        }
    }

    return false;
}

test('the office reaches the statistics and a shop does not', function () {
    foreach ([Rol::ADMIN, Rol::EMPLEADO] as $rol) {
        $this->actingAs(usuarioCon($rol))
            ->get('/panel/estadisticas')
            ->assertSuccessful()
            ->assertInertia(fn ($pagina) => $pagina->component('estadisticas/index'));
    }

    // ⚠️ No alcanza con `viewAny`: un comercio SÍ ve el listado de pedidos, los suyos. Esta
    // pantalla cuenta los de todos, así que es otra pregunta y otro método de la policy.
    $this->actingAs(usuarioCon(Rol::RESTRINGIDO))
        ->get('/panel/estadisticas')
        ->assertForbidden();
});

test('the menu and the policy agree on who sees the statistics', function () {
    // Una regla, dos lectores: el menú decide con su lista de roles y el controlador con la
    // policy. Si alguien cambia uno y no el otro, o aparece una entrada que al abrirla da 403,
    // o se esconde una pantalla que el usuario podía abrir.
    foreach ([Rol::ADMIN, Rol::EMPLEADO, Rol::RESTRINGIDO] as $rol) {
        $user = usuarioCon($rol);

        expect(elMenuMuestraEstadisticas($user))
            ->toBe($user->can('verEstadisticas', Pedido::class), "el rol $rol");
    }
});

test('it counts the finished orders of each day, and the days with none', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    unPedidoFinalizadoEl('2026-09-01 10:00:00');
    unPedidoFinalizadoEl('2026-09-01 18:30:00');
    unPedidoFinalizadoEl('2026-09-03 09:00:00');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-09')
        ->assertInertia(function ($pagina) {
            $dias = collect($pagina->toArray()['props']['dias'])->keyBy('fecha');

            expect($dias)->toHaveCount(10)
                ->and($dias['2026-09-01']['cantidad'])->toBe(2)
                // El día sin pedidos se lista igual, en cero: no desaparece de la tabla.
                ->and($dias['2026-09-02']['cantidad'])->toBe(0)
                ->and($dias['2026-09-03']['cantidad'])->toBe(1)
                ->and($dias['2026-09-10']['esHoy'])->toBeTrue()
                ->and($dias['2026-09-01']['esHoy'])->toBeFalse();
        });
});

test('only the finished ones are counted', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    unPedidoFinalizadoEl('2026-09-05 10:00:00');

    foreach ([Pedido::ESTADO_CANCELADO, Pedido::ESTADO_RECHAZADO, Pedido::ESTADO_EN_CURSO, Pedido::ESTADO_SIN_ASIGNAR] as $estado) {
        Pedido::factory()->create([
            'estado' => $estado,
            'created_at' => Carbon::parse('2026-09-05 11:00:00'),
        ]);
    }

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-09')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['resumen']['total'],
        )->toBe('1'));
});

test('a deleted order is not counted', function () {
    // ⚠️ Importa más de lo que parece: la oficina se deshace de los pedidos que no llegan al
    // final borrándolos, así que entre los borrados hay muchos. El modelo viejo también los
    // excluye, por el scope de SoftDeletes.
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    unPedidoFinalizadoEl('2026-09-05 10:00:00');
    unPedidoFinalizadoEl('2026-09-05 11:00:00')->delete();

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-09')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['resumen']['total'],
        )->toBe('1'));
});

test('an order at half past eleven at night belongs to that day and not the next', function () {
    // ⚠️ Esto sujeta la zona horaria de la conexión. El `group by` corta el día con
    // `date(created_at)`, que usa la zona de la sesión de Postgres, y la conexión sale con
    // `set time zone 'America/Argentina/Tucuman'` —ver config/database.php—. Si eso se
    // cambiara a UTC, las 23:30 de Tucumán son las 02:30 del día siguiente y este pedido se
    // contaría en el día que no es. La empresa cierra a las nueve de la noche, así que con los
    // datos de hoy nadie lo notaría.
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    unPedidoFinalizadoEl('2026-09-05 23:30:00');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-09')
        ->assertInertia(function ($pagina) {
            $dias = collect($pagina->toArray()['props']['dias'])->keyBy('fecha');

            expect($dias['2026-09-05']['cantidad'])->toBe(1)
                ->and($dias['2026-09-06']['cantidad'])->toBe(0);
        });
});

test('the orders of another month are not counted', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    unPedidoFinalizadoEl('2026-08-31 23:59:59');
    unPedidoFinalizadoEl('2026-09-01 00:00:00');
    unPedidoFinalizadoEl('2026-09-10 11:00:00');

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-09')
        ->assertInertia(fn ($pagina) => expect(
            $pagina->toArray()['props']['resumen']['total'],
        )->toBe('2'));
});

test('it asks the database once, and not once per day of the month', function () {
    // ⚠️ El controlador viejo hace un `count()` adentro de un `while` sobre los días. Medido
    // contra los 898.189 pedidos finalizados de la base de desarrollo: 166 ms las treinta
    // consultas contra 15 ms un `group by`.
    Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00'));

    unPedidoFinalizadoEl('2026-09-05 10:00:00');

    $consultas = 0;
    DB::listen(function ($consulta) use (&$consultas) {
        if (str_contains($consulta->sql, 'from "pedidos"')) {
            $consultas++;
        }
    });

    $this->actingAs(usuarioCon(Rol::EMPLEADO))->get('/panel/estadisticas?mes=2026-09');

    expect($consultas)->toBe(1);
});

test('the summary is the one that comes out of the listed days', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00'));

    // Cuatro días: 2, 0, 6 y 0 pedidos. Total 8, promedio 2,0, pico 6, dos días sin pedidos.
    unPedidoFinalizadoEl('2026-09-01 10:00:00');
    unPedidoFinalizadoEl('2026-09-01 11:00:00');

    foreach (range(1, 6) as $cual) {
        unPedidoFinalizadoEl('2026-09-03 1'.$cual.':00:00');
    }

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-09')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('resumen.total', '8')
            ->where('resumen.promedio', '2,0')
            ->where('resumen.dias', 4)
            ->where('resumen.sinPedidos', 2)
            ->where('resumen.pico', 6)
            ->where('resumen.mejor.cantidad', 6));
});

test('a month without a single order says so instead of dividing by zero', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-05')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('resumen.total', '0')
            ->where('resumen.promedio', '0,0')
            ->where('resumen.pico', 0)
            // Sin pedidos no hay mejor día, y el primero del mes con cero no es «el mejor».
            ->where('resumen.mejor', null));
});

test('the very first day of a month lists that one day and does not fail', function () {
    // Pasa de verdad: un mes del futuro se trae al actual, pero el día 1 a las 00:00 el mes
    // actual tiene un solo día y en el primer instante todavía ninguno con pedidos.
    Carbon::setTestNow(Carbon::parse('2026-09-01 00:00:00'));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('mes.valor', '2026-09')
            ->where('resumen.dias', 1));
});

test('the screen says which month it is showing, and what the picker allows', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes=2026-07')
        ->assertInertia(fn ($pagina) => $pagina
            ->where('mes.valor', '2026-07')
            ->where('mes.nombre', 'julio de 2026')
            ->where('mes.tope', '2026-09')
            ->where('mes.anterior', '2026-06')
            ->where('mes.siguiente', '2026-08'));
});

test('a month in the URL that is not a month opens the current one instead of failing', function () {
    // ⚠️ En el sistema viejo esto es un 500: `createFromFormat` tira InvalidFormatException.
    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

    foreach (['cualquiera', '2026-13', ''] as $texto) {
        $this->actingAs(usuarioCon(Rol::EMPLEADO))
            ->get('/panel/estadisticas?mes='.$texto)
            ->assertSuccessful()
            ->assertInertia(fn ($pagina) => $pagina->where('mes.valor', '2026-09'));
    }

    // Y un arreglo tampoco: `?mes[]=x` hace que `query('mes')` no sea una cadena.
    $this->actingAs(usuarioCon(Rol::EMPLEADO))
        ->get('/panel/estadisticas?mes[]=2026-08')
        ->assertSuccessful()
        ->assertInertia(fn ($pagina) => $pagina->where('mes.valor', '2026-09'));
});
