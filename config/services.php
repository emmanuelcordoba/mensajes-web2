<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | Con esto se le manda una notificación al teléfono de un cadete o de un cliente. El
    | sistema viejo tiene el proyecto escrito dentro de la URL —`nuggo-mensajescadeteria`— y
    | lee la cuenta de servicio de `storage/nuggo-mensajescadeteria-firebase.json`, o sea un
    | archivo con una clave privada adentro del repositorio desplegado.
    |
    | ⚠️ `credenciales` acepta las dos formas: **la ruta a un archivo JSON** o **el JSON
    | entero**. Lo segundo es lo que hace falta donde no hay disco propio al que subir un
    | archivo —Laravel Cloud—, y lo primero es más cómodo en una máquina. Sin esto
    | configurado no se manda nada y queda anotado en el log: ver `AccesoAFirebase`.
    |
    */

    'firebase' => [
        'proyecto' => env('FIREBASE_PROJECT_ID'),
        'credenciales' => env('FIREBASE_CREDENTIALS'),

        /*
        | ⚠️ Si el `type` de cada notificación viaja en `data`. **En false porque es lo que
        | hace el sistema viejo**: allá cada sitio escribe su `['type' => ...]`, el trabajo
        | lo guarda y nunca lo manda.
        |
        | Prenderlo CAMBIA lo que el cadete ve en la mano, y para mejor: la app lee
        | `notification.data.type` y con `nuevo-pedido` abre la pestaña de pedidos, con
        | `nuevo-mensaje` enciende el globito del chat y con cualquier otra cosa muestra un
        | alerta. Como hoy `data` no llega, todas caen en el alerta genérico. La app de
        | clientes no lo lee, así que a ésa no le cambia nada. Ver `EnviarNotificacion`.
        */
        'enviar_el_tipo' => env('FIREBASE_ENVIAR_TIPO', false),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    /*
    | La clave de la API de Google Maps, que usa el mapa de cadetes del panel.
    |
    | ⚠️ Esta clave VIAJA AL NAVEGADOR y tiene que viajar: la API de Maps se carga desde el
    | cliente y la clave va en la URL del script. No es un secreto que se pueda guardar
    | —cualquiera que abra la pantalla la ve—, así que lo que la protege es la restricción
    | por dominio en la consola de Google, no esconderla. En el sistema viejo vivía en
    | `config/app.php`, que no es donde van las de terceros.
    */
    'google' => [
        'maps_key' => env('GOOGLE_MAPS_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
