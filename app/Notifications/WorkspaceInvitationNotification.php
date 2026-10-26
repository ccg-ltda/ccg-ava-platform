<?php

namespace App\Notifications;

use App\Services\EmailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation e-mail: a one-time link to choose a password and join ONE Workspace. It is queued (like the password recovery) and carries no password and no
 * credential of any kind. If delivery fails for good, the invitation can be re-sent from the administration (that issues a new token and kills the old one).
 */
class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(private readonly string $url, private readonly string $workspaceName, private readonly int $hours) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $templates = app(EmailTemplates::class);
        $title = 'Te invitaron a '.$this->workspaceName;
        $blocks = ['Hola', "Te invitaron a unirte al Workspace «{$this->workspaceName}» en ".config('branding.name').'.', "El enlace caduca en {$this->hours} horas y solo sirve una vez.", 'Si no esperabas esta invitación, ignora este mensaje: no se crea ninguna cuenta hasta que aceptes.'];
        $action = ['label' => 'Aceptar la invitación', 'url' => $this->url];

        $message = (new MailMessage)
            ->subject($title)
            ->greeting('Hola')
            ->line($blocks[1])
            ->action($action['label'], $this->url)
            ->line($blocks[2])
            ->line($blocks[3])
            ->salutation((string) config('branding.name'));

        return $templates->safeUrl($this->url)
            ? $message->view(['emails.html', 'emails.plain'], ['html' => $templates->render($title, $blocks, $action), 'text' => $templates->text($title, $blocks, $action)])
            : $message;
    }
}
