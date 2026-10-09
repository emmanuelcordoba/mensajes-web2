<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

/**
 * Desde dónde seguir listando los movimientos del saldo.
 *
 * ⚠️ **El sistema viejo no lo valida**: hace `$request->offset ?? 0` y se lo pasa a
 * `offset()`. En MongoDB eso se convertía a número sin chistar; en PostgreSQL un
 * `?offset=cualquiera` —o un negativo— es un error de SQL, o sea un 500. La app siempre manda
 * `this.movimientos.length`, así que esto no lo rompe la app: lo rompe cualquiera con un
 * token.
 */
class MovimientosDeSaldoRequest extends PeticionDeLaApp
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offset' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
