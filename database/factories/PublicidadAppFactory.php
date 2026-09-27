<?php

namespace Database\Factories;

use App\Models\PublicidadApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicidadApp>
 */
class PublicidadAppFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => fake()->url(),
        ];
    }

    public function oculta(): static
    {
        return $this->state(fn (array $atributos): array => ['visible' => false]);
    }
}
