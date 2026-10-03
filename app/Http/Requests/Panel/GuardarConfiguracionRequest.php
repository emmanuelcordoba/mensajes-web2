<?php

namespace App\Http\Requests\Panel;

use App\Models\Configuracion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que el panel acepta al cambiar una configuración.
 *
 * Las reglas dependen de la fila, no del formulario: una numérica no acepta lo mismo que
 * «tiempo de demora».
 */
class GuardarConfiguracionRequest extends FormRequest
{
    /**
     * El mismo patrón que el CHECK de la columna: dígitos con un punto decimal opcional.
     *
     * ⚠️ Está acá para que el error llegue como mensaje y no como un 500 de PostgreSQL.
     * `numeric|min:0` de Laravel NO alcanza: deja pasar «1e3», «.5» y «1.», que el CHECK
     * rechaza. Validar algo distinto de lo que la base acepta es cómo se consigue una
     * pantalla que falla sin decir por qué.
     */
    public const PATRON_NUMERICO = '/^[0-9]+(\.[0-9]+)?$/';

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
        $configuracion = $this->route('configuracion');

        if (! $configuracion instanceof Configuracion) {
            return [];
        }

        return [
            'descripcion' => ['nullable', 'string', 'max:255'],
            'valor' => $this->reglasDelValor($configuracion),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function reglasDelValor(Configuracion $configuracion): array
    {
        // El tiempo de demora se puede dejar sin indicar, que es lo que significa vacío.
        if ($configuracion->nombre === 'TIEMPO_DE_DEMORA') {
            return ['nullable', Rule::in(Configuracion::TIEMPOS_DE_DEMORA)];
        }

        if (! $configuracion->esNumerica()) {
            return ['required', 'string', 'max:255'];
        }

        $reglas = ['required', 'string', 'regex:'.self::PATRON_NUMERICO];

        // El mismo rango que exige la pantalla de cobranza por saldo.
        if ($configuracion->nombre === 'COBRANZA_SALDO_PORCENTAJE') {
            $reglas[] = 'numeric';
            $reglas[] = 'between:1,100';
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // ⚠️ El mensaje dice qué se acepta, no que «el formato es inválido». Las
            // numéricas se leen con `floatval()` al cotizar y al cobrar: un texto valía 0
            // y un negativo restaba. Ver PANEL-16.
            'valor.regex' => 'Tiene que ser un número sin signo, con punto decimal si hace falta: 3000 o 1250.50.',
            'valor.in' => 'Elegí uno de los tiempos de la lista, o dejalo sin indicar.',
        ];
    }
}
