<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

use App\Models\Cadete;
use App\Models\User;
use App\Services\FotosDePerfil;
use App\Support\FechaDeNacimiento;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Lo que el cadete puede cambiar de su perfil desde la app.
 *
 * Son los ocho campos de `perfil-editar` más la foto. Lo que NO está es tan importante como
 * lo que está: `numero_movil`, `estado`, `orden_cola`, `cobranza_saldo`,
 * `modalidad_cobranza`, `rol_id` y la contraseña no se tocan desde acá. El sistema viejo
 * escribía con `$user->cadete->update($request->only([...]))`, que ya era una lista, pero
 * después hacía `$user->save()` con el modelo entero: la lista es la que corta, no el
 * `fillable`. Ver SEC-4.
 *
 * ⚠️ **El email se cambia sin verificar el nuevo**, como en el sistema viejo. Significa que
 * el cadete puede dejarse afuera de su propia cuenta escribiéndolo mal, porque el login es
 * por email. No se cambia junto con esto —la app publicada manda los nueve campos juntos y
 * no tiene pantalla para confirmar nada—, pero queda anotado.
 */
class GuardarPerfilRequest extends PeticionDeLaApp
{
    /**
     * ⚠️ El email se baja a minúsculas ANTES de validar, igual que en el panel: el modelo lo
     * normaliza en su mutador, y validar «Ana@X.com» tal cual deja que `unique` no encuentre
     * la fila guardada como «ana@x.com» —pasa la validación y revienta contra el índice al
     * escribir—. Ver DATA-7.
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
        $cadete = $this->elCadete();

        return [
            'nombres' => ['required', 'string', 'max:60'],
            'apellidos' => ['required', 'string', 'max:60'],
            'tipo_vehiculo' => ['required', Rule::in(array_keys(Cadete::VEHICULOS))],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],

            // El rango y por qué hace falta, en `FechaDeNacimiento`. Un cadete de los 32 que
            // tienen una fecha imposible guardada (DATA-10) va a tener que corregirla para
            // poder guardar, y el mensaje le dice cuál es el problema.
            'fecha_nacimiento' => ['required', ...FechaDeNacimiento::reglas()],

            // ⚠️ Obligatorio acá y opcional en el panel, y está bien que difieran: la
            // columna es nullable porque hay cadetes viejos sin DNI cargado, pero el
            // formulario de la app lo pide y no tiene por qué dejar borrarlo.
            //
            // Cuenta también a los dados de baja, porque el UNIQUE los cuenta.
            'dni' => [
                'required',
                'regex:/^\d{7,8}$/',
                Rule::unique('cadetes', 'dni')->ignore($cadete?->id),
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Sin `withoutTrashed()`: una cuenta dada de baja sigue ocupando su email,
                // porque `users.email` es UNIQUE a secas. Ver DATA-7.
                Rule::unique('users', 'email')->ignore($this->laCuenta()?->id),
            ],

            // ⚠️ **La app manda este campo SIEMPRE**, incluso cuando el cadete no tocó la
            // foto: `perfil-editar` hace `this.usuario.foto = this.foto`, y `this.foto` se
            // llenó al cargar la pantalla con la URL que emitimos nosotros —o con
            // `assets/images/avatar.png` si no había foto—. Sólo es una foto nueva un
            // `data:image/...;base64,...`; el resto se acepta y se ignora.
            'foto' => [
                'nullable',
                'string',
                function (string $atributo, mixed $valor, Closure $falla): void {
                    $motivo = FotosDePerfil::porQueNoSirve($valor);

                    if ($motivo !== null) {
                        $falla($motivo);
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...FechaDeNacimiento::mensajes(),
            'dni.regex' => 'El DNI tiene que tener 7 u 8 dígitos, sin puntos ni espacios.',
            'dni.unique' => 'Ese DNI ya lo tiene otro cadete.',
            'email.unique' => 'Ese email ya está en uso.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo_vehiculo' => 'vehículo',
        ];
    }

    /** La cuenta que está autenticada. */
    public function laCuenta(): ?User
    {
        $usuario = $this->user();

        return $usuario instanceof User ? $usuario : null;
    }

    /** Y su cadete, que es la fila que se edita. */
    public function elCadete(): ?Cadete
    {
        return $this->laCuenta()?->cadete;
    }
}
