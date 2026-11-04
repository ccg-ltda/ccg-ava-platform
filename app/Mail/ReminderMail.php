<?php

namespace App\Mail;

use App\Models\Reminder;
use App\Services\EmailTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The e-mail of a commercial reminder (module Recordatorios), in the platform's global template. The subject and the text are what the team wrote (escaped, never HTML), the
 * text already rendered for this customer. Its Message-ID is stable per reminder, so a receiving server can recognise a repeat; the footer says who sends it and how to stop.
 */
class ReminderMail extends Mailable
{
    public function __construct(public readonly Reminder $reminder, public readonly string $text, public readonly ?string $subjectText = null) {}

    /** The subject the customer reads: the team's text with the customer's data already filled in (it used to go out with the raw {{markers}}). */
    private function subjectLine(): string
    {
        return $this->subjectText ?? (string) $this->reminder->subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function headers(): Headers
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'ava.local';

        return new Headers(messageId: "reminder-{$this->reminder->id}@{$host}", text: ['X-Ava-Reminder' => (string) $this->reminder->id]);
    }

    public function content(): Content
    {
        $templates = app(EmailTemplates::class);
        $blocks = array_values(array_filter(preg_split('/\n{2,}/', str_replace("\r\n", "\n", trim($this->text))) ?: [], fn ($part) => trim($part) !== ''));
        $footer = 'Recibes este mensaje de '.($this->reminder->workspace?->name ?? 'tu proveedor').'. Si no quieres recibir más mensajes, responde a este correo pidiendo la baja.';

        return new Content(
            view: 'emails.html',
            text: 'emails.plain',
            with: ['html' => $templates->render($this->subjectLine(), $blocks, null, $footer), 'text' => $templates->text($this->subjectLine(), $blocks)."\n".$footer."\n"],
        );
    }
}
