<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Área de cobertura
    |--------------------------------------------------------------------------
    |
    | Qué direcciones acepta el sistema. Lo usa la API de integración —`usersapi`—, que es
    | la única que recibe direcciones en texto libre de un tercero y tiene que decidir si
    | están dentro del área.
    |
    | ⚠️ **La app de clientes tiene esta misma lista escrita a mano adentro** —`alta.page.ts`
    | en dos lugares—, así que hoy son dos copias: si alguna vez cambian las localidades hay
    | que tocar los dos lados hasta que la app lea la lista del servidor. Acá no se puede
    | arreglar: la app está publicada.
    |
    | El criterio es el de la app: la provincia tiene que ser exactamente «Tucumán» Y la
    | localidad tiene que estar en la lista. No alcanza con una de las dos.
    |
    */

    'provincia' => 'Tucumán',

    'localidades' => [
        'San Miguel de Tucumán',
        'Yerba Buena',
        'Tafí Viejo',
        'Banda del Río Salí',
        'Alderetes',
    ],

];
