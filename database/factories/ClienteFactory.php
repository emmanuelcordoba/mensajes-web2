<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    /**
     * Por omisión, un cliente del panel: tiene `nombre` y no la persona.
     *
     * `numero` no se define a propósito: lo pone `clientes_numero_seq`, que es lo
     * que hace el sistema nuevo. Definirlo acá haría que las pruebas no ejerciten
     * el camino real.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->company(),
            'direccion' => fake()->streetAddress(),
            'telefono' => fake()->numerify('381#######'),
        ];
    }

    /** Un cliente que se registró desde la app: tiene la persona, no el comercio. */
    public function deLaApp(): static
    {
        return $this->state(fn (array $atributos): array => [
            'nombre' => null,
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'plataforma' => Cliente::PLATAFORMA_APP,
        ]);
    }
}
