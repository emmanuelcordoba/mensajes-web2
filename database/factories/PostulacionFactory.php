<?php

namespace Database\Factories;

use App\Models\Cadete;
use App\Models\Postulacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Postulacion>
 */
class PostulacionFactory extends Factory
{
    /**
     * Por omisión, una postulación pendiente: sin cadete.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'dni' => fake()->unique()->numberBetween(10_000_000, 45_000_000),
            'fecha_nacimiento' => fake()->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d'),
            'direccion' => fake()->streetAddress(),
            'telefono' => fake()->numerify('381#######'),
            'email' => fake()->unique()->safeEmail(),
            // Los dos valores son los mismos que los de un cadete: una
            // postulación se convierte en uno.
            'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
        ];
    }

    /** Ya contratada: tiene su cadete. */
    public function contratada(): static
    {
        return $this->state(fn (array $atributos): array => [
            'cadete_id' => Cadete::factory(),
        ]);
    }
}
