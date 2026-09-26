<?php

namespace Database\Factories;

use App\Models\LogEstadoPedido;
use App\Models\Pedido;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogEstadoPedido>
 */
class LogEstadoPedidoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pedido_id' => Pedido::factory(),
            'estado' => Pedido::ESTADO_SIN_ASIGNAR,
            'plataforma_origen' => LogEstadoPedido::WEB,
        ];
    }
}
