<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El código con el que un cliente verifica su email al registrarse en la app.
 *
 * Es el hermano de `CodigoDeRecuperacion`: mismo formato de seis dígitos y mismo camino
 * —encolado—, distinto asunto y distinto momento. Uno verifica una casilla nueva, el otro
 * recupera una contraseña.
 */
class CodigoDeVerificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $usuario, public string $codigo, public string $nombre) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // El mismo asunto que el sistema viejo: es el que la gente reconoce.
            subject: 'Código de verificación para Mensajes Cadetería',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.codigo-de-verificacion',
            with: ['nombre' => $this->nombre, 'codigo' => $this->codigo],
        );
    }
}
