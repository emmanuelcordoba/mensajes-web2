<?php

return [

    /*
    |--------------------------------------------------------------------------
    | El MongoDB de origen
    |--------------------------------------------------------------------------
    |
    | ⚠️ Una copia restaurada, NUNCA producción. Producción corre MongoDB 3.6.3,
    | a la que el driver actual ya no le habla, y además el servidor está
    | comprometido (SEC-18). El servicio `mongo` de compose.yaml está en el
    | perfil `etl`, así que hay que levantarlo a mano:
    |
    |     sail --profile etl up -d mongo
    |
    */

    'origen' => env('ETL_ORIGEN_URI', 'mongodb://mongo:27017'),

    'base' => env('ETL_ORIGEN_BASE', 'mensajes'),

];
