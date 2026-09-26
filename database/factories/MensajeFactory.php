<?php

namespace Database\Factories;

use App\Models\Cadete;
use App\Models\Mensaje;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mensaje>
 */
class MensajeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cadete_id' => Cadete::factory(),
            'user_id' => User::factory(),
            'mensaje' => fake()->sentence(),
        ];
    }

    public function leidoEnLaApp(): static
    {
        return $this->state(fn (array $atributos): array => ['leido_app' => true]);
    }

    public function leidoEnLaWeb(): static
    {
        return $this->state(fn (array $atributos): array => ['leido_web' => true]);
    }
}
