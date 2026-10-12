<?php

namespace App\Http\Requests\Api\UserApi\V1;

/**
 * Lo que hace falta para dar de alta un pedido por la API.
 *
 * ⚠️ **`costo` no está, y si viene se ignora.** El precio lo calcula el servidor con el mismo
 * cálculo que el endpoint de cotización: un consumidor no puede fijar lo que paga. Es la
 * diferencia con el alta de la app de clientes, que sí acepta el `costo` del teléfono y lo
 * guarda sin contrastarlo (COT-1).
 *
 * ⚠️ `origen` y `destino` son PERSONAS, no direcciones: de quién sale el envío y quién lo
 * recibe. Las direcciones son `direccion_origen` y `direccion_destino`. El pedido las guarda
 * en dos columnas con nombres heredados —`nombre_cliente` y `responsable`— que no dicen eso.
 */
class CrearPedidoPorApiRequest extends PeticionDeLaApi
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->reglasDeUbicacion('origen'),
            ...$this->reglasDeUbicacion('destino'),
            'origen' => ['required', 'string', 'max:255'],
            'destino' => ['required', 'string', 'max:255'],
            'destino_telefono' => ['nullable', 'string', 'max:20'],
            'aclaracion_adicional' => ['nullable', 'string', 'max:1000'],
            'gastronomia' => ['nullable', 'boolean'],
            'retorno_origen' => ['nullable', 'boolean'],
            'envia_dinero_monto' => ['nullable', 'numeric', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->mensajesDeUbicacion(),
            'origen.required' => 'El campo origen es obligatorio: es de quién sale el envío.',
            'destino.required' => 'El campo destino es obligatorio: es quién recibe el envío.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'destino_telefono' => 'teléfono de destino',
            'aclaracion_adicional' => 'aclaración adicional',
        ];
    }
}
