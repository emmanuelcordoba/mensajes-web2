<?php

namespace App\Http\Requests\Panel;

use App\Models\Cadete;
use App\Models\Cliente;
use App\Models\Rol;
use App\Services\AsignacionDeCadete;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Lo que el alta de pedidos de Inicio acepta.
 *
 * ⚠️ **Las reglas cambian según el rol, y así es en el sistema viejo.** Para la oficina hace
 * falta el precio y alcanza con el cliente *o* la dirección —se puede anotar un pedido con el
 * número del comercio y completar el resto después—; para un comercio hacen falta la
 * dirección y el destino, y en cambio no puede poner precio ni elegir cadete: eso lo decide
 * la central.
 *
 * ⚠️ **Los largos se comprueban acá y en el sistema viejo casi no se comprueban.** Allá las
 * reglas son seis y el resto entra como venga: el `maxlength` del input es lo único que
 * frena, y eso lo pone el navegador. Sobre MongoDB un campo más largo se guardaba sin
 * chistar; sobre `VARCHAR(255)` Postgres rechaza la consulta y la pantalla se cae con un 500.
 * Los números son los de las columnas, salvo los dos «Depto. / Oficina», que son los 10
 * caracteres que el sistema viejo ya exige.
 *
 * ⚠️ **Lo que el rol no puede mandar está `prohibited` y no se descarta en silencio.** El
 * controlador viejo lo resuelve con un `$request->merge()` que pisa `valor` y `cadete` con
 * null: funciona, pero una cuenta de comercio que mandara un precio se iba creyendo que lo
 * había puesto. Acá se lo dice.
 */
class GuardarPedidoRequest extends FormRequest
{
    /** La autorización la hace la política, desde el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $comercio = $this->esDeUnComercio();

        return [
            // ⚠️ Uno de los dos, como el sistema viejo: `cliente` required_without:direccion
            // y al revés. Un comercio en cambio no elige cliente —es él— y sí tiene que
            // decir de dónde a dónde.
            'cliente' => $comercio ? ['nullable', 'string', 'max:255'] : ['required_without:direccion', 'nullable', 'string', 'max:255'],
            'cliente_id' => $comercio ? ['prohibited'] : ['nullable', 'integer', 'exists:clientes,id'],

            'direccion' => $comercio ? ['required', 'string', 'max:255'] : ['required_without:cliente', 'nullable', 'string', 'max:255'],
            'destino' => $comercio ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],

            // Los 10 del sistema viejo. El campo del formulario tenía `maxlength="255"`, así
            // que se podía escribir lo que la validación después rechazaba: acá el campo
            // también frena en 10.
            'direccion_detalle' => ['nullable', 'string', 'max:10'],
            'destino_detalle' => ['nullable', 'string', 'max:10'],

            'responsable' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'detalle' => ['nullable', 'string', 'max:1000'],
            'retorno_origen' => ['boolean'],
            'garantia' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],

            // El precio lo pone la central, y para ella es obligatorio.
            'valor' => $comercio ? ['prohibited'] : ['required', 'numeric', 'min:0', 'max:9999999999.99'],

            'cadete_id' => $comercio ? ['prohibited'] : ['nullable', 'integer', 'exists:cadetes,id'],
        ];
    }

    /** Si el que carga es una cuenta de comercio: entonces el pedido es suyo y sin precio. */
    public function esDeUnComercio(): bool
    {
        return $this->user()?->tieneRol(Rol::RESTRINGIDO) === true;
    }

    /**
     * El comercio al que se le carga el pedido cuando lo carga él mismo.
     *
     * ⚠️ Sale de la relación y no de un `switch` sobre el nombre del usuario, que es lo que
     * hacía el panel viejo con dos nombres escritos a mano. Ver PANEL-6.
     */
    public function comercioPropio(): ?Cliente
    {
        return $this->user()?->clienteRestringido;
    }

    /**
     * El cadete elegido, o null.
     *
     * ⚠️ Instancia y no `static`: una propiedad estática la comparten todos los requests del
     * proceso. Es el error que ya apareció en `AgregarAColaRequest`.
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
     * El saldo del cadete, medido contra el precio que se está tipeando.
     *
     * Sale como error del campo `cadete_id` para que el motivo aparezca al lado del selector.
     * Es la misma función que el servicio consulta antes de escribir: una regla, dos lectores.
     *
     * @return list<callable(Validator): void>
     */
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
            function (Validator $validator): void {
                // Un comercio sin su cliente asignado no puede cargar nada, y el panel viejo
                // lo dejaba intentarlo: los campos quedaban vacíos y readonly, el pedido se
                // guardaba sin comercio y nada se lo explicaba. Es un error de configuración
                // de la cuenta, y lo que lo destraba es asignarle el comercio.
                if ($this->esDeUnComercio() && $this->comercioPropio() === null) {
                    $validator->errors()->add(
                        'cliente',
                        'Tu cuenta no tiene un comercio asignado, así que el pedido no se puede'
                        .' cargar a nombre de nadie. Pedile a la central que te lo asigne.',
                    );
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
            'direccion.required_without' => 'Hace falta el cliente o la dirección.',
            'cliente.required_without' => 'Hace falta el cliente o la dirección.',
            'destino.required' => 'Hace falta el destino.',
            'valor.required' => 'Hace falta el precio: es lo que se le cobra al cadete.',
            'valor.prohibited' => 'El precio lo pone la central.',
            'cadete_id.prohibited' => 'El cadete lo asigna la central.',
            'cadete_id.exists' => 'Ese cadete no existe.',
            'cliente_id.prohibited' => 'El pedido se carga a nombre de tu propio comercio.',
            'cliente_id.exists' => 'Ese cliente no existe.',
            'direccion_detalle.max' => 'El departamento u oficina entra en 10 caracteres.',
            'destino_detalle.max' => 'El departamento u oficina entra en 10 caracteres.',
        ];
    }
}
