<?php

namespace Database\Factories;

use App\Models\ActividadCadete;
use App\Models\Cadete;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActividadCadete>
 */
class ActividadCadeteFactory extends Factory
{
    /**
     * Por omisión, un tramo ya cerrado de una hora.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inicio = now()->subHours(2);

        return [
            'cadete_id' => Cadete::factory(),
            'inicio' => $inicio,
            'fin' => $inicio->copy()->addHour(),
            'duracion' => 3_600,
        ];
    }

    /** Un tramo abierto: el cadete todavía está en la app. */
    public function abierta(): static
    {
        return $this->state(fn (array $atributos): array => [
            'fin' => null,
            'duracion' => null,
        ]);
    }
}
