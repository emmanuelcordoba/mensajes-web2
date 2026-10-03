<?php

namespace App\Http\Requests\Panel;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Lo que el panel acepta al crear o editar una cuenta.
 *
 * Una sola clase para los dos caminos: las diferencias son dos —la contraseña es
 * obligatoria sólo al crear, y al editar el email se compara ignorando la propia fila—,
 * y tenerlas juntas es lo que hace que no se separen. En el sistema viejo eran dos
 * `FormRequest` y la regla del rol estaba sólo en el de alta, así que la edición no la
 * tenía: por eso allá el rol directamente no se podía cambiar.
 */
class GuardarUsuarioRequest extends FormRequest
{
    /** La autorización la hace la política, desde el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ⚠️ El email se normaliza ANTES de validar, no al guardar.
     *
     * El modelo lo baja a minúsculas y lo recorta en su mutador. Si acá se validara
     * «Ana@X.com» tal cual, `unique` no encontraría la fila guardada como «ana@x.com»:
     * pasaría la validación y reventaría contra el índice único al escribir. De ahí
     * salieron los 77 grupos de emails repetidos de producción. Ver DATA-7.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->value()))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editando = $this->route('user') instanceof User ? $this->route('user') : null;

        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // ⚠️ Sin `withoutTrashed()`: una cuenta dada de baja SIGUE ocupando su
                // email, porque `users.email` es UNIQUE a secas. Es la mitad de DATA-7
                // que no era obvia.
                Rule::unique('users', 'email')->ignore($editando?->id),
            ],

            // Al editar, en blanco significa «no la toques». Sin esto, un cambio de
            // nombre le borraría la contraseña a la persona.
            'password' => [
                $editando === null ? 'required' : 'nullable',
                'string',
                Password::default(),
                'confirmed',
            ],

            'rol_id' => [
                'required',
                Rule::exists('roles', 'id')->where(
                    fn ($consulta) => $consulta->whereIn('rol', Rol::DEL_PANEL),
                ),
                // ⚠️ Sólo un administrador crea o nombra a otro administrador. Ver SEC-4:
                // hasta que el panel se separó, una cuenta de comercio llegaba a esta
                // pantalla y podía crearse un administrador.
                function (string $atributo, mixed $valor, callable $falla): void {
                    $esAdmin = Rol::query()->whereKey($valor)->value('rol') === Rol::ADMIN;

                    if ($esAdmin && ! $this->user()?->tieneRol(Rol::ADMIN)) {
                        $falla('Sólo un administrador puede nombrar a otro administrador.');
                    }
                },
            ],

            // Sólo significa algo para un comercio; para los demás roles la columna queda
            // en null, que es lo que corresponde. Ver PANEL-3.
            'cliente_restringido_id' => [
                Rule::requiredIf(fn (): bool => $this->esComercio()),
                'nullable',
                Rule::exists('clientes', 'id')->whereNull('deleted_at'),
            ],

            'iniciales' => ['nullable', 'string', 'max:2'],
            'color' => ['nullable', 'string', 'max:9'],
        ];
    }

    /** Si el rol elegido es el de un comercio. */
    public function esComercio(): bool
    {
        return Rol::query()->whereKey($this->input('rol_id'))->value('rol') === Rol::RESTRINGIDO;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'rol_id' => 'rol',
            'cliente_restringido_id' => 'comercio',
        ];
    }
}
