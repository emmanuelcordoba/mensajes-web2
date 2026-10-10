<?php

namespace App\Http\Requests\Api\ClienteApp\V1;

use Illuminate\Validation\Rule;

/**
 * Los seis campos con que alguien nuevo se registra en la app de clientes.
 *
 * ⚠️ **`direccion` acepta 255 y el sistema viejo 30.** Treinta caracteres no alcanzan para
 * una dirección de verdad —«Avenida Nicolás Avellaneda 607» son 30 justos— y la columna es
 * `VARCHAR(255)`, igual que la del panel. Era un tope que no defendía nada y recortaba
 * direcciones reales.
 *
 * ⚠️ **La contraseña pide un mínimo de 6, y el registro viejo no pedía ninguno**: aceptaba
 * una sola letra. El otro registro —el del cliente que ya está en el panel— sí pedía
 * `min:6`, así que eran dos reglas para la misma contraseña según por qué puerta entrara.
 * Ahora es una, y vive acá.
 *
 * ⚠️ `fcm_token` llega y se ignora en el alta: la app lo manda y después lo vuelve a mandar
 * al verificar, que es donde se guarda. Ver `VerificacionController`.
 */
class RegistrarseRequest extends PeticionDeLaApp
{
    public const LARGO_MINIMO_DE_CONTRASENA = 6;

    /**
     * ⚠️ El email se baja a minúsculas ANTES de validar: el modelo lo normaliza en su
     * mutador, y validar «Ana@X.com» tal cual deja que `unique` no encuentre la fila
     * guardada como «ana@x.com» —pasa la validación y revienta contra el índice—. Ver DATA-7.
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
            // Sin `withoutTrashed()`: una cuenta dada de baja sigue ocupando su email,
            // porque `users.email` es UNIQUE a secas. Ver DATA-7.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:'.self::LARGO_MINIMO_DE_CONTRASENA, 'max:255', 'confirmed'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ese email ya está en uso.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña tiene que tener al menos '
                .self::LARGO_MINIMO_DE_CONTRASENA.' caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'correo electrónico',
        ];
    }
}
