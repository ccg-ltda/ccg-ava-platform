<?php

namespace App\Mail;

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
        return new Envelope(subject: 'Tu código de verificación de Ava Platform');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.signup-code');
    }
}
