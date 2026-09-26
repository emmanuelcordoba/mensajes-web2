<?php

namespace Database\Factories;

use App\Models\Cadete;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cadete>
 */
class CadeteFactory extends Factory
{
    /**
     * Por omisión, un cadete inactivo en bicicleta y cobranza semanal.
     *
     * `numero_movil` y `dni` son UNIQUE incluyendo a los dados de baja, así que el
     * factory los tiene que generar únicos de verdad.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'numero_movil' => fake()->unique()->numberBetween(1, 900_000),
            'apellidos' => fake()->lastName(),
            'nombres' => fake()->firstName(),
            'direccion' => fake()->streetAddress(),
            'telefono' => fake()->numerify('381#######'),
            'fecha_nacimiento' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'dni' => fake()->unique()->numberBetween(10_000_000, 45_000_000),
            'estado' => Cadete::ESTADO_INACTIVO,
            'tipo_vehiculo' => Cadete::VEHICULO_BICICLETA,
            'modalidad_cobranza' => Cadete::COBRANZA_SEMANAL,
            'user_id' => User::factory(),
        ];
    }

    /** En la cola, esperando un pedido. El orden de cola es un timestamp Unix. */
    public function enLaCola(?int $ordenCola = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'estado' => Cadete::ESTADO_ACTIVO_APP,
            'orden_cola' => $ordenCola ?? now()->getTimestamp(),
        ]);
    }

    public function enEstado(string $estado): static
    {
        return $this->state(fn (array $atributos): array => ['estado' => $estado]);
    }

    /** Cobra por saldo en vez de por semana. */
    public function porSaldo(string $saldo = '5000.00'): static
    {
        return $this->state(fn (array $atributos): array => [
            'modalidad_cobranza' => Cadete::COBRANZA_SALDO,
            'cobranza_saldo' => $saldo,
        ]);
    }
}
