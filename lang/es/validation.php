<?php

/*
| Las reglas que la pantalla de ingreso puede mostrar, y nada más.
|
| ⚠️ Está a propósito incompleto. El resto del panel sigue contestando en inglés —«The
| name field is required.»— porque traducir las cien reglas es una decisión aparte de
| traducir una pantalla, y ésta se tradujo el día del corte. Una clave que no esté acá cae
| al idioma de respaldo sola, así que agregar las que falten es sumar líneas y nada más.
*/

return [
    'required' => 'Hace falta completar :attribute.',
    'email' => 'Eso no parece un email.',
    'string' => ':Attribute tiene que ser texto.',

    'attributes' => [
        'email' => 'el email',
        'password' => 'la contraseña',
    ],
];
