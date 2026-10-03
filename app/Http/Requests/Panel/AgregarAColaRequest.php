<?php

namespace App\Http\Requests\Panel;

use App\Models\Cadete;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * Lo que el cadete escribe en el mostrador: su número de móvil y su contraseña.
 *
 * ⚠️ La contraseña se comprueba acá, con `Hash::check`, y no se parece en nada a un
 * login: no abre sesión ni toca la del navegador. Es la del cadete, en la pantalla de la
 * oficina, para probar que el que se pone en la cola es él.
 *
 * ⚠️ Es un formulario que prueba contraseñas, así que la ruta lleva un `throttle`. El
 * sistema viejo no tenía ninguno, y muchas de esas contraseñas son PIN de 3 a 8 dígitos
 * —así las pedía el panel viejo—, o sea que probarlas todas era cuestión de dejarlo
 * corriendo.
 */
class AgregarAColaRequest extends FormRequest
{
    /** El cadete de ese móvil, resuelto una sola vez. */
    protected ?Cadete $cadete = null;

    protected bool $cadeteResuelto = false;

    /** La autorización la hace la política, desde el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero_movil' => [
                'required',
                'integer',
                'min:1',
                function (string $atributo, mixed $valor, callable $falla): void {
                    if ($this->cadete() === null) {
                        $falla('No hay ningún cadete con ese número de móvil.');
                    }
                },
            ],

            'password' => [
                'required',
                'string',
                function (string $atributo, mixed $valor, callable $falla): void {
                    $cadete = $this->cadete();

                    if ($cadete === null) {
                        // Ya falló el móvil; un segundo error no agrega nada.
                        return;
                    }

                    // ⚠️ La cuenta puede faltar aunque `cadetes.user_id` sea NOT NULL:
                    // `User` usa bajas lógicas. Sin hash contra el que comparar, no entra.
                    if (! Hash::check((string) $valor, (string) $cadete->user?->password)) {
                        $falla('La contraseña no es la de ese móvil.');
                    }
                },
            ],
        ];
    }

    /**
     * El cadete de ese número de móvil, o null.
     *
     * Lo preguntan las dos reglas y después el controlador, así que se consulta una vez.
     * La propiedad es de la instancia y no un `static` de la función: un `static` lo
     * compartirían todos los pedidos que corran en el mismo proceso.
     */
    public function cadete(): ?Cadete
    {
        if (! $this->cadeteResuelto) {
            $this->cadeteResuelto = true;

            $this->cadete = Cadete::query()
                ->with(['user.motivoBloqueo'])
                ->where('numero_movil', $this->integer('numero_movil'))
                ->first();
        }

        return $this->cadete;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['numero_movil' => 'número de móvil'];
    }
}
