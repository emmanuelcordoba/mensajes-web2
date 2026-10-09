<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El permiso para mandarle una notificación a un teléfono.
 *
 * Firebase Cloud Messaging pide un token de OAuth2 firmado con la cuenta de servicio del
 * proyecto. Es lo mismo que hace el sistema viejo en `EnviarNotificacion::getAccessToken()`,
 * con tres diferencias.
 *
 * ⚠️ **El token se guarda en caché.** Allá se pide uno nuevo en CADA notificación: una ida
 * y vuelta a Google por cada aviso, firmando un JWT cada vez, para un token que dura una
 * hora. Al asignar pedidos en una tanda eso duplica las llamadas de red. Acá se pide uno y
 * se reusa 50 minutos. Es la única diferencia interna con el sistema viejo y no cambia nada
 * de lo que se manda ni de lo que el teléfono recibe.
 *
 * ⚠️ **Las credenciales no están en el repositorio.** Allá la clave privada vive en
 * `storage/nuggo-mensajescadeteria-firebase.json`; acá sale de `FIREBASE_CREDENTIALS`, que
 * puede ser una ruta o el JSON entero. Ver `config/services.php`.
 *
 * ⚠️ **Si no está configurado, no se manda y se dice.** Sin esto, una instalación sin las
 * credenciales se traga todas las notificaciones en silencio y nadie se entera hasta que un
 * cadete pregunta por qué no le avisan. El aviso al log sale una vez por hora, no una por
 * notificación, para no llenar el archivo.
 */
class AccesoAFirebase
{
    /** Lo único que necesitamos de la cuenta de servicio. */
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Google los emite por una hora; se renueva con diez minutos de sobra. */
    public const MINUTOS_DE_CACHE = 50;

    public const CLAVE_DEL_TOKEN = 'firebase:access-token';

    /** Cada cuánto se repite el aviso de que falta configurar esto. */
    public const CLAVE_DEL_AVISO = 'firebase:sin-configurar-avisado';

    /** Si hay con qué mandar algo. */
    public static function estaConfigurado(): bool
    {
        return self::proyecto() !== null && self::credenciales() !== null;
    }

    /** El id del proyecto de Firebase, que va en la URL. */
    public static function proyecto(): ?string
    {
        $proyecto = config('services.firebase.proyecto');

        return is_string($proyecto) && $proyecto !== '' ? $proyecto : null;
    }

    /** La URL a la que se le manda un mensaje, o null si no hay proyecto. */
    public static function url(): ?string
    {
        $proyecto = self::proyecto();

        return $proyecto === null
            ? null
            : 'https://fcm.googleapis.com/v1/projects/'.$proyecto.'/messages:send';
    }

    /**
     * El token con el que se autoriza el envío, o null si no se pudo conseguir.
     *
     * Null no se guarda en caché: un problema de red de un segundo no puede dejar las
     * notificaciones apagadas durante 50 minutos.
     */
    public static function token(): ?string
    {
        $guardado = Cache::get(self::CLAVE_DEL_TOKEN);

        if (is_string($guardado) && $guardado !== '') {
            return $guardado;
        }

        $credenciales = self::credenciales();

        if ($credenciales === null) {
            self::avisarQueFalta();

            return null;
        }

        try {
            $respuesta = (new ServiceAccountCredentials(self::SCOPE, $credenciales))->fetchAuthToken();
        } catch (Throwable $e) {
            Log::warning('No se pudo pedir el token de Firebase', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $respuesta['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            Log::warning('Firebase contestó sin access_token');

            return null;
        }

        Cache::put(self::CLAVE_DEL_TOKEN, $token, now()->addMinutes(self::MINUTOS_DE_CACHE));

        return $token;
    }

    /**
     * La cuenta de servicio, ya decodificada.
     *
     * ⚠️ Nada de lo que sale de acá se escribe en un log ni en una excepción: adentro hay
     * una clave privada que firma en nombre del proyecto.
     *
     * @return array<string, mixed>|null
     */
    protected static function credenciales(): ?array
    {
        $valor = config('services.firebase.credenciales');

        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        $valor = trim($valor);

        // El JSON entero, que es la forma que sirve donde no hay disco propio.
        if (str_starts_with($valor, '{')) {
            return self::decodificar($valor, 'el JSON de FIREBASE_CREDENTIALS');
        }

        // O una ruta: absoluta, o relativa a la base del proyecto.
        $ruta = str_starts_with($valor, '/') ? $valor : base_path($valor);

        if (! is_file($ruta) || ! is_readable($ruta)) {
            Log::warning('El archivo de credenciales de Firebase no se puede leer', ['ruta' => $ruta]);

            return null;
        }

        return self::decodificar((string) file_get_contents($ruta), 'el archivo de credenciales');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function decodificar(string $json, string $queEs): ?array
    {
        $datos = json_decode($json, true);

        if (! is_array($datos) || ! isset($datos['client_email'], $datos['private_key'])) {
            // ⚠️ Sin el contenido: tiene la clave privada adentro.
            Log::warning('No se pudo leer '.$queEs.' de Firebase.');

            return null;
        }

        /** @var array<string, mixed> $datos */
        return $datos;
    }

    /** Una vez por hora y no una por notificación. */
    protected static function avisarQueFalta(): void
    {
        if (Cache::get(self::CLAVE_DEL_AVISO) !== null) {
            return;
        }

        Cache::put(self::CLAVE_DEL_AVISO, true, now()->addHour());

        Log::warning(
            'Firebase no está configurado, así que no se mandan notificaciones a los teléfonos.'
            .' Faltan FIREBASE_PROJECT_ID y/o FIREBASE_CREDENTIALS.'
        );
    }
}
