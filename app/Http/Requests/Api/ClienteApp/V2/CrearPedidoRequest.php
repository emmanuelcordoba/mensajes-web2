<?php

namespace App\Http\Requests\Api\ClienteApp\V2;

use App\Http\Requests\Api\ClienteApp\V1\PeticionDeLaApp;

/**
 * El pedido que el cliente carga desde la app.
 *
 * ⚠️ **Las reglas del depto apuntaban a campos que nadie manda.** El sistema viejo valida
 * `direccion_origen_detalle` y `direccion_destino_detalle`, y la app manda
 * `direccion_origen_depto` y `direccion_destino_depto`, que son los que el código después
 * lee para armar la dirección. O sea que el depto entraba sin control de tipo ni de largo.
 * Allá quedó anotado como «TODO: Fixear en una V3»; acá las reglas apuntan a los campos que
 * existen.
 *
 * ⚠️ **`costo` lo manda el cliente y el servidor lo cree.** Es el precio que él mismo
 * cotizó, dando la vuelta por el teléfono. El porqué de que no se pueda cerrar desde acá
 * está en `PedidoDelClienteApp::crear()`.
 */
class CrearPedidoRequest extends PeticionDeLaApp
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direccion_origen_calle_altura' => ['required', 'string', 'max:40'],
            'direccion_origen_depto' => ['nullable', 'string', 'max:10'],
            'direccion_origen_localidad' => ['required', 'string', 'max:30'],

            'direccion_destino_calle_altura' => ['required', 'string', 'max:40'],
            'direccion_destino_depto' => ['nullable', 'string', 'max:10'],
            'direccion_destino_localidad' => ['required', 'string', 'max:30'],

            'costo' => ['required', 'numeric', 'min:0'],
            'tipo_paquete' => ['required', 'string', 'max:255'],
            'peso_paquete' => ['required', 'string', 'max:255'],
            'retorno_origen' => ['required', 'boolean'],

            'envia_dinero_monto' => ['nullable', 'numeric', 'min:1'],
            'valor_declarado' => ['nullable', 'numeric', 'min:1'],

            'telefono' => ['required', 'string', 'max:255'],
            'detalle' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Los mismos textos del sistema viejo: nombran los campos como los ve la persona, con la
     * (A) del origen y la (B) del destino que la pantalla usa.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'direccion_origen_calle_altura' => 'calle y altura (A)',
            'direccion_origen_depto' => 'departamento (A)',
            'direccion_origen_localidad' => 'localidad (A)',
            'direccion_destino_calle_altura' => 'calle y altura (B)',
            'direccion_destino_depto' => 'departamento (B)',
            'direccion_destino_localidad' => 'localidad (B)',
            'telefono' => 'teléfono',
        ];
    }
}
