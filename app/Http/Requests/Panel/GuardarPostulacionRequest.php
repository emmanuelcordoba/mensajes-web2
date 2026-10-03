<?php

namespace App\Http\Requests\Panel;

use App\Models\Cadete;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que el panel acepta al cargar una postulación.
 *
 * Es el alta que la oficina usa cuando alguien se presenta en persona; la otra puerta es
 * la app, que no está portada.
 *
 * ⚠️ **La contraseña no se pide.** El formulario viejo la pedía y la tabla guardaba una
 * contraseña **en texto plano** por postulación: se limpiaron las 705 el 2026-09-16 y la
 * columna no existe más. La cuenta se crea al contratar, con su hash, y la contraseña se
 * muestra una sola vez.
 *
 * ⚠️ Las cuatro imágenes se suben como archivos. El formulario viejo mandaba un campo de
 * archivo y un campo oculto con el base64 que completaba el JavaScript, y validaba el de
 * archivo mientras guardaba el oculto: si ese JS fallaba, la postulación se guardaba sin
 * imágenes. Es el mismo problema que tenía la publicidad.
 */
class GuardarPostulacionRequest extends FormRequest
{
    /** La autorización la hace la política, desde el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /** Igual que en cuentas y en cadetes: se normaliza antes de validar. Ver DATA-7. */
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
        $reglas = [
            'nombres' => ['required', 'string', 'max:60'],
            'apellidos' => ['required', 'string', 'max:60'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],

            // El mismo rango que en cadetes: la columna no tiene CHECK y hay 21
            // postulaciones con fechas imposibles, todas con forma de fecha de carga
            // (DATA-10).
            'fecha_nacimiento' => [
                'required',
                'date',
                'after_or_equal:'.now()->subYears(90)->toDateString(),
                'before_or_equal:'.now()->subYears(16)->toDateString(),
            ],

            // ⚠️ Contra las dos tablas, como en el sistema viejo: no sirve de nada
            // aceptar una postulación con un DNI que después no va a poder contratarse.
            // Sin `withoutTrashed()` en ninguna de las dos, porque los dos UNIQUE cuentan
            // a los dados de baja.
            'dni' => [
                'required',
                'regex:/^\d{7,8}$/',
                Rule::unique('postulaciones', 'dni'),
                Rule::unique('cadetes', 'dni'),
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('postulaciones', 'email'),
                Rule::unique('users', 'email'),
            ],

            'tipo_vehiculo' => ['required', Rule::in(array_keys(Cadete::VEHICULOS))],

            'recomendado_por' => ['nullable', 'string', 'max:100'],
        ];

        foreach (array_keys(Postulacion::PREGUNTAS) as $pregunta) {
            $reglas[$pregunta] = ['required', Rule::in([Postulacion::SI, Postulacion::NO])];
        }

        foreach (PostulacionDocumento::TIPOS as $tipo) {
            // Los cuatro, como pedía el formulario viejo. Para contratar hacen falta tres
            // —ver DocumentosDePostulacion::PARA_CONTRATAR—, pero quien carga la
            // postulación tiene a la persona enfrente y es el momento de sacarle la foto.
            $reglas[$tipo] = ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dni.regex' => 'El DNI tiene que tener 7 u 8 dígitos, sin puntos ni espacios.',
            'dni.unique' => 'Ese DNI ya está cargado, en otra postulación o en un cadete.',
            'email.unique' => 'Ese email ya está cargado, en otra postulación o en una cuenta.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no puede ser de hace más de 90 años.',
            'fecha_nacimiento.before_or_equal' => 'Tiene que tener al menos 16 años.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo_vehiculo' => 'vehículo',
            'foto' => 'foto de perfil',
            'dni_frente' => 'frente del DNI',
            'dni_dorso' => 'dorso del DNI',
            'boleta_de_servicio' => 'boleta de servicio',
            'recomendado_por' => 'recomendado por',
            'pregunta_experiencia_app' => 'experiencia con alguna app',
            'pregunta_tiene_celular' => 'teléfono celular',
            'pregunta_equipo_en_condiciones' => 'estado del equipo',
            'pregunta_tiene_datos' => 'datos móviles',
        ];
    }
}
