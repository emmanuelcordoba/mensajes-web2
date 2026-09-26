<?php

use App\Models\Cadete;
use App\Models\Configuracion;
use App\Models\Pedido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| El cadete. Es la tabla con más restricciones del esquema, porque era la que tenía
| más datos sucios: DATA-4 y DATA-6. Lo que se prueba acá es sobre todo que ahora
| la base rechace lo que antes entraba.
|
| Cada escritura rechazada va dentro de DB::transaction(), que abre un savepoint:
| en PostgreSQL una sentencia fallida aborta la transacción del test entero y todo
| lo que viniera después fallaría por el motivo equivocado.
*/

test('the dni is unique even against cadetes who are gone', function () {
    // Decidido el 2026-09-12: la unicidad es total. En producción el mismo DNI
    // convivía como "30111222" y 30111222 —105 grupos al castear, DATA-4—, y sobre
    // una columna INTEGER eso no puede volver a pasar.
    $cadete = Cadete::factory()->create(['dni' => 30_111_222]);
    $cadete->delete();

    expect(fn () => DB::transaction(fn () => Cadete::factory()->create(['dni' => 30_111_222])))
        ->toThrow(QueryException::class);
});

test('the mobile number is unique too, and several cadetes may have no dni', function () {
    $cadete = Cadete::factory()->create(['numero_movil' => 52]);
    $cadete->delete();

    expect(fn () => DB::transaction(fn () => Cadete::factory()->create(['numero_movil' => 52])))
        ->toThrow(QueryException::class);

    // Vaciar el DNI es una de las formas de resolver un repetido sin borrar al
    // cadete, así que varios NULL tienen que convivir.
    Cadete::factory()->count(3)->create(['dni' => null]);

    expect(Cadete::query()->whereNull('dni')->count())->toBe(3);
});

test('only the five known states and the two known vehicles are accepted', function () {
    foreach (Cadete::ESTADOS as $estado) {
        expect(Cadete::factory()->enEstado($estado)->create()->estado)->toBe($estado);
    }

    expect(fn () => DB::transaction(fn () => Cadete::factory()->enEstado('suspendido')->create()))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => Cadete::factory()->create(['tipo_vehiculo' => 'A'])))
        ->toThrow(QueryException::class);
});

test('the collection modality defaults to weekly, which is what production lacked', function () {
    // En producción hay cadetes sin modalidad, porque la migración de PANEL-8 se
    // corrió contra mensajes_testing y no contra producción.
    expect(Cadete::factory()->create()->modalidad_cobranza)->toBe(Cadete::COBRANZA_SEMANAL);

    $cadete = Cadete::factory()->create();
    $cadete->setAttribute('modalidad_cobranza', null);

    expect(fn () => DB::transaction(fn () => $cadete->save()))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => Cadete::factory()->create(['modalidad_cobranza' => 'Mensual'])))
        ->toThrow(QueryException::class);
});

test('money keeps its cents here too, because this is somebody debt', function () {
    $cadete = Cadete::factory()->create(['monto_deuda' => '1234.56', 'cobranza_saldo' => '0.07']);

    expect($cadete->fresh()->monto_deuda)->toBeString()->toBe('1234.56')
        ->and($cadete->fresh()->cobranza_saldo)->toBe('0.07');
});

test('the full name puts the surname first, which is how the panel shows it', function () {
    $cadete = Cadete::factory()->create(['nombres' => 'Juan', 'apellidos' => 'Gómez']);

    expect($cadete->nombre_completo)->toBe('Gómez Juan');
});

test('the mobile number suggestion counts cadetes who are gone', function () {
    // No hay secuencia y no debe haberla: en la práctica se recicla el número del
    // cadete que se va, así que los números vuelven para atrás. Ver PANEL-5.
    expect(Cadete::siguienteNumeroMovil())->toBe(1);

    $cadete = Cadete::factory()->create(['numero_movil' => 300]);

    expect(Cadete::siguienteNumeroMovil())->toBe(301);

    // El número de un cadete dado de baja ya fue emitido, y sigue en el UNIQUE.
    $cadete->delete();

    expect(Cadete::siguienteNumeroMovil())->toBe(301);
});

test('the first one in the queue is first, not false', function () {
    // El método viejo devolvía `false` para el primero, porque search() da el
    // índice 0 y el código hacía `$posicion ? $posicion+1 : $posicion`. O sea que
    // el que iba primero veía lo mismo que uno que no estaba en la cola.
    $primero = Cadete::factory()->enLaCola(1_000)->create();
    $segundo = Cadete::factory()->enLaCola(2_000)->create();
    $tercero = Cadete::factory()->enLaCola(3_000)->create();

    expect($primero->posicionEnCola())->toBe(1)
        ->and($segundo->posicionEnCola())->toBe(2)
        ->and($tercero->posicionEnCola())->toBe(3);

    // Uno que no está en la cola no tiene lugar que informar.
    expect(Cadete::factory()->enEstado(Cadete::ESTADO_INACTIVO)->create()->posicionEnCola())
        ->toBeNull();

    // Ni uno activo sin orden de cola, que la columna admite.
    expect(Cadete::factory()->enEstado(Cadete::ESTADO_ACTIVO_APP)->create()->posicionEnCola())
        ->toBeNull();
});

test('the queue only holds the two active states, in arrival order', function () {
    $segundo = Cadete::factory()->enLaCola(2_000)->create();
    $primero = Cadete::factory()->enLaCola(1_000)->create();
    Cadete::factory()->enEstado(Cadete::ESTADO_CON_PEDIDO_APP)->create(['orden_cola' => 500]);
    Cadete::factory()->enEstado(Cadete::ESTADO_POSTULADO)->create(['orden_cola' => 600]);

    expect(Cadete::query()->enLaCola()->pluck('id')->all())->toBe([$primero->id, $segundo->id]);
});

test('asking whether THIS cadete has an order in progress answers about this one', function () {
    // El método viejo consultaba Auth::user()->cadete, o sea que respondía por el
    // cadete de la sesión y no por $this.
    $conPedido = Cadete::factory()->create();
    $sinPedido = Cadete::factory()->create();

    Pedido::factory()->enEstado(Pedido::ESTADO_EN_CURSO)->create(['cadete_id' => $conPedido->id]);
    Pedido::factory()->enEstado(Pedido::ESTADO_FINALIZADO)->create(['cadete_id' => $sinPedido->id]);

    expect($conPedido->tienePedidoEnCurso())->toBeTrue()
        ->and($sinPedido->tienePedidoEnCurso())->toBeFalse();
});

test('the minimum balance rounds up to the next hundred', function () {
    Configuracion::factory()->numero('COBRANZA_SALDO_PORCENTAJE', '18')->create();
    Configuracion::factory()->numero('COSTO_MINIMO', '1950')->create();

    // 18 * (1950/100) = 351 -> 400
    expect(Cadete::saldoMinimo())->toBe(400.0);
});

test('a balance that is already a round hundred is left alone', function () {
    Configuracion::factory()->numero('COBRANZA_SALDO_PORCENTAJE', '20')->create();
    Configuracion::factory()->numero('COSTO_MINIMO', '1500')->create();

    // 20 * (1500/100) = 300, que ya es múltiplo de 100.
    expect(Cadete::saldoMinimo())->toBe(300.0);
});
