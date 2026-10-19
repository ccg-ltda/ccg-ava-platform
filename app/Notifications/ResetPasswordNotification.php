<?php

namespace App\Notifications;

use App\Models\User;
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

        return (new MailMessage)
            ->subject('Restablece tu contraseña')
            ->greeting('Hola')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Elegir una contraseña nueva', $this->resetUrl($notifiable))
            ->line("El enlace caduca en {$minutes} minutos y solo sirve una vez.")
            ->line('Si no fuiste tú, ignora este mensaje: tu contraseña no cambia.')
            ->salutation('CCG Avachat');
    }
}
