<?php

namespace App\Http\Requests\Api\UserApi\V1;

use App\Support\Ubicacion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Lo que comparten las dos peticiones de la API de integración, que es casi todo: los dos
 * extremos del viaje.
 *
 * ⚠️ **El vocabulario es origen/destino.** En el sistema viejo fue `negocio`/`cliente` hasta
 * el 2026-08-28, y había que recordar que el «negocio» era el origen y el «cliente» el
 * destino, cosa que ningún nombre decía. El cambio rompió compatibilidad y se asumió porque
 * la API **no tiene tráfico desde el 2021-12-02** —76 pedidos en total, ninguno en casi
 * cuatro años (DEP-3)—. Acá se porta el vocabulario nuevo, que es el que está hoy.
 *
 * ⚠️ **Cada extremo se puede mandar de dos formas**: calle y altura MÁS la localidad, que es
 * la normal; o coordenadas, para quien ya las tenga resueltas y quiera evitar que el servidor
 * adivine. Si vienen las dos, mandan las coordenadas: son inequívocas.
 *
 * ⚠️ **El 422 va con el formato de la API y no el de Laravel**, igual que en las apps. Un
 * consumidor externo lee el cuerpo, así que la forma es parte del contrato.
 */
abstract class PeticionDeLaApi extends FormRequest
{
    /** El token y el rol los exige la ruta. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ⚠️ **El cuerpo se lee como JSON.** El sistema viejo hace `$request->json()->all()`, así
     * que sin `Content-Type: application/json` llega vacío y la respuesta es un 422 diciendo
     * que faltan las direcciones. Acá `validationData()` lo deja explícito y de paso el
     * mensaje sirve: un `form-data` también valida.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->all();
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => $validator->errors(),
            'code' => 422,
        ], 422));
    }

    /**
     * Las reglas de un extremo del viaje.
     *
     * @return array<string, mixed>
     */
    protected function reglasDeUbicacion(string $punto): array
    {
        return [
            // ⚠️ El tope sale de la columna y se calcula, no se escribe: `pedidos.direccion`
            // es VARCHAR(255) y lo que se guarda es el texto MÁS «, localidad», así que
            // agregar una localidad más larga a la cobertura no puede dejar pasar un valor
            // que la columna después trunque.
            'direccion_'.$punto => [
                'required_without_all:'.$punto.'_lat,'.$punto.'_lng',
                'nullable',
                'string',
                'max:'.self::largoMaximoDeDireccion(),
            ],

            // Obligatoria sólo cuando el extremo viene como texto: con coordenadas la
            // localidad la resuelve la geocodificación inversa, y pedirla sería pedir un dato
            // que ya tenemos.
            'localidad_'.$punto => [
                'required_with:direccion_'.$punto,
                'nullable',
                'string',
                Rule::in(Ubicacion::localidades()),
            ],

            $punto.'_lat' => ['required_with:'.$punto.'_lng', 'nullable', 'numeric', 'between:-90,90'],
            $punto.'_lng' => ['required_with:'.$punto.'_lat', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /** Cuánto texto de dirección entra, sabiendo que después se le pega «, localidad». */
    public static function largoMaximoDeDireccion(): int
    {
        $localidades = Ubicacion::localidades();
        $masLarga = $localidades === [] ? 0 : max(array_map('mb_strlen', $localidades));

        return 255 - mb_strlen(', ') - $masLarga;
    }

    /**
     * Los mensajes de los dos extremos.
     *
     * ⚠️ **La lista de localidades va en el mensaje**: el `in` de Laravel dice sólo que el
     * valor es inválido, y de un endpoint externo se espera que el error diga cuáles se
     * aceptan.
     *
     * @return array<string, string>
     */
    protected function mensajesDeUbicacion(): array
    {
        $cubiertas = implode(', ', Ubicacion::localidades());

        $mensajes = [];

        foreach (['origen', 'destino'] as $punto) {
            $mensajes['direccion_'.$punto.'.required_without_all'] =
                'Falta la dirección de '.$punto.': mandá direccion_'.$punto.' y localidad_'.$punto
                .', o '.$punto.'_lat y '.$punto.'_lng.';
            $mensajes['localidad_'.$punto.'.required_with'] =
                'Falta la localidad de '.$punto.'. Es obligatoria junto con direccion_'.$punto.'.';
            $mensajes['localidad_'.$punto.'.in'] =
                'La localidad de '.$punto.' está fuera del área de cobertura. Localidades: '.$cubiertas.'.';
        }

        return $mensajes;
    }
}
