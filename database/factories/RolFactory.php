<?php

namespace Database\Factories;

use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rol>
 */
class RolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rol = fake()->unique()->regexify('[a-z]{6,12}');

        return [
            'rol' => $rol,
            'display_rol' => ucfirst($rol),
        ];
    }

    public function llamado(string $rol): static
    {
        return $this->state(fn (array $atributos): array => [
            'rol' => $rol,
            'display_rol' => ucfirst($rol),
        ]);
    }
}
