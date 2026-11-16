<?php

use App\Models\Cadete;
use App\Models\Pedido;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/*
| La limpieza diaria de pedidos abandonados.
|
| Un pedido Asignado o En curso que el cadete nunca cerró queda así para siempre, y mientras
| tanto traba a su cadete: `tienePedidoSinTerminar()` es lo que decide si al entregar vuelve
| solo a la fila. Del corte vinieron 382, el más viejo de junio de 2020.
|
| ⚠️ Lo que estos tests sujetan, además de a quién toca, es **qué NO toca**: lo reciente, lo
| terminado, y `updated_at`, que es la antigüedad de cada pedido y el dato con el que se
| eligió el corte.
*/

/** Un pedido de un cadete, con la fecha de su último movimiento. */
function unPedidoQuedadoEn(string $estado, CarbonInterface $cuando, ?Cadete $cadete = null): Pedido
{
    $pedido = Pedido::factory()->enEstado($estado)->create([
        'cadete_id' => ($cadete ?? Cadete::factory()->create())->id,
    ]);

    // Sin tocar los timestamps: escribir `updated_at` con el modelo lo pisaría con ahora.
    Pedido::query()->whereKey($pedido->id)->update(['updated_at' => $cuando]);

    return $pedido->fresh();
}

test('it gives up the orders nobody ever closed', function () {
    $viejo = unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, now()->subDays(31));
    $masViejo = unPedidoQuedadoEn(Pedido::ESTADO_ASIGNADO, now()->subYears(5));

    $this->artisan('pedidos:limpiar-sin-terminar --aplicar')
        ->expectsOutputToContain('Dados de baja 2')
        ->assertSuccessful();

    expect(Pedido::query()->whereKey($viejo->id)->exists())->toBeFalse()
        ->and(Pedido::query()->whereKey($masViejo->id)->exists())->toBeFalse()
        // Borrado lógico: siguen estando para quien los busque con `withTrashed`.
        ->and(Pedido::withTrashed()->whereKey($viejo->id)->exists())->toBeTrue();
});

test('what is still in the street is not touched', function () {
    // ⚠️ El día de la limpieza, el pedido más viejo que de verdad estaba en la calle tenía
    // seis días. Treinta es holgado a propósito.
    $deAyer = unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, now()->subDay());
    $deHaceUnMesJusto = unPedidoQuedadoEn(Pedido::ESTADO_ASIGNADO, now()->subDays(29));

    $this->artisan('pedidos:limpiar-sin-terminar --aplicar')->assertSuccessful();

    expect(Pedido::query()->whereKey($deAyer->id)->exists())->toBeTrue()
        ->and(Pedido::query()->whereKey($deHaceUnMesJusto->id)->exists())->toBeTrue();
});

test('an order that was already resolved is none of its business', function () {
    // Finalizado, Cancelado y Rechazado ya están cerrados: no traban a nadie y no se tocan,
    // por viejos que sean.
    foreach ([Pedido::ESTADO_FINALIZADO, Pedido::ESTADO_CANCELADO, Pedido::ESTADO_RECHAZADO] as $estado) {
        $pedido = unPedidoQuedadoEn($estado, now()->subYears(3));

        $this->artisan('pedidos:limpiar-sin-terminar --aplicar')->assertSuccessful();

        expect(Pedido::query()->whereKey($pedido->id)->exists())->toBeTrue();
    }
});

test('the date of the last movement survives, which is what explains the cut', function () {
    // ⚠️ Por esto el borrado va por consulta y no con `delete()` del modelo: `SoftDeletes`
    // guarda el modelo entero y pisa `updated_at`.
    $cuando = now()->subYears(2)->startOfDay();
    $pedido = unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, $cuando);

    $this->artisan('pedidos:limpiar-sin-terminar --aplicar')->assertSuccessful();

    $borrado = Pedido::withTrashed()->findOrFail($pedido->id);

    expect($borrado->updated_at->toDateTimeString())->toBe($cuando->toDateTimeString())
        ->and($borrado->deleted_at)->not->toBeNull();
});

test('without --aplicar it only says what it would do', function () {
    $pedido = unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, now()->subDays(60));

    $this->artisan('pedidos:limpiar-sin-terminar')
        ->expectsOutputToContain('Se darían de baja 1')
        ->assertSuccessful();

    expect(Pedido::query()->whereKey($pedido->id)->exists())->toBeTrue();
});

test('with too many it does not touch anything and leaves it written', function () {
    // ⚠️ El tope no ahorra trabajo: está para que un día en que algo deje de finalizar
    // pedidos no se lleve medio sistema por delante en silencio.
    Log::spy();

    $uno = unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, now()->subDays(40));
    unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, now()->subDays(40));

    $this->artisan('pedidos:limpiar-sin-terminar --aplicar --tope=1')
        ->expectsOutputToContain('No se tocó ninguno')
        ->assertFailed();

    expect(Pedido::query()->whereKey($uno->id)->exists())->toBeTrue();

    // `warning`, que es el nivel que Telescope guarda.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje, array $datos): bool => str_contains($mensaje, 'tope')
            && $datos['cuantos'] === 2);
});

test('the days can be changed', function () {
    $pedido = unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, now()->subDays(10));

    $this->artisan('pedidos:limpiar-sin-terminar --aplicar --dias=7')->assertSuccessful();

    expect(Pedido::query()->whereKey($pedido->id)->exists())->toBeFalse();
});

test('a cadete with nothing left goes back to the queue when he delivers', function () {
    // El motivo de todo esto, de punta a punta: el cadete que arrastra un pedido de hace
    // años no vuelve solo a la fila al entregar. Después de la limpieza, sí.
    $cadete = Cadete::factory()->create(['estado' => Cadete::ESTADO_CON_PEDIDO_APP]);
    unPedidoQuedadoEn(Pedido::ESTADO_EN_CURSO, now()->subYears(3), $cadete);

    expect($cadete->tienePedidoSinTerminar())->toBeTrue();

    $this->artisan('pedidos:limpiar-sin-terminar --aplicar')->assertSuccessful();

    expect($cadete->tienePedidoSinTerminar())->toBeFalse();
});
