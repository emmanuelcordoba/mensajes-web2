<?php

namespace App\Http\Requests\Panel;

use App\Models\Cadete;
use App\Services\AsignacionDeCadete;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Lo que manda el modal de la lista de espera.
 *
 * ⚠️ **Los largos se comprueban acá y en el sistema viejo no se comprueban en ningún lado.**
 * Allá la validación son cuatro reglas —`pedido`, `direccion` y `valor` requeridos, `cadete`
 * opcional— y el resto entra como venga: el `maxlength` del input es lo único que frena, y
 * eso lo pone el navegador. Sobre MongoDB un campo más largo que la pantalla se guardaba sin
 * chistar; sobre `VARCHAR(255)` Postgres rechaza la consulta y la pantalla se cae con un 500.
 * Los números de acá son los de las columnas.
 */
class AsignarCadeteRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Las dos que el sistema viejo sí exige. Un pedido sin dirección no se puede
            // entregar, y uno sin precio asignado a un cadete de saldo le cobra cero.
            'direccion' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],

            'destino' => ['nullable', 'string', 'max:255'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'detalle' => ['nullable', 'string', 'max:1000'],
            'garantia' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'retorno_origen' => ['boolean'],

            'cadete_id' => ['nullable', 'integer', 'exists:cadetes,id'],
        ];
    }

    /**
     * El cadete elegido, o null.
     *
     * ⚠️ Instancia y no `static`: una propiedad estática la comparten todos los requests del
     * proceso, y el segundo pedido de la misma petición se encontraría con el cadete del
     * primero. Es el error que ya apareció en `AgregarAColaRequest`.
     */
    private ?Cadete $cadete = null;

    private bool $resuelto = false;

    public function cadete(): ?Cadete
    {
        if (! $this->resuelto) {
            $id = $this->input('cadete_id');
            $this->cadete = is_numeric($id) ? Cadete::query()->find($id) : null;
            $this->resuelto = true;
        }

        return $this->cadete;
    }

    /**
     * El saldo se comprueba acá para que el motivo salga como error del campo `cadete_id`,
     * al lado del selector, y no como un aviso suelto.
     *
     * Es la misma función que el servicio consulta antes de escribir: una regla, dos
     * lectores.
     */
    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $cadete = $this->cadete();

                if ($cadete === null) {
                    return;
                }

                $motivo = AsignacionDeCadete::porQueNo($cadete, (string) $this->input('valor'));

                if ($motivo !== null) {
                    $validator->errors()->add('cadete_id', $motivo);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'direccion.required' => 'Un pedido sin dirección no se puede entregar.',
            'valor.required' => 'Hace falta el precio: es lo que se le cobra al cadete.',
            'cadete_id.exists' => 'Ese cadete no existe.',
        ];
    }
}
