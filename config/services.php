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
