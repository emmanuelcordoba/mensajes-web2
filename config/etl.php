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

    /*
    |--------------------------------------------------------------------------
    | Dónde escribe las imágenes
    |--------------------------------------------------------------------------
    |
    | Las tres tablas de imágenes guardan la RUTA de un archivo, relativa a este
    | disco (PERF-2, ver «Las imágenes son archivos» en ESQUEMA.sql). El disco
    | tiene que ser PRIVADO: dos de los cuatro documentos de cada postulación son
    | el DNI de la persona, de frente y de dorso.
    |
    | `local` es storage/app/private, que alcanza para el ensayo.
    |
    | ⚠️ SIN RESOLVER para producción: disco del servidor con el backup extendido,
    | o un bucket. A partir de esta decisión la base sola NO es un respaldo
    | completo, y hay que resolverlo ANTES del corte. Ver ETL-1 y DATA-9.
    |
    */

    'disco' => env('ETL_DISCO', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Los archivos que ya existen en el servidor viejo
    |--------------------------------------------------------------------------
    |
    | El sistema viejo ya convierte a archivo al contratar una postulación, así
    | que el origen tiene las dos formas mezcladas: 1.749 rutas y 2.900 base64.
    | Las rutas hay que COPIARLAS, y esos archivos no están en ningún backup de
    | la base (DATA-9): salen de un `tar` aparte del servidor.
    |
    | Acá va el directorio donde está desempaquetado ese tar, con `postulaciones/`
    | adentro. El ETL frena si falta un archivo, en vez de cargar una fila que
    | apunta a la nada.
    |
    | ⚠️ Desempaquetar SÓLO las rutas que la base reclama. El tar del 2026-09-24
    | trae 4.071 archivos y sólo 1.749 tienen dueño: los otros 2.322 son DNI de
    | 774 personas cuya postulación se borró, y no se migran. Ver DATA-9.
    |
    */

    'archivos' => env('ETL_ARCHIVOS', storage_path('app/etl-origen')),

];
