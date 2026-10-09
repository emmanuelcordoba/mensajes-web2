<?php

namespace App\Services;

use App\Jobs\EnviarNotificacion;
use App\Models\MotivoBloqueo;

/**
 * Todo lo que el sistema le dice al teléfono de alguien.
 *
 * Es el trait `FirebaseCloudMessaging` del sistema viejo, con los textos que estaban
 * desparramados en los nueve lugares que notifican. Están acá por dos razones.
 *
 * ⚠️ **Cada método acepta un token NULO y no hace nada.** En el sistema viejo cada sitio
 * escribe su propio `if ($cadete->fcm_token)` —nueve veces—, y en uno de ellos el `if` mira
 * una variable y la llamada usa otra. Preguntar en un solo lado es lo que saca ese `if` de
 * los nueve sitios: el que avisa dice a quién le avisa, no si puede.
 *
 * ⚠️ **Y los textos son los del sistema viejo, palabra por palabra.** Son los que la gente
 * viene leyendo en la mano desde años: «Te acaban de asignar un nuevo pedido», no «Tenés un
 * pedido nuevo». Hay un test que los fija.
 *
 * El `type` de cada una viaja hasta el trabajo y **hoy no sale del servidor**, igual que
 * allá; el porqué y el interruptor están en `EnviarNotificacion`.
 */
class NotificacionesAlTelefono
{
    // ----------------------------------------------------------------- al cadete

    /** Le tocó un pedido. */
    public static function nuevoPedido(?string $token): void
    {
        self::mandar($token, 'Nuevo pedido', 'Te acaban de asignar un nuevo pedido', 'nuevo-pedido');
    }

    /** La central le escribió por el chat. */
    public static function nuevoMensaje(?string $token): void
    {
        self::mandar($token, 'Nuevo mensaje', 'Tienes un nuevo mensaje.', 'nuevo-mensaje');
    }

    /**
     * Le bloquearon la cuenta, con el motivo.
     *
     * ⚠️ Con «otro motivo» el cuerpo es **el texto que escribió el operador**, y por eso ese
     * campo está limitado a 120 caracteres en el formulario del panel: entra en una
     * notificación. Si por algún camino llegara vacío, se manda el texto de siempre en vez
     * de una notificación en blanco.
     */
    public static function bloqueado(?string $token, string $motivo, ?string $mensaje = null): void
    {
        $cuerpo = match ($motivo) {
            MotivoBloqueo::FALTA_DE_PAGO => 'Por motivo de falta de pago, pasar pronto por administración.',
            MotivoBloqueo::RECLAMO_PEDIDO => 'Por motivo de reclamo en pedido, pasar pronto por administración.',
            MotivoBloqueo::OTRO_MOTIVO => $mensaje,
            default => null,
        } ?? 'Pasar pronto por administración.';

        self::mandar($token, 'Bloqueado', $cuerpo, 'bloqueado-'.$motivo);
    }

    /** Y le levantaron el bloqueo. */
    public static function desbloqueado(?string $token): void
    {
        self::mandar($token, 'Desbloqueado', 'Te acaban de desbloquear.', 'desbloqueado');
    }

    /** Tiene un pedido en curso hace demasiado. */
    public static function pedidoSinFinalizar(?string $token): void
    {
        self::mandar($token, 'Pedido sin finalizar', 'Finalice el pedido antes de que sea bloqueado.');
    }

    /** Perdió el pedido por no confirmarlo en cinco minutos. */
    public static function pedidoPerdido(?string $token): void
    {
        self::mandar(
            $token,
            'Atención',
            'Pedido perdido por no haberlo confirmado en un lapso de 5 minutos.',
        );
    }

    // ---------------------------------------------------------------- al cliente

    /** Le pusieron precio al pedido que cargó desde la app. */
    public static function pedidoCotizado(?string $token): void
    {
        self::mandar($token, 'Pedido actualizado', 'Su pedido fue cotizado.', 'pedido-actualizado');
    }

    /** El cadete lo aceptó y salió. */
    public static function pedidoEnCamino(?string $token): void
    {
        self::mandar($token, 'Pedido actualizado', 'Su pedido está en camino.', 'pedido-actualizado');
    }

    /** Y lo entregó. */
    public static function pedidoFinalizado(?string $token): void
    {
        self::mandar($token, 'Pedido finalizado', 'Su pedido fue realizado.', 'pedido-finalizado');
    }

    // ----------------------------------------------------------------------------

    /**
     * Encola el envío, si hay a quién.
     *
     * ⚠️ **`afterCommit()`**: varios de los que avisan están dentro de una transacción
     * —asignar un pedido escribe el pedido, el cadete y el cobro del saldo como una sola
     * decisión—. Un aviso de algo que después no se guardó es una mentira que ya está en el
     * teléfono de alguien. El sistema viejo no tiene transacciones en esos caminos, así que
     * allá la pregunta no existía. Fuera de una transacción esto encola igual, en el acto.
     */
    protected static function mandar(?string $token, string $titulo, string $cuerpo, ?string $tipo = null): void
    {
        if ($token === null || trim($token) === '') {
            return;
        }

        EnviarNotificacion::dispatch($token, $titulo, $cuerpo, $tipo)->afterCommit();
    }
}
