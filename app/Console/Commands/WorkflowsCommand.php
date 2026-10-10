<?php

namespace App\Console\Commands;

use App\Models\Chatbot;
use App\Services\ChatbotWorkflows;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Platform-team tool (never reachable from the web): gives a chatbot one of the workflows of the catalog
 * (`config/n8n.php`, stored in `chatbots.workflow_key`), takes it away, or lists the assignments. A client cannot choose or change either, so a workflow
 * can never come from a manipulated request.
 */
class WorkflowsCommand extends Command
{
    protected $signature = 'workflows:manage
        {action : assign | unassign | list}
        {--key= : assign: key of the workflow (an entry of config/n8n.php)}
        {--chatbot= : assign / unassign: id of the chatbot}';

    protected $description = 'Assign n8n workflows to chatbots (platform team only)';

    public function handle(ChatbotWorkflows $workflows): int
    {
        return match ($this->argument('action')) {
            'assign' => $this->assign($workflows),
            'unassign' => $this->unassign($workflows),
            'list' => $this->list($workflows),
            default => $this->fail('Acción desconocida. Usa assign, unassign o list.'),
        };
    }

    private function assign(ChatbotWorkflows $workflows): int
    {
        $chatbot = Chatbot::with('workspace')->find($this->option('chatbot'));

        if (! $chatbot) {
            $this->components->error('Indica un --chatbot existente.');

            return self::FAILURE;
        }

        try {
            $workflows->assign($chatbot, (string) $this->option('key'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Chatbot #{$chatbot->id} «{$chatbot->name}» (Workspace {$chatbot->workspace->code}) ejecutará «{$this->option('key')}».");

        return self::SUCCESS;
    }

    private function unassign(ChatbotWorkflows $workflows): int
    {
        $chatbot = Chatbot::with('workspace')->find($this->option('chatbot'));

        if (! $chatbot) {
            $this->components->error('Indica un --chatbot existente.');

            return self::FAILURE;
        }

        $workflows->unassign($chatbot);
        $this->components->info("Chatbot #{$chatbot->id} sin workflow.");

        return self::SUCCESS;
    }

    private function list(ChatbotWorkflows $workflows): int
    {
        $this->table(['workflow', 'n8n id', 'path'], collect($workflows->catalog())->map(fn ($entry, $key) => [$key, $entry['n8n_workflow_id'] ?? '', $entry['webhook_path'] ?? ''])->values()->all());
        $this->table(['workspace', 'chatbot', 'workflow', 'activo'], Chatbot::with('workspace')->whereNotNull('workflow_key')->orderBy('workspace_id')->orderBy('id')->get()
            ->map(fn (Chatbot $c) => [$c->workspace->code, "#{$c->id} {$c->name}", $c->workflow_key, $c->is_active ? 'sí' : 'no'])->all());

        return self::SUCCESS;
    }
}
