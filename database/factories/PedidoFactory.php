<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Pedido;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
{
    /**
     * Por omisión, un pedido del panel: sin cliente vinculado y con el nombre
     * suelto, que es el 57% de los pedidos reales.
     *
     * `numero` no se define: lo pone `pedidos_numero_seq`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direccion' => fake()->streetAddress(),
            'destino' => fake()->streetAddress(),
            'detalle' => fake()->sentence(),
            'telefono' => fake()->numerify('381#######'),
            'valor' => fake()->randomFloat(2, 500, 9000),
            'plataforma_origen' => Pedido::PLATAFORMA_WEB,
            'estado' => Pedido::ESTADO_SIN_ASIGNAR,
            'nombre_cliente' => fake()->name(),
        ];
    }

    /** Un pedido de la app, vinculado a su cliente. */
    public function deLaApp(): static
    {
        return $this->state(fn (array $atributos): array => [
            'plataforma_origen' => Pedido::PLATAFORMA_APP,
            'nombre_cliente' => null,
            'cliente_id' => Cliente::factory()->deLaApp(),
        ]);
    }

    public function enEstado(string $estado): static
    {
        return $this->state(fn (array $atributos): array => ['estado' => $estado]);
    }
}
