<?php

namespace Database\Factories;

use App\Models\Configuracion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Configuracion>
 */
class ConfiguracionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->regexify('[A-Z]{6,14}'),
            'titulo' => fake()->sentence(3),
            'tipo' => Configuracion::TIPO_TEXTO,
            'valor' => fake()->word(),
        ];
    }

    /**
     * Una numérica. El valor va como string porque la columna es TEXT y su CHECK
     * exige dígitos con un punto decimal opcional.
     */
    public function numero(string $nombre, string $valor): static
    {
        return $this->state(fn (array $atributos): array => [
            'nombre' => $nombre,
            'tipo' => Configuracion::TIPO_NUMERO,
            'valor' => $valor,
        ]);
    }

    public function texto(string $nombre, string $valor): static
    {
        return $this->state(fn (array $atributos): array => [
            'nombre' => $nombre,
            'tipo' => Configuracion::TIPO_TEXTO,
            'valor' => $valor,
        ]);
    }
}
