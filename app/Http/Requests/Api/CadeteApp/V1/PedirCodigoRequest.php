<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

/**
 * El email de quien dice haberse olvidado la contraseña.
 *
 * ⚠️ El sistema viejo valida `required` a secas, sin formato: `?email=hola` entra y busca.
 * Acá se exige que parezca un email, que es lo que la app ya exige en su formulario
 * —`Validators.email`—, así que no cambia nada para quien usa la app.
 *
 * ⚠️ **No se normaliza a minúsculas.** La búsqueda es la que ignora mayúsculas, porque hay
 * pares de cuentas que sólo difieren en eso (DATA-7) y a las dos hay que escribirles el
 * mismo código. Ver `RecuperacionDeContrasena`.
 */
class PedirCodigoRequest extends PeticionDeLaApp
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
