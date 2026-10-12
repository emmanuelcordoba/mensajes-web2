<?php

namespace App\Http\Requests\Api\UserApi\V1;

/**
 * Lo que hace falta para cotizar un viaje: los dos extremos y las dos opciones que mueven el
 * precio.
 */
class CotizarViajeRequest extends PeticionDeLaApi
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->reglasDeUbicacion('origen'),
            ...$this->reglasDeUbicacion('destino'),
            'retorno_origen' => ['nullable', 'boolean'],
            'envia_dinero_monto' => ['nullable', 'numeric', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->mensajesDeUbicacion();
    }
}
