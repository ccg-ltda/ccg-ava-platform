<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Chatbot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The instructions of an assistant in TWO modes that never mix. The ONLY place that reads the effective text, saves a block or the mode, versions it and records what n8n has confirmed.
 *
 *  - `single`: ONE complete prompt, the `instructions` column (every assistant that existed before the blocks is in this mode with its text byte for byte).
 *  - `modular`: three independent blocks, in this order: 1 Identidad y comunicación (`instructions_identity`), 2 Memoria y conocimiento (`instructions_knowledge`),
 *    3 Comportamiento, reglas y derivación (`instructions_limits`). The column names are older than the final names of blocks 2 and 3; the labels are what people see.
 *  - Effective text: ONLY the active mode. In `single` it is the prompt; in `modular` it is the non-empty blocks joined under fixed headings. The content of the other mode is
 *    never deleted and never sent: switching mode is a versioned change (it changes what n8n receives) and switching back finds everything as it was. Both the configuration
 *    n8n reads and the payload of every execution use it: there is no second copy.
 *  - Saving a block never touches the others; it is refused if the block changed since the person opened it (hash), if the whole text would exceed what the workflow
 *    accepts, or if the content is only whitespace. Every real change creates a numbered version (a full snapshot) and an audit event without the text.
 *  - «Guardado en Zonno» is a fact of this database; «Sincronizado» only means n8n CONFIRMED it received that version (it read the configuration, or an execution that carried
 *    it was accepted). Zonno never writes into the workflow: n8n pulls.
 * Before the migration exists (`ready()` false) the assistant keeps its single `instructions` field exactly as before.
 */
class ChatbotInstructions
{
    public const MODES = ['single' => 'Single · un solo prompt completo', 'modular' => 'Modular · tres bloques independientes'];

    /** block key => [column, label, help, mode] — the order is the order of the effective text of the modular mode. */
    public const BLOCKS = [
        'identity' => ['instructions_identity', 'Bloque 1 · Identidad y comunicación', 'Quién es el asistente, su rol y propósito, la personalidad y el tono, el idioma, cómo se dirige a las personas y ejemplos de expresiones que debe usar o evitar.', 'modular'],
        'knowledge' => ['instructions_knowledge', 'Bloque 2 · Memoria y conocimiento', 'Cómo usa el contexto y la información disponible: qué recuerda de la conversación, cómo consulta los documentos de la base de conocimiento y qué hace cuando no tiene información suficiente. No inventa datos, precios, horarios ni políticas.', 'modular'],
        'limits' => ['instructions_limits', 'Bloque 3 · Comportamiento, reglas y derivación', 'Cómo actúa ante solicitudes concretas, qué datos pide antes de continuar, cómo trata quejas y casos especiales, y cuándo reconoce que no puede resolver y pasa la conversación a una persona.', 'modular'],
        'legacy' => ['instructions', 'Prompt completo', 'Un solo texto con todas las instrucciones del asistente. Es el que se envía mientras el modo sea Single.', 'single'],
    ];

