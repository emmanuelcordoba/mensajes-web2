<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

/**
 * El número de cliente con el que alguien del panel se registra en la app.
 *
 * ⚠️ `integer` y el sistema viejo valida `required` a secas: `?numero=hola` entraba y
 * buscaba `where('numero', 'hola')`, que en PostgreSQL es un error de tipo —un 500—.
 */
class BuscarClientePorNumeroRequest extends PeticionDeLaApp
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero.required' => 'El número de cliente es obligatorio.',
            'numero.integer' => 'El número de cliente ingresado no es válido.',
            'numero.min' => 'El número de cliente ingresado no es válido.',
        ];
    }
}
