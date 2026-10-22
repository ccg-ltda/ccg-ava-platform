<?php

namespace App\Mail;

use App\Models\CatalogReminder;
use App\Services\EmailTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The email of a reminder of the Catálogo, in the platform's global template. The subject and the text are what the creator wrote (escaped, never HTML). Sent
 * synchronously by the worker that owns the reminder; its Message-ID is stable per reminder, so if a retry ever repeats a message the receiving server can
 * recognize it as the same one (a best effort: SMTP itself has no idempotency).
 */
class CatalogReminderMail extends Mailable
{
    public function __construct(public readonly CatalogReminder $reminder) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reminder->subject);
    }

    public function headers(): Headers
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'ava.local';

        return new Headers(messageId: "catalog-reminder-{$this->reminder->id}@{$host}", text: ['X-Ava-Reminder' => (string) $this->reminder->id]);
    }

    public function content(): Content
    {
        $templates = app(EmailTemplates::class);
        $blocks = array_values(array_filter(preg_split('/\n{2,}/', str_replace("\r\n", "\n", trim($this->reminder->body))) ?: [], fn ($part) => trim($part) !== ''));
        $footer = 'Recordatorio programado por ti en el Catálogo de '.(string) $this->reminder->workspace?->name.'.';

        return new Content(
            view: 'emails.html',
            text: 'emails.plain',
            with: ['html' => $templates->render($this->reminder->subject, $blocks, null, $footer), 'text' => $templates->text($this->reminder->subject, $blocks)."\n".$footer."\n"],
        );
    }
}
