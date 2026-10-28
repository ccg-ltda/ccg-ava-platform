<?php

namespace App\Services;

use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The automatic close of conversations by inactivity, configurable per Workspace (Configuraciones > Conversaciones > Cierre automático).
 *
 * Settings: `conversation_autoclose_enabled` / `conversation_autoclose_minutes` in the Workspace's settings. NULL means «never configured» and keeps the behavior from before
 * this setting existed: support sessions close after `support.inactivity_minutes` (4) and ordinary conversations never close by themselves. Once an administrator saves the
 * setting it governs BOTH: off = nothing closes automatically; on = anything quiet for the minutes closes.
 *
 * Activity is conversation activity ONLY: the clock is `last_message_at`, which moves with a message of the contact, a reply of the AI and a message of an agent (even «sigo
 * trabajando en tu solicitud»). Notifications, state changes, audit, reads, sounds, polling and the system line of a close never touch it, and no maintenance job may refresh it.
 *
 * A conversation whose AI execution is IN FLIGHT (pending/running, younger than the time an execution may legitimately take: timeout × tries + backoff) is not abandoned and is not
 * closed; a stuck execution older than that does not keep it open. Closing is under the conversation's row lock with the activity and the execution re-read there, so a
 * message that arrives at the limit wins, and doing it twice does it once. Nothing is deleted and nothing is sent to the contact.
 */
class ConversationAutoClose
{
    public const MIN = 1;

    public const MAX = 1440;

    public function __construct(private readonly ConversationControl $control) {}

    private static bool $ready = false;

    /** Whether the setting's columns exist (the migration ran). Without them: «never configured», i.e. the behavior from before. */
    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasColumn('workspace_settings', 'conversation_autoclose_enabled');
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    /** @return array{enabled: bool|null, minutes: int, configured: bool} */
    public function settings(Workspace|int $workspace): array
    {
        if (! self::ready()) {
            return ['enabled' => null, 'minutes' => min(self::MAX, max(self::MIN, (int) config('support.inactivity_minutes'))), 'configured' => false];
        }

        $row = DB::table('workspace_settings')->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)->first(['conversation_autoclose_enabled', 'conversation_autoclose_minutes']);
        $enabled = $row?->conversation_autoclose_enabled;
        $minutes = (int) ($row?->conversation_autoclose_minutes ?: config('support.inactivity_minutes'));

        return ['enabled' => $enabled === null ? null : (bool) $enabled, 'minutes' => min(self::MAX, max(self::MIN, $minutes)), 'configured' => $enabled !== null];
    }

    /** Whether this conversation may be closed by inactivity under its Workspace's setting. */
    public function applies(Conversation $conversation, bool $support): bool
    {
        $settings = $this->settings($conversation->workspace_id);

        return $settings['enabled'] ?? $support;
    }

    public function minutesFor(Conversation $conversation): int
    {
        return $this->settings($conversation->workspace_id)['minutes'];
    }

    /** Seconds an execution may legitimately be in flight: the request timeout of every try plus the waits between them. */
    public function executionGrace(): int
    {
        return (int) config('n8n.timeout') * (int) config('n8n.tries') + array_sum((array) config('n8n.backoff')) + 30;
    }

    /** An AI execution that is still within its allowed time. */
    public function processing(Conversation $conversation): bool
    {
        return ChatbotExecution::where('conversation_id', $conversation->id)->whereIn('status', [ChatbotExecution::PENDING, ChatbotExecution::RUNNING])
            ->where('created_at', '>', now()->subSeconds($this->executionGrace()))->exists();
    }

    /** Quiet for longer than the Workspace's limit, by the server clock, and nothing valid in flight. */
    public function idle(Conversation $conversation): bool
    {
        return $conversation->last_message_at !== null && $conversation->last_message_at->lte(now()->subMinutes($this->minutesFor($conversation))) && ! $this->processing($conversation);
    }

    /**
     * Closes ORDINARY conversations (not support sessions: SupportSessions owns those, with their archived key) that went quiet in Workspaces that turned the setting on.
     * Returns how many THIS run closed.
     */
    public function closeIdle(): int
    {
        if (! self::ready()) {
            return 0;
        }

        $closed = 0;
        $workspaces = DB::table('workspace_settings')->where('conversation_autoclose_enabled', true)->pluck('conversation_autoclose_minutes', 'workspace_id');

        foreach ($workspaces as $workspaceId => $minutes) {
            $minutes = min(self::MAX, max(self::MIN, (int) ($minutes ?: config('support.inactivity_minutes'))));

            Conversation::with('chatbot.workspace')->where('workspace_id', $workspaceId)->whereNull('demo_key')->whereNull('closed_at')->where('handling', '!=', Conversation::RESOLVED)
                ->where('last_message_at', '<=', now()->subMinutes($minutes))->orderBy('id')->limit(200)->get()
                ->each(function (Conversation $conversation) use (&$closed) {
                    if (app(SupportDesk::class)->isRequest($conversation)) {
                        return; // support sessions have their own close
                    }

                    try {
                        if ($this->control->resolveForInactivity($conversation, fn (Conversation $locked) => $this->idle($locked))) {
                            $closed++;
                        }
                    } catch (Throwable $e) {
                        // One failing conversation never blocks the others; it stays as it was and the next run tries again.
                        Log::error('conversation_idle_close_failed', ['exception' => $e::class, 'conversation' => $conversation->id]);
                    }
                });
        }

        return $closed;
    }

    /** @param  array{enabled: bool, minutes: int}  $data */
    public function save(Workspace $workspace, array $data): void
    {
        abort_unless(self::ready(), 409, 'La actualización de la base de datos que guarda esta configuración todavía no se ha aplicado.');

        $workspace->settings()->firstOrCreate([], WorkspaceSetting::defaults());

        DB::table('workspace_settings')->where('workspace_id', $workspace->id)->update([
            'conversation_autoclose_enabled' => $data['enabled'], 'conversation_autoclose_minutes' => min(self::MAX, max(self::MIN, $data['minutes'])), 'updated_at' => now(),
        ]);
    }
}
