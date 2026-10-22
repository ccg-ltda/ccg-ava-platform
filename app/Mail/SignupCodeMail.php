<?php

namespace App\Mail;

use App\Services\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The one-time code that proves an email can receive mail during the public sign-up. Sent by the server; never logged by the application itself. */
class SignupCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code, public readonly int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código de verificación de '.config('branding.name'));
    }

    public function content(): Content
    {
        // The code, its lifetime and the plain-text twin are exactly what they were; only the HTML presentation comes from the global template.
        $brand = (string) config('branding.name');
        $blocks = ["Tu código de verificación de {$brand} es:", ['code' => $this->code], "Caduca en {$this->minutes} minutos y solo sirve una vez. Escríbelo en la pantalla de registro.", 'Si no pediste este código, ignora este mensaje: no se creó ninguna cuenta.'];

        return new Content(view: 'emails.html', text: 'emails.signup-code', with: ['html' => app(EmailTemplates::class)->render('Tu código de verificación', $blocks), 'text' => '']);
    }
}
