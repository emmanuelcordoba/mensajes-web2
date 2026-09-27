<?php

namespace Database\Factories;

use App\Models\Cadete;
use App\Models\MontoSemanal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MontoSemanal>
 */
class MontoSemanalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_vehiculo' => Cadete::VEHICULO_MOTOCICLETA,
            'monto' => '12000.00',
        ];
    }

    public function paraBicicleta(string $monto = '8000.00'): static
    {
        return $this->state(fn (array $atributos): array => [
            'tipo_vehiculo' => Cadete::VEHICULO_BICICLETA,
            'monto' => $monto,
        ]);
    }
}