    private const HEADINGS = ['identity' => 'Identidad y comunicación', 'knowledge' => 'Memoria y conocimiento', 'limits' => 'Comportamiento, reglas y derivación'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Whether the database has the blocks, the versions and the feedback (migration `extend_chatbots_with_instruction_blocks_and_feedback`). */
    public static function ready(): bool
    {
        return Schema::hasColumn('chatbots', 'instructions_identity') && Schema::hasTable('chatbot_instruction_versions');
    }

    public static function hash(?string $text): string
    {
        return sha1((string) $text);
    }

    /** The active mode. An assistant without the migration is always `single`. */
    public function mode(Chatbot $chatbot): string
    {
        return self::ready() && ($chatbot->instructions_mode ?? 'single') === 'modular' ? 'modular' : 'single';
    }

    /** @return list<string> the block keys that belong to a mode */
    public static function blocksOf(string $mode): array
    {
        return array_keys(array_filter(self::BLOCKS, fn (array $block) => $block[3] === $mode));
    }

    /** What n8n gets as the system message: ONLY the active mode (the other one is kept, never sent, never merged). */
    public function effective(Chatbot $chatbot): string
    {
        if ($this->mode($chatbot) === 'single') {
            return (string) $chatbot->instructions;
        }

        $parts = [];

        foreach (self::blocksOf('modular') as $key) {
            $text = trim((string) $chatbot->{self::BLOCKS[$key][0]});

            if ($text !== '') {
                $parts[] = '## '.self::HEADINGS[$key]."\n".$text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * The blocks of the active mode as separate texts, for a workflow that wants them apart (the joined `effective` text stays the compatible one).
     *
     * @return list<array{key: string, label: string, text: string}>
     */
    public function activeBlocks(Chatbot $chatbot): array
    {
        if ($this->mode($chatbot) === 'single') {
            return [];
        }

        return collect(self::blocksOf('modular'))->map(fn (string $key) => ['key' => $key, 'label' => self::HEADINGS[$key] ?? self::BLOCKS[$key][1], 'text' => trim((string) $chatbot->{self::BLOCKS[$key][0]})])
            ->filter(fn (array $block) => $block['text'] !== '')->values()->all();
    }

    /** @return list<array{key: string, mode: string, label: string, help: string, text: string, hash: string, chars: int}> all four, each with the mode it belongs to */
    public function blocks(Chatbot $chatbot): array
    {
        $rows = [];

        foreach (self::BLOCKS as $key => [$column, $label, $help, $mode]) {
            $text = (string) $chatbot->{$column};
            $rows[] = ['key' => $key, 'mode' => $mode, 'label' => $label, 'help' => $help, 'text' => $text, 'hash' => self::hash($text), 'chars' => mb_strlen($text)];
        }

        return $rows;
    }

    /**
     * Where the instructions stand: the saved version and what n8n has confirmed.
     *
     * @return array{version: int, mode: string, syncedVersion: ?int, syncedAt: ?string, state: string, label: string}
     */
    public function status(Chatbot $chatbot): array
    {
        $version = (int) $chatbot->instructions_version;
        $synced = $chatbot->instructions_synced_version !== null ? (int) $chatbot->instructions_synced_version : null;
        $settings = $chatbot->workspace->settingsOrDefault();
        $at = $chatbot->instructions_synced_at;

        [$state, $label] = match (true) {
            $synced === null => ['never', 'Guardado en Zonno. n8n todavía no ha confirmado que lo recibió.'],
            $synced >= $version => ['synced', 'Sincronizado: n8n confirmó que recibió esta versión.'],
            default => ['pending', "Guardado en Zonno (v{$version}). n8n sigue en la v{$synced}: la recibirá en su próxima lectura o ejecución."],
        };

        return ['version' => $version, 'mode' => $this->mode($chatbot), 'syncedVersion' => $synced, 'syncedAt' => $at ? $settings->formatDate($at).' '.$settings->formatTime($at) : null, 'state' => $state, 'label' => $label];
    }

    /**
     * Saves ONE block. Returns the new version, or the current one when the text did not change.
     *
     * @param  ?string  $expectedHash  the hash of the block when the person opened it; a different stored hash means someone else changed it meanwhile
     *
     * @throws ValidationException
     */
    public function save(Chatbot $chatbot, string $block, ?string $text, ?string $expectedHash, ?User $by, string $source = 'manual', ?int $feedbackId = null): int
    {
        abort_unless(self::ready() && isset(self::BLOCKS[$block]), 404);

        $column = self::BLOCKS[$block][0];
        $text = $this->clean($text);

        return DB::transaction(function () use ($chatbot, $block, $column, $text, $expectedHash, $by, $source, $feedbackId) {
            $locked = Chatbot::whereKey($chatbot->id)->where('workspace_id', $chatbot->workspace_id)->lockForUpdate()->firstOrFail();
            $current = (string) $locked->{$column};

            if ($expectedHash !== null && ! hash_equals(self::hash($current), $expectedHash)) {
                throw ValidationException::withMessages([$block => 'Este apartado cambió mientras lo editabas. Recarga la página para ver la versión actual antes de guardar.']);
            }

            if ((string) $text === $current) {
                return (int) $locked->instructions_version;
            }

            // Only the active mode is edited: the content of the other one is kept untouched until the person switches to it.
            if (self::BLOCKS[$block][3] !== $this->mode($locked)) {
                $other = self::BLOCKS[$block][3] === 'single' ? 'Single' : 'Modular';

                throw ValidationException::withMessages([$block => "Este apartado es del modo {$other}, que no está activo. Cambia de modo para editarlo; su contenido se conserva."]);
            }

            $this->ensureBaseline($locked);
            $locked->{$column} = $text;
            $this->assertFits($locked, $block);

            $version = (int) $locked->instructions_version + 1;
            $locked->instructions_version = $version;
            $locked->save();

            DB::table('chatbot_instruction_versions')->insert([
                'workspace_id' => $locked->workspace_id, 'chatbot_id' => $locked->id, 'version' => $version,
                'identity' => $locked->instructions_identity, 'limits' => $locked->instructions_limits, 'knowledge' => $locked->instructions_knowledge, 'legacy' => $locked->instructions, 'mode' => $this->mode($locked),
                'changed_blocks' => json_encode([$block]), 'source' => $source, 'feedback_id' => $feedbackId, 'created_by' => $by?->id, 'created_at' => now(),
            ]);

            // The legacy block is the `instructions` column, which the model already audits; the new blocks are audited here (sizes, never the text).
            $block === 'legacy' || $this->audit->record('updated', 'chatbot', $locked->id, $locked->name, [
                AuditLogger::change('Instrucciones · '.self::BLOCKS[$block][1], mb_strlen($current).' caracteres', mb_strlen((string) $text)." caracteres (versión {$version}".($source === 'feedback' ? ', desde una retroalimentación' : '').')'),
            ], $locked->workspace);

            $chatbot->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });
    }

    /**
     * Switches between `single` and `modular`. The content of both modes is kept; only what n8n receives changes, so it is a versioned, audited change that n8n has to
     * confirm like any other. Returns the new version, or the current one when the mode already was that.
     *
     * @throws ValidationException
     */
    public function setMode(Chatbot $chatbot, string $mode, ?User $by): int
    {
        abort_unless(self::ready() && in_array($mode, ['single', 'modular'], true), 404);

        return DB::transaction(function () use ($chatbot, $mode, $by) {
            $locked = Chatbot::whereKey($chatbot->id)->where('workspace_id', $chatbot->workspace_id)->lockForUpdate()->firstOrFail();
            $before = $this->mode($locked);

            if ($before === $mode) {
                return (int) $locked->instructions_version;
            }

            $this->ensureBaseline($locked);
            $locked->instructions_mode = $mode;
            $this->assertFits($locked, 'mode');

            $version = (int) $locked->instructions_version + 1;
            $locked->instructions_version = $version;
            $locked->save();

            DB::table('chatbot_instruction_versions')->insert([
                'workspace_id' => $locked->workspace_id, 'chatbot_id' => $locked->id, 'version' => $version,
                'identity' => $locked->instructions_identity, 'limits' => $locked->instructions_limits, 'knowledge' => $locked->instructions_knowledge, 'legacy' => $locked->instructions, 'mode' => $mode,
                'changed_blocks' => json_encode(['mode']), 'source' => 'manual', 'created_by' => $by?->id, 'created_at' => now(),
            ]);

            $this->audit->record('updated', 'chatbot', $locked->id, $locked->name, [AuditLogger::change('Modo de instrucciones', ucfirst($before), ucfirst($mode)." (versión {$version})")], $locked->workspace);

            $chatbot->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });
    }

    /**
     * n8n confirmed it received `$version` (it read the configuration, or accepted an execution that carried it). Only moves forward, and never touches
     * `updated_at` or the audit: it is a fact about the connection, not a change of the assistant.
     */
    public function markSynced(int $chatbotId, int $version): void
    {
        if (! self::ready()) {
            return;
        }

        DB::table('chatbots')->where('id', $chatbotId)->where(fn ($q) => $q->whereNull('instructions_synced_version')->orWhere('instructions_synced_version', '<=', $version))
            ->update(['instructions_synced_version' => $version, 'instructions_synced_at' => now()]);
    }

    /** @return list<array<string, mixed>> the latest versions, newest first (who changed what, never the text of other Workspaces) */
    public function versions(Chatbot $chatbot, int $limit = 10): array
    {
        if (! self::ready()) {
            return [];
        }

        $settings = $chatbot->workspace->settingsOrDefault();

        return DB::table('chatbot_instruction_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.created_by')
            ->where('v.chatbot_id', $chatbot->id)->where('v.workspace_id', $chatbot->workspace_id)->orderByDesc('v.version')->limit($limit)
            ->get(['v.version', 'v.source', 'v.changed_blocks', 'v.created_at', 'u.name as author'])
            ->map(fn ($row) => [
                'version' => (int) $row->version, 'source' => $row->source, 'blocks' => array_map(fn ($key) => $key === 'mode' ? 'Modo de configuración' : (self::BLOCKS[$key][1] ?? $key), (array) json_decode((string) $row->changed_blocks, true)),
                'author' => $row->author, 'at' => $settings->formatDate(Carbon::parse($row->created_at)).' '.$settings->formatTime(Carbon::parse($row->created_at)),
            ])->all();
    }

    /** An assistant created after the migration has no snapshot of its current version yet: the state BEFORE the first change is kept as that version, so history is complete. */
    private function ensureBaseline(Chatbot $chatbot): void
    {
        $exists = DB::table('chatbot_instruction_versions')->where('chatbot_id', $chatbot->id)->where('version', $chatbot->instructions_version)->exists();

        if (! $exists) {
            DB::table('chatbot_instruction_versions')->insert([
                'workspace_id' => $chatbot->workspace_id, 'chatbot_id' => $chatbot->id, 'version' => $chatbot->instructions_version,
                'identity' => $chatbot->instructions_identity, 'limits' => $chatbot->instructions_limits, 'knowledge' => $chatbot->instructions_knowledge, 'legacy' => $chatbot->instructions, 'mode' => $this->mode($chatbot),
                'changed_blocks' => json_encode([]), 'source' => 'migration', 'created_at' => now(),
            ]);
        }
    }

    /** Empty or whitespace-only becomes «no content» (null); line endings are normalized; nothing else is altered. */
    private function clean(?string $text): ?string
    {
        $text = str_replace("\r\n", "\n", (string) $text);

        return trim($text) === '' ? null : rtrim($text);
    }

    /** The whole effective text must fit what the workflow accepts: better refused here than silently cut there. */
    private function assertFits(Chatbot $chatbot, string $block): void
    {
        $max = (int) config('n8n.max_instructions');

        if (mb_strlen($this->effective($chatbot)) > $max) {
            throw ValidationException::withMessages([$block => "Las instrucciones juntas no pueden superar {$max} caracteres (es lo que acepta el workflow). Acorta este apartado o el resto."]);
        }
    }
}
