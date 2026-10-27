<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Improving an assistant from a conversation WITHOUT rewriting its prompt. A person flags a reply of the assistant, says why, and proposes the new content of ONE of the
 * blocks of the active mode (the three modular ones, or the single prompt) (they can start from a suggested line and edit it). Nothing changes until a person with permission approves it: the approval applies exactly that block
 * as a new version (ChatbotInstructions::save, source `feedback`), audited with who approved it. There is no model in the loop: this class never writes a proposal by itself
 * and the fixed safety guidelines (config/chatbots.php `guidelines`) are not part of any block, so no proposal can edit them. A proposal made on an older text of the block
 * is `stale` and is never applied (the person re-proposes on the current text).
 */
class ChatbotFeedback
{
    public const TARGETS = ['identity', 'knowledge', 'limits', 'legacy'];

    public function __construct(private readonly ChatbotInstructions $instructions, private readonly AuditLogger $audit) {}

    public static function ready(): bool
    {
        return ChatbotInstructions::ready() && Schema::hasTable('chatbot_feedback');
    }

    /**
     * @param  array{reason: string, desired_behavior?: ?string, target_block: string, proposed_text: string}  $data
     *
     * @throws ValidationException
     */
    public function propose(User $by, Conversation $conversation, Message $message, array $data): int
    {
        abort_unless(self::ready(), 404);
        // The message must be a reply of the assistant in THIS conversation and Workspace (the database enforces the same with a composite key).
        abort_unless($message->conversation_id === $conversation->id && $message->workspace_id === $conversation->workspace_id && $message->sender === 'ai', 404);

        $chatbot = Chatbot::where('workspace_id', $conversation->workspace_id)->findOrFail($conversation->chatbot_id);
        $block = $data['target_block'];

        // The proposal is about the text n8n really receives: a block of the mode that is NOT active would change nothing.
        if (! in_array($block, ChatbotInstructions::blocksOf($this->instructions->mode($chatbot)), true)) {
            throw ValidationException::withMessages(['target_block' => 'Ese apartado no es del modo activo del asistente.']);
        }

        $column = ChatbotInstructions::BLOCKS[$block][0];
        $current = (string) $chatbot->{$column};
        $proposed = str_replace("\r\n", "\n", trim($data['proposed_text']));

        if ($proposed === '' || $proposed === $current) {
            throw ValidationException::withMessages(['proposed_text' => 'Escribe el contenido propuesto para el apartado: debe ser distinto del actual.']);
        }

        return (int) DB::table('chatbot_feedback')->insertGetId([
            'workspace_id' => $chatbot->workspace_id, 'chatbot_id' => $chatbot->id, 'conversation_id' => $conversation->id, 'message_id' => $message->id,
            'reason' => trim($data['reason']), 'desired_behavior' => filled($data['desired_behavior'] ?? null) ? trim($data['desired_behavior']) : null,
            'target_block' => $block, 'proposed_text' => $proposed, 'base_version' => (int) $chatbot->instructions_version, 'base_hash' => ChatbotInstructions::hash($current),
            'status' => 'pending', 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Applies a pending proposal as a new version of its block. @throws ValidationException when the block changed since the proposal */
    public function approve(User $by, Chatbot $chatbot, int $feedbackId): int
    {
        abort_unless(self::ready(), 404);

        $feedback = DB::table('chatbot_feedback')->where('id', $feedbackId)->where('chatbot_id', $chatbot->id)->where('workspace_id', $chatbot->workspace_id)->first();
        abort_unless($feedback, 404);

        if ($feedback->status !== 'pending') {
            throw ValidationException::withMessages(['feedback' => 'Esta propuesta ya fue resuelta.']);
        }

        // The hash of the block when it was proposed is the guard: if someone edited it meanwhile, nothing is applied and the proposal is closed as stale.
        $column = ChatbotInstructions::BLOCKS[$feedback->target_block][0];

        if (! hash_equals(ChatbotInstructions::hash((string) $chatbot->fresh()->{$column}), $feedback->base_hash)) {
            DB::table('chatbot_feedback')->where('id', $feedback->id)->where('status', 'pending')->update(['status' => 'stale', 'updated_at' => now()]);

            throw ValidationException::withMessages(['feedback' => 'El apartado cambió después de esta propuesta: ya no se puede aplicar. Propón el cambio de nuevo sobre el texto actual.']);
        }

        return DB::transaction(function () use ($by, $chatbot, $feedback) {
            // Claimed first, so two simultaneous approvals apply it once.
            $claimed = DB::table('chatbot_feedback')->where('id', $feedback->id)->where('status', 'pending')->update(['status' => 'approved', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages(['feedback' => 'Esta propuesta ya fue resuelta.']);
            }

            $version = $this->instructions->save($chatbot, $feedback->target_block, $feedback->proposed_text, $feedback->base_hash, $by, 'feedback', (int) $feedback->id);
            DB::table('chatbot_feedback')->where('id', $feedback->id)->update(['applied_version' => $version]);
            $this->audit->record('updated', 'chatbot_feedback', (int) $feedback->id, $chatbot->name, [AuditLogger::change('Retroalimentación', 'Pendiente', "Aprobada (versión {$version})")], $chatbot->workspace);

            return $version;
        });
    }

    public function reject(User $by, Chatbot $chatbot, int $feedbackId): void
    {
        abort_unless(self::ready(), 404);

        $changed = DB::table('chatbot_feedback')->where('id', $feedbackId)->where('chatbot_id', $chatbot->id)->where('workspace_id', $chatbot->workspace_id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'updated_at' => now()]);

        abort_unless($changed === 1, 404);
        $this->audit->record('updated', 'chatbot_feedback', $feedbackId, $chatbot->name, [AuditLogger::change('Retroalimentación', 'Pendiente', 'Rechazada')], $chatbot->workspace);
    }

    /** @return list<array<string, mixed>> the open proposals of one assistant with the text they would replace (for the side-by-side review) */
    public function pending(Chatbot $chatbot): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table('chatbot_feedback as f')->leftJoin('users as u', 'u.id', '=', 'f.created_by')->join('messages as m', 'm.id', '=', 'f.message_id')
            ->where('f.chatbot_id', $chatbot->id)->where('f.workspace_id', $chatbot->workspace_id)->where('f.status', 'pending')->orderBy('f.id')
            ->get(['f.id', 'f.reason', 'f.desired_behavior', 'f.target_block', 'f.proposed_text', 'f.base_hash', 'f.conversation_id', 'u.name as author', 'm.body as reply'])
            ->map(function ($row) use ($chatbot) {
                $current = (string) $chatbot->{ChatbotInstructions::BLOCKS[$row->target_block][0]};

                return [
                    'id' => (int) $row->id, 'reason' => $row->reason, 'desiredBehavior' => $row->desired_behavior, 'block' => $row->target_block, 'blockLabel' => ChatbotInstructions::BLOCKS[$row->target_block][1],
                    'author' => $row->author, 'reply' => mb_substr((string) $row->reply, 0, 500), 'current' => $current, 'proposed' => $row->proposed_text,
                    // True when the block changed after the proposal: approving would be refused, the person must propose again.
                    'stale' => ! hash_equals(ChatbotInstructions::hash($current), $row->base_hash),
                ];
            })->all();
    }
}
