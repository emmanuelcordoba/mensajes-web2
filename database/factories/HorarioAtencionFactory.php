<?php

namespace Database\Factories;

use App\Models\HorarioAtencion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HorarioAtencion>
 */
class HorarioAtencionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'desde' => '08:00:00',
            'hasta' => '21:00:00',
            'mensaje_horario' => 'Atendemos de 8 a 21.',
            'mensaje_confirmacion' => 'Tu pedido fue recibido.',
        ];
    }

    /** Un horario que cruza la medianoche, que el esquema no impide. */
    public function cruzandoLaMedianoche(): static
    {
        return $this->state(fn (array $atributos): array => [
            'desde' => '22:00:00',
            'hasta' => '02:00:00',
        ]);
    }
}
