<?php

namespace App\Http\Requests\Panel;

use App\Models\Cadete;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Lo que el panel acepta al crear o editar un cadete.
 *
 * Una sola clase para los dos caminos, como en cuentas: las diferencias son la contraseña
 * —obligatoria sólo al crear— y las dos unicidades, que al editar ignoran la propia fila.
 * En el sistema viejo eran dos `FormRequest` casi iguales, y el de edición resolvía el id
 * leyendo un campo oculto del formulario (`$this->request->get('_id')`) para después
 * buscar el cadete: el id de lo que se edita viene de la ruta, no del cuerpo.
 *
 * ⚠️ `numero_movil` y `dni` se comparan **contando a los dados de baja**, porque los dos
 * UNIQUE de la tabla los cuentan (decidido el 2026-09-12). `Rule::unique` consulta la
 * tabla sin el scope de SoftDeletes, así que es justo lo que corresponde acá y no un
 * descuido.
 *
 * ⚠️ El DNI no necesita la regla `NumeroNoRepetido` del sistema viejo, que existía para
 * comparar sin mirar el tipo: allá el mismo documento convivía como `"30111222"` y
 * `30111222` —105 grupos repetidos al castear, DATA-4—. La columna es INTEGER.
 */
class GuardarCadeteRequest extends FormRequest
{
    /** La autorización la hace la política, desde el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ⚠️ El email se normaliza ANTES de validar, no al guardar, por lo mismo que en
     * cuentas: el modelo lo baja a minúsculas en su mutador, y validar «Ana@X.com» tal
     * cual deja que `unique` no encuentre la fila guardada como «ana@x.com» —pasa la
     * validación y revienta contra el índice al escribir—. Ver DATA-7.
     *
     * La casilla de la cuota propia se normaliza también: un checkbox sin marcar no viaja,
     * y `boolean` sobre un campo ausente no es lo mismo que sobre `false`.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->value()))]);
        }

        $this->merge(['tiene_monto_semanal_personal' => $this->boolean('tiene_monto_semanal_personal')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editando = $this->cadete();

        // Al editar un cadete cuya cuenta está dada de baja no hay dónde escribir el email
        // ni la contraseña: los dos quedan `prohibited`, con su mensaje.
        $conCuenta = $editando === null || $editando->user !== null;

        return [
            'nombres' => ['required', 'string', 'max:60'],
            'apellidos' => ['required', 'string', 'max:60'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],

            // ⚠️ La columna es DATE NOT NULL **sin CHECK de rango**, así que la base acepta
            // el año 190: hay 32 cadetes con fechas imposibles, 41 personas contando las
            // postulaciones (DATA-10). El rango se exige acá para que no entren más, y de
            // paso esta pantalla es por donde se arreglan las que ya están.
            'fecha_nacimiento' => [
                'required',
                'date',
                'after_or_equal:'.now()->subYears(90)->toDateString(),
                'before_or_equal:'.now()->subYears(16)->toDateString(),
            ],

            'dni' => [
                'nullable',
                'regex:/^\d{7,8}$/',
                Rule::unique('cadetes', 'dni')->ignore($editando?->id),
            ],

            'numero_movil' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('cadetes', 'numero_movil')->ignore($editando?->id),
            ],

            'tipo_vehiculo' => ['required', Rule::in(array_keys(Cadete::VEHICULOS))],

            'tiene_monto_semanal_personal' => ['boolean'],

            'observaciones' => ['nullable', 'string', 'max:2000'],

            'email' => $conCuenta
                ? [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    // Sin `withoutTrashed()`: una cuenta dada de baja sigue ocupando su
                    // email, porque `users.email` es UNIQUE a secas. Ver DATA-7.
                    Rule::unique('users', 'email')->ignore($editando?->user_id),
                ]
                : ['prohibited'],

            // ⚠️ El panel viejo pedía un PIN numérico de 3 a 8 dígitos. Acá se pide una
            // contraseña como la de cualquier cuenta: es la misma tabla `users` y el mismo
            // login. Si la app necesita un PIN, eso es un mecanismo aparte y no una
            // contraseña más corta para todos.
            //
            // Al editar, en blanco significa «no la toques», igual que en cuentas: sin eso,
            // corregir un teléfono le cambiaría la contraseña a la persona.
            'password' => match (true) {
                $editando === null => ['required', 'string', Password::default(), 'confirmed'],
                $conCuenta => ['nullable', 'string', Password::default(), 'confirmed'],
                default => ['prohibited'],
            },
        ];
    }

    /** El cadete que se está editando, o null si es un alta. */
    public function cadete(): ?Cadete
    {
        $deLaRuta = $this->route('cadete');

        return $deLaRuta instanceof Cadete ? $deLaRuta : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dni.regex' => 'El DNI tiene que tener 7 u 8 dígitos, sin puntos ni espacios.',
            'dni.unique' => 'Ese DNI ya lo tiene otro cadete, aunque esté dado de baja.',
            'numero_movil.unique' => 'Ese número de móvil ya lo tiene otro cadete, aunque esté dado de baja.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no puede ser de hace más de 90 años.',
            'fecha_nacimiento.before_or_equal' => 'El cadete tiene que tener al menos 16 años.',
            'email.prohibited' => 'Este cadete no tiene cuenta activa, así que no hay email que cambiar.',
            'password.prohibited' => 'Este cadete no tiene cuenta activa, así que no hay contraseña que cambiar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'numero_movil' => 'número de móvil',
            'tipo_vehiculo' => 'vehículo',
            'tiene_monto_semanal_personal' => 'cuota semanal propia',
        ];
    }
}
