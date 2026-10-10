<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Lo que un cliente puede cambiar de su perfil desde la app.
 *
 * Son cinco campos. Lo que NO está: `numero`, `plataforma`, `fcm_token`, `user_id` y el
 * `nombre` que escribió el panel. El sistema viejo tampoco los acepta acá, pero los escribe
 * con asignación directa sobre el modelo entero.
 *
 * ⚠️ **`direccion` acepta 255 y el sistema viejo 30**, igual que en el registro: la columna
 * es `VARCHAR(255)` y treinta caracteres no alcanzan para una dirección de verdad.
 */
class GuardarPerfilDelClienteRequest extends PeticionDeLaApp
{
    /**
     * ⚠️ El email se baja a minúsculas ANTES de validar: el modelo lo normaliza en su
     * mutador, y validar «Ana@X.com» tal cual deja que `unique` no encuentre la fila
     * guardada como «ana@x.com». Ver DATA-7.
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
        return [
            'nombres' => ['required', 'string', 'max:60'],
            'apellidos' => ['required', 'string', 'max:60'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Sin `withoutTrashed()`: una cuenta dada de baja sigue ocupando su email.
                Rule::unique('users', 'email')->ignore($this->laCuenta()?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ese email ya está en uso.',
        ];
    }

    /** La cuenta que está autenticada, para que `unique` se ignore a sí misma. */
    public function laCuenta(): ?User
    {
        $usuario = $this->user();

        return $usuario instanceof User ? $usuario : null;
    }
}
