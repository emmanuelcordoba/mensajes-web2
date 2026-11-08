<?php

/*
| Los mensajes de la pantalla de ingreso.
|
| ⚠️ `APP_LOCALE=es` no alcanza por sí solo. Laravel no trae los idiomas adentro: sin un
| archivo acá, `trans('auth.failed')` cae al inglés y la pantalla contesta «These
| credentials do not match our records.». Comprobado el 2026-11-07, que es cuando se
| tradujo la pantalla.
|
| `failed` no dice cuál de los dos está mal a propósito: decirlo le confirma a cualquiera
| que un email existe.
*/

return [
    'failed' => 'El email o la contraseña no son correctos.',
    'password' => 'La contraseña no es correcta.',
    'throttle' => 'Demasiados intentos. Probá de nuevo en :seconds segundos.',
];
