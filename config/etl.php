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
    | Dónde viven las imágenes, y dónde las escribe la carga
    |--------------------------------------------------------------------------
    |
    | Las tres tablas de imágenes guardan la RUTA de un archivo (PERF-2, ver «Las
    | imágenes son archivos» en ESQUEMA.sql). Los dos discos tienen que ser
    | PRIVADOS: dos de los cuatro documentos de cada postulación son el DNI de la
    | persona, de frente y de dorso.
    |
    | **Decidido el 2026-09-26: van a un bucket.** No por volumen ni por costo
    | —2,2 GB es poco y sale centavos— sino porque deja al servidor sin estado, y
    | el motivo por el que hay servidor nuevo es que el viejo está comprometido
    | (SEC-18). Con los archivos adentro no se puede reemplazar una máquina sin
    | pensarlo; con un bucket, sí.
    |
    | Son DOS discos a propósito:
    |
    | - `disco_carga` es donde ESCRIBE la carga. Local, porque tiene que ser
    |   rápido: son 4.509 archivos y 2,2 GB.
    | - `disco` es donde viven al final y de donde los lee la aplicación. El
    |   bucket.
    |
    | Si fueran el mismo, la carga escribiría directo al bucket y los 2,2 GB
    | caerían DENTRO de la ventana del corte, que es de unas pocas horas. Con los
    | dos separados se suben antes con `etl:archivos` y en la ventana queda sólo
    | lo que cambió: medido, 68 archivos y 31 MB con una semana de anticipación.
    |
    | Los dos en `local` es la configuración del ensayo, y entonces `etl:archivos`
    | no tiene nada que hacer y lo dice.
    |
    */

    'disco' => env('ETL_DISCO', 'local'),

    'disco_carga' => env('ETL_DISCO_CARGA', 'local'),

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
