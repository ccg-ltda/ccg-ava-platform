<?php

namespace App\Console\Commands;

use App\Support\MailAvailability;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Sends ONE test message to an explicit address through the configured mailer, to check the real SMTP setup. It touches no business data (no user,
 * Organization, Workspace, subscription or sign-up request), has no HTTP route, and prints neither credentials nor verification codes. "Accepted by the
 * SMTP server" is NOT "arrived in the inbox": only the person who owns the address can confirm that.
 */
class MailTestCommand extends Command
{
    protected $signature = 'mail:test {recipient : address that receives the test message}';

    protected $description = 'Send one test email through the configured mailer (explicit recipient, no business data)';

    public function handle(): int
    {
        $recipient = trim((string) $this->argument('recipient'));

        if (Validator::make(['email' => $recipient], ['email' => ['required', 'email:rfc']])->fails()) {
            $this->components->error('El destinatario no es una dirección de correo válida.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        if (! MailAvailability::deliversRealMail()) {
            $this->components->error("El mailer «{$mailer}» no entrega correo real (log, array, null o remitente de ejemplo): no se envió nada. Revisa MAIL_* en .env (docs: RECOMENDACIONES.md §30).");

            return self::FAILURE;
        }

        $this->line('Mailer: '.$mailer.' · servidor: '.config("mail.mailers.{$mailer}.host").':'.config("mail.mailers.{$mailer}.port").' · cifrado: '.(config("mail.mailers.{$mailer}.scheme") ?: 'smtp (STARTTLS si el servidor lo ofrece)'));

        try {
            Mail::raw(
                'Mensaje de prueba de '.config('branding.name').".\n\nSi lo lees, la configuración de correo saliente funciona. No contiene datos de clientes ni códigos.\nEnviado: ".now()->toDateTimeString().' ('.config('app.timezone').')',
                fn (Message $message) => $message->to($recipient)->subject('Prueba de correo de '.config('branding.name')),
            );
        } catch (Throwable $e) {
            $this->components->error('No se pudo entregar el mensaje al servidor SMTP: '.$this->diagnose($e));

            return self::FAILURE;
        }

        $this->components->info("El servidor SMTP aceptó el mensaje para {$recipient}. Eso NO confirma que llegó a la bandeja: revisa tu bandeja de entrada y la carpeta de spam.");

        return self::SUCCESS;
    }

    /** A cause a person can act on, never the raw server text that could carry an address or a credential. */
    private function diagnose(Throwable $e): string
    {
        $text = mb_strtolower($e->getMessage());

        return match (true) {
            str_contains($text, 'authenticate') || str_contains($text, '535') || str_contains($text, 'username and password') => 'autenticación rechazada (usuario o contraseña de aplicación incorrectos, o el administrador no permite contraseñas de aplicación).',
            str_contains($text, 'could not be established') || str_contains($text, 'connection refused') || str_contains($text, 'timed out') || str_contains($text, 'getaddrinfo') => 'no hay conexión con el servidor (host, puerto, firewall o red).',
            str_contains($text, 'starttls') || str_contains($text, 'tls') || str_contains($text, 'ssl') => 'fallo de cifrado TLS (revisa MAIL_PORT y MAIL_SCHEME).',
            str_contains($text, 'sender') || str_contains($text, '553') || str_contains($text, '550') => 'el servidor rechazó el remitente o el destinatario (revisa MAIL_FROM_ADDRESS).',
            default => 'error de transporte ('.class_basename($e).').',
        };
    }
}
