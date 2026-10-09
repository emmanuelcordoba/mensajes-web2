<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El código con el que alguien recupera su contraseña desde una de las dos apps.
 *
 * ⚠️ **Va encolado**, y el sistema viejo lo manda en el acto con `Mail::send()`. Pedir un
 * código tarda lo que tarda el servidor de correo, y mientras tanto la app muestra el
 * spinner: con el envío diferido la respuesta sale sola. Hace falta un `queue:work`, que ya
 * es parte del despliegue por el reloj de los cinco minutos y por las notificaciones.
 *
 * ⚠️ **El código no es un enlace.** Es un número de seis dígitos que la persona copia a la
 * app. El panel tiene otra recuperación, la de la web, basada en tokens con vencimiento; esa
 * es más segura y no se mezcla con ésta. Ver `RecuperacionDeContrasena::ROLES`.
 */
class CodigoDeRecuperacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $usuario, public string $codigo) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // El mismo asunto que el sistema viejo: es el que la gente reconoce en la bandeja.
            subject: 'Código de restauración de contraseña para Mensajes Cadetería',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.codigo-de-recuperacion',
            with: ['nombre' => $this->usuario->name, 'codigo' => $this->codigo],
        );
    }
}
