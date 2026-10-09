<?php

namespace App\Jobs;

use App\Services\AccesoAFirebase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le manda una notificación a un teléfono, por Firebase Cloud Messaging.
 *
 * Va encolado, como en el sistema viejo: la llamada a Google tarda y el que la dispara está
 * contestándole a alguien —el operador que asigna un pedido, el cadete que acepta—. **Hace
 * falta un `queue:work` corriendo**; sin él la notificación se queda en la tabla y nunca
 * llega. Es parte del despliegue, igual que el reloj de los cinco minutos.
 *
 * ⚠️ **`data` no se manda, y eso es lo que hace hoy el sistema viejo.** Allá cada sitio que
 * notifica escribe un `['type' => 'nuevo-pedido']`, el trait lo pasa, el trabajo lo guarda
 * en una propiedad… y `handle()` arma el cuerpo con `token` y `notification` solamente. El
 * `type` nunca sale del servidor.
 *
 * Tiene consecuencia: la app del cadete lee `notification.data.type` para decidir qué hacer
 * —con `nuevo-pedido` abre la pestaña de pedidos, con `nuevo-mensaje` enciende el globito
 * rojo del chat, y con cualquier otra cosa muestra un alerta genérico—. Como `data` no
 * llega, hoy TODAS caen en la rama genérica. Se replica tal cual, porque mandarlo cambiaría
 * lo que el cadete ve en la mano, y el tipo viaja hasta acá para que prenderlo sea una
 * variable de entorno: `FIREBASE_ENVIAR_TIPO`. La app de clientes no lee `data` en ninguna
 * parte, así que a ésa no le cambia nada.
 *
 * ⚠️ **El certificado se verifica**, que es lo que el cliente HTTP hace por omisión. En el
 * sistema viejo estaba apagado a mano y se arregló: cualquiera en el camino podía hacerse
 * pasar por Google y quedarse con el token de la cuenta de servicio, que viaja en la
 * cabecera. Ver SEC-16.
 *
 * ⚠️ **El token del dispositivo no se escribe en ningún log**: identifica al teléfono de una
 * persona. El sistema viejo ya tenía ese cuidado y se mantiene.
 */
class EnviarNotificacion implements ShouldQueue
{
    use Queueable;

    /** Cuánto se espera a Google antes de dar el envío por perdido. */
    public const SEGUNDOS_DE_ESPERA = 10;

    public function __construct(
        public string $token,
        public string $titulo,
        public string $cuerpo,
        public ?string $tipo = null,
    ) {}

    public function handle(): void
    {
        $url = AccesoAFirebase::url();
        $acceso = AccesoAFirebase::token();

        if ($url === null || $acceso === null) {
            // `AccesoAFirebase` ya dejó dicho en el log qué falta.
            return;
        }

        try {
            $respuesta = Http::withToken($acceso)
                ->timeout(self::SEGUNDOS_DE_ESPERA)
                ->post($url, ['message' => $this->mensaje()]);
        } catch (Throwable $e) {
            // ⚠️ Sin el token del dispositivo.
            Log::warning('No se pudo enviar la notificación', ['error' => $e->getMessage()]);

            return;
        }

        if ($respuesta->failed()) {
            // ⚠️ El sistema viejo sólo mira si curl falló, así que un token de dispositivo
            // vencido —que Firebase contesta con un 404 UNREGISTERED— se pierde sin dejar
            // rastro. Esto no cambia el comportamiento: sólo deja de ser invisible.
            Log::warning('Firebase rechazó la notificación', [
                'estado' => $respuesta->status(),
                'error' => $respuesta->json('error.status'),
            ]);
        }
    }

    /**
     * El cuerpo que espera FCM v1.
     *
     * @return array<string, mixed>
     */
    protected function mensaje(): array
    {
        $mensaje = [
            'token' => $this->token,
            'notification' => [
                'title' => $this->titulo,
                'body' => $this->cuerpo,
            ],
        ];

        if ($this->tipo !== null && self::seEnviaElTipo()) {
            $mensaje['data'] = ['type' => $this->tipo];
        }

        return $mensaje;
    }

    /** El interruptor, que vive en `config/services.php` con el porqué entero. */
    public static function seEnviaElTipo(): bool
    {
        return (bool) config('services.firebase.enviar_el_tipo', false);
    }
}
