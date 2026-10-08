<?php

namespace App\Http\Requests\Api\CadeteApp\V1;

/**
 * Dónde está el cadete, que la app manda sola mientras está activa.
 *
 * ⚠️ El sistema viejo acepta esto por `form-data` o por JSON, con dos caminos distintos y
 * dos validaciones distintas —una de ellas devolvía 400 en vez de 422—. Un `FormRequest` lee
 * los dos formatos igual.
 */
class UbicacionDelCadeteRequest extends PeticionDeLaApp
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
