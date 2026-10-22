<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\EmailTemplates;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;

/**
 * The password-recovery email. It is queued so the request answers in the same time whether or not the account exists
 * (a synchronous send would make an existing account measurably slower), and it never carries a Workspace or a role.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /** A failed delivery is retried a little later; the token only lives `auth.passwords.*.expire` minutes anyway. */
    public int $tries = 3;

    public array $backoff = [30, 120];

    public ?int $userId = null;

    public function forUser(int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * The token travels in the queued payload, so when delivery fails for good (and that payload stays in `failed_jobs`) the token is
     * deleted: what is left there opens nothing. The person just asks for a new link.
     */
    public function failed(\Throwable $exception): void
    {
        $email = $this->userId ? User::whereKey($this->userId)->value('email') : null;

        if ($email) {
            DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))->where('email', $email)->delete();
        }
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        $url = $this->resetUrl($notifiable);
        $templates = app(EmailTemplates::class);
        $blocks = ['Hola', 'Recibimos una solicitud para restablecer la contraseña de tu cuenta.', "El enlace caduca en {$minutes} minutos y solo sirve una vez.", 'Si no fuiste tú, ignora este mensaje: tu contraseña no cambia.'];
        $action = ['label' => 'Elegir una contraseña nueva', 'url' => $url];

        $message = (new MailMessage)
            ->subject('Restablece tu contraseña')
            ->greeting('Hola')
            ->line($blocks[1])
            ->action($action['label'], $url)
            ->line($blocks[2])
            ->line($blocks[3])
            ->salutation((string) config('branding.name'));

        // The message keeps its lines and its action (what the standard layout would print); the HTML goes through the global template only when the link is a
        // safe http(s) URL (it always is for this app). Otherwise the standard layout is sent, as before.
        return $templates->safeUrl($url)
            ? $message->view(['emails.html', 'emails.plain'], ['html' => $templates->render('Restablece tu contraseña', $blocks, $action), 'text' => $templates->text('Restablece tu contraseña', $blocks, $action)])
            : $message;
    }
}
