<?php

namespace Database\Factories;

use App\Models\Cadete;
use App\Models\MovimientoCobranzaSaldo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoCobranzaSaldo>
 */
class MovimientoCobranzaSaldoFactory extends Factory
{
    /**
     * Por omisión, una carga de saldo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cadete_id' => Cadete::factory()->porSaldo(),
            'monto' => '1000.00',
            'monto_positivo' => true,
            'saldo_parcial' => '5000.00',
            'tipo' => MovimientoCobranzaSaldo::CARGA_SALDO,
        ];
    }

    /** Un descuento por un pedido finalizado. */
    public function descuento(?int $pedidoId = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'monto_positivo' => false,
            'tipo' => MovimientoCobranzaSaldo::PEDIDO_FINALIZADO,
            'pedido_id' => $pedidoId,
        ]);
    }
}
