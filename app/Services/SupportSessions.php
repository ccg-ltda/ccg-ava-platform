<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * The life of a support request: a session opens with the person's first message and ENDS, for good, when the person or CCG's agent finishes it or when nobody writes for
 * `support.inactivity_minutes` (4). A finished session keeps everything (messages, files, who closed it, when and why) for CCG, and is never opened again: its `contact_id` is archived
 * (`c{workspace}u{user}x{conversation}`), so the person's next message creates a NEW conversation with its own id and nothing of the old one is shown to them.
 *
 * Inactivity is measured on the server, from the last message of the person or of the team (`last_message_at`); polling, rendering and the system message of the closing never move it.
 * Closing runs under the row lock the messages take, and re-reads the activity under it, so a message that arrives at the limit either keeps the session alive or lands in the new one:
 * never in a closed one. Closing twice (two processes, two tabs) does it once.
 */
class SupportSessions
{
    public const REASONS = ['client' => 'Finalizada por el cliente', 'agent' => 'Finalizada por el agente', 'inactivity' => 'Cerrada por inactividad'];

    public function __construct(private readonly SupportDesk $desk, private readonly ConversationControl $control) {}

    public static function minutes(): int
    {
        return max(1, (int) config('support.inactivity_minutes'));
    }

    /** The key a finished session keeps, which frees the person's own key for the next one. */
    public static function archivedKey(Conversation $conversation): string
    {
        return preg_replace('/x\d+$/', '', (string) $conversation->contact_id).'x'.$conversation->id;
    }

    /** The text the person sees about how their session ended. */
    public static function notice(?string $reason): string
    {
        return match ($reason) {
            'inactivity' => 'La conversación se cerró por inactividad (4 minutos sin mensajes). Si todavía necesitas ayuda, inicia una nueva.',
            'agent' => 'El equipo de soporte finalizó la conversación. Si todavía necesitas ayuda, inicia una nueva.',
            default => 'Finalizaste la conversación. Puedes iniciar una nueva cuando quieras.',
        };
    }

    /** Whether an open session has gone quiet for longer than the limit (server clock). */
    public function idle(Conversation $conversation): bool
    {
        return $conversation->closed_at === null && $conversation->last_message_at !== null && $conversation->last_message_at->lte(now()->subMinutes(self::minutes()));
    }

    /**
     * Finishes a session. Returns whether THIS call closed it (false when it was already closed, or, for inactivity, when activity arrived meanwhile).
     */
    public function close(Conversation $conversation, string $reason, ?User $by = null): bool
    {
        return $this->control->closeSupportSession($conversation, $reason, $by, function (Conversation $locked) use ($reason) {
            // The activity is re-read UNDER the lock: a message that got there first keeps the session alive.
            return $reason !== 'inactivity' || $this->idle($locked);
        }, function (Conversation $locked) use ($reason) {
            // A system line, never a message of the person or the team: it is not delivered anywhere, does not wake an assistant, does not count as news and does not move the clock.
            $locked->messages()->create([
                'workspace_id' => $locked->workspace_id, 'direction' => 'out', 'sender' => 'system', 'type' => 'text', 'body' => self::REASONS[$reason],
                'status' => null, 'simulated' => false, 'sent_at' => now(),
            ]);
        });
    }

    /** Closes this session if it has gone idle; returns whether it did. */
    public function closeIfIdle(Conversation $conversation): bool
    {
        return $this->idle($conversation) && $this->close($conversation, 'inactivity');
    }

    /**
     * Every idle support session (the scheduler runs this each minute; reading and writing also close the one they touch, so a stopped scheduler never keeps a session alive).
     */
    public function closeIdle(): int
    {
        $closed = 0;

        Conversation::with('chatbot.workspace')->where('channel', 'web')->whereNull('closed_at')->where('handling', '!=', Conversation::RESOLVED)
            ->where('last_message_at', '<=', now()->subMinutes(self::minutes()))->where('contact_id', 'like', 'c%u%')
            ->orderBy('id')->limit(200)->get()
            ->each(function (Conversation $conversation) use (&$closed) {
                if ($this->desk->isRequest($conversation) && $this->close($conversation, 'inactivity')) {
                    $closed++;
                }
            });

        return $closed;
    }

    /** Outgoing messages that were never confirmed: not «Enviando» forever (see the command). */
    public function expirePending(int $minutes = 2): int
    {
        return Message::where('direction', 'out')->where('status', Message::PENDING)->where('sent_at', '<=', now()->subMinutes($minutes))
            ->update(['status' => Message::UNCONFIRMED, 'failure_reason' => 'No se recibió confirmación del envío. Pudo no haberse enviado: verifica con el contacto antes de reenviarlo.']);
    }
}
