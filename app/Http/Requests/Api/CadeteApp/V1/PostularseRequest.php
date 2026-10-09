<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

use App\Models\Cadete;
use App\Models\Postulacion;
use App\Models\PostulacionDocumento;
use App\Services\DocumentosDePostulacion;
use App\Support\FechaDeNacimiento;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Lo que alguien manda desde la app para postularse como cadete.
 *
 * Son los 17 campos del formulario: ocho datos, cuatro imágenes, cuatro preguntas y a quién
 * conoce. **Es el único endpoint público que escribe** —el login no escribe y el reseteo
 * necesita un código—, así que es la única puerta por la que un desconocido deja una fila y
 * cuatro archivos en el servidor.
 *
 * ⚠️ **El DNI y el email se comparan contra las dos tablas**, como en el sistema viejo: no
 * sirve de nada aceptar una postulación con un DNI o un email que después no va a poder
 * contratarse. Es la misma regla que `GuardarPostulacionRequest` del panel.
 *
 * ⚠️ **La fecha de nacimiento tiene el rango de las otras tres pantallas.** El sistema viejo
 * acá valida `required|date` a secas, así que por esta puerta entraban las fechas imposibles
 * que el panel sí frena: de los 41 casos de DATA-10, 21 son postulaciones. El rango vive en
 * `FechaDeNacimiento`.
 *
 * ⚠️ **`tipo_vehiculo` se valida contra la lista**, y allá era `required` a secas: entraba
 * cualquier letra, y después el panel de contratación la muestra como vehículo del cadete.
 *
 * ⚠️ **Las respuestas del cuestionario también.** Allá es `required` y nada más; las 725
 * filas del origen sólo tienen 'S' y 'N', así que eso es el dominio.
 *
 * ⚠️ `fcm_token` llega y se ignora: la app lo manda siempre en null —`data.fcm_token = null`
 * en `postulacion.page.ts`— y una postulación no es una cuenta, así que no hay dónde
 * guardarlo ni a quién notificarle.
 */
class PostularseRequest extends PeticionDeLaApp
{
    /**
     * ⚠️ El email se baja a minúsculas ANTES de validar, por lo mismo que en el panel: el
     * modelo lo normaliza al guardar, y validar «Ana@X.com» tal cual deja que `unique` no
     * encuentre la fila guardada como «ana@x.com». Ver DATA-7.
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
            'tipo_vehiculo' => ['required', Rule::in(array_keys(Cadete::VEHICULOS))],

            'fecha_nacimiento' => ['required', ...FechaDeNacimiento::reglas()],

            // Contra las dos tablas, y contando a los cadetes dados de baja, porque el
            // UNIQUE de `cadetes.dni` los cuenta.
            'dni' => [
                'required',
                'regex:/^\d{7,8}$/',
                Rule::unique('postulaciones', 'dni')->whereNull('deleted_at'),
                Rule::unique('cadetes', 'dni'),
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('postulaciones', 'email')->whereNull('deleted_at'),
                Rule::unique('users', 'email'),
            ],

            'recomendado_por' => ['nullable', 'string', 'max:100'],

            ...$this->reglasDeLasPreguntas(),
            ...$this->reglasDeLasImagenes(),
        ];
    }

    /**
     * Las cuatro preguntas, todas obligatorias y todas S o N.
     *
     * @return array<string, mixed>
     */
    protected function reglasDeLasPreguntas(): array
    {
        $reglas = [];

        foreach (array_keys(Postulacion::PREGUNTAS) as $campo) {
            $reglas[$campo] = ['required', Rule::in([Postulacion::SI, Postulacion::NO])];
        }

        return $reglas;
    }

    /**
     * Las cuatro imágenes, todas obligatorias y todas un `data:` que se pueda leer.
     *
     * ⚠️ Las cuatro son obligatorias acá y en el formulario de la app, pero para CONTRATAR
     * alcanzan tres: la foto de perfil no es motivo para no contratar a nadie. Ver
     * `DocumentosDePostulacion::PARA_CONTRATAR`. Son dos preguntas distintas y es a propósito
     * que no sean la misma lista.
     *
     * @return array<string, mixed>
     */
    protected function reglasDeLasImagenes(): array
    {
        $reglas = [];

        foreach (PostulacionDocumento::TIPOS as $tipo) {
            $reglas[$tipo] = [
                'required',
                'string',
                function (string $atributo, mixed $valor, Closure $falla): void {
                    $motivo = DocumentosDePostulacion::porQueNoSirve($valor);

                    if ($motivo !== null) {
                        $falla($motivo);
                    }
                },
            ];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...FechaDeNacimiento::mensajes(),
            'dni.regex' => 'El DNI tiene que tener 7 u 8 dígitos, sin puntos ni espacios.',
            'dni.unique' => 'Ese DNI ya está cargado, en otra postulación o en un cadete.',
            'email.unique' => 'Ese email ya está cargado, en otra postulación o en una cuenta.',
            'foto.required' => 'La foto de perfil es obligatoria.',
            'dni_frente.required' => 'La foto del frente del DNI es obligatoria.',
            'dni_dorso.required' => 'La foto del dorso del DNI es obligatoria.',
            'boleta_de_servicio.required' => 'La foto de una boleta de servicio es obligatoria.',
            ...$this->mensajesDeLasPreguntas(),
        ];
    }

    /**
     * El mismo texto para las cuatro, como en el sistema viejo.
     *
     * @return array<string, string>
     */
    protected function mensajesDeLasPreguntas(): array
    {
        $mensajes = [];

        foreach (array_keys(Postulacion::PREGUNTAS) as $campo) {
            $mensajes[$campo.'.required'] = 'Por favor complete el cuestionario.';
            $mensajes[$campo.'.in'] = 'Por favor complete el cuestionario.';
        }

        return $mensajes;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo_vehiculo' => 'vehículo',
            'dni_frente' => 'frente del DNI',
            'dni_dorso' => 'dorso del DNI',
            'boleta_de_servicio' => 'boleta de servicio',
        ];
    }
}
