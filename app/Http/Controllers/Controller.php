<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Trae `AuthorizesRequests` para que todo controlador pueda llamar a
 * `$this->authorize()`. Laravel ya no lo incluye por omisión, y sin el trait la
 * llamada no existe: una autorización que no se ejecuta no falla, **pasa**.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
