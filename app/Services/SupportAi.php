<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The AI of CCG's support (administrative Workspace only). It answers the support desk of the clients' administrators and CCG's public chat when the chatbot that receives them
 * is ACTIVE and has a platform workflow. This is the only place that turns it on: a valid configuration is required (the workflow must be in the platform catalog, enabled, and
 * the platform n8n configured), it is never switched on by itself, and switching it off returns the support to people without touching conversations.
 * A client Workspace configures its own assistants in Asistentes; nothing here reaches them.
 */
class SupportAi
{
    public function __construct(private readonly SupportDesk $desk, private readonly ChatbotWorkflows $workflows, private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> everything the panel shows; no secret of n8n is part of it */
    public function status(): array
    {
        $bot = $this->desk->destination();
        $catalog = collect($this->workflows->catalog())->filter(fn ($entry, $key) => $this->workflows->assignable((string) $key))
            ->map(fn ($entry, $key) => ['key' => (string) $key, 'name' => (string) ($entry['name'] ?? $key), 'version' => isset($entry['version']) ? (string) $entry['version'] : null])->values()->all();
        $n8nReady = filled(config('n8n.execution_url')) && filled(config('n8n.secret'));

        return [
            'chatbot' => $bot ? ['id' => $bot->id, 'name' => $bot->name] : null,
            'enabled' => $bot !== null && $bot->is_active && $this->workflows->for($bot) !== null,
            'workflow' => $bot ? $this->workflows->for($bot)?->key : null,
            'catalog' => $catalog,
            'n8nConfigured' => $n8nReady,
            // Why it cannot answer right now, in fixed words (nothing from n8n).
            'problem' => match (true) {
                $bot === null => 'No hay un asistente que reciba el soporte.',
                ! $n8nReady => 'El n8n de la plataforma no está configurado (N8N_EXECUTION_URL / N8N_EXECUTION_SECRET).',
                $catalog === [] => 'No hay workflows habilitados en el catálogo de la plataforma.',
                default => null,
            },
            'fallback' => 'Si n8n no responde, tarda de más o devuelve una respuesta inválida, la conversación pasa a una persona; nunca se queda sin atención.',
        ];
    }

    /** @throws ValidationException */
    public function update(bool $enabled, ?string $workflow, User $by): void
    {
        $bot = $this->desk->destination();

        if (! $bot) {
            throw ValidationException::withMessages(['enabled' => 'No hay un asistente que reciba el soporte.']);
        }

        $before = ['active' => $bot->is_active && $this->workflows->for($bot) !== null, 'workflow' => $bot->workflow_key];

        if ($enabled) {
            if (! filled(config('n8n.execution_url')) || ! filled(config('n8n.secret'))) {
                throw ValidationException::withMessages(['enabled' => 'El n8n de la plataforma no está configurado: el soporte con IA no se puede activar.']);
            }

            if ($workflow === null || ! $this->workflows->assignable($workflow)) {
                throw ValidationException::withMessages(['workflow' => 'Elige un workflow habilitado del catálogo de la plataforma.']);
            }

            $bot->forceFill(['is_active' => true, 'workflow_key' => $workflow])->save();
        } else {
            // Off = people attend again. The assignment is kept (the choice is not lost), but an inactive chatbot never runs a workflow.
            $bot->forceFill(['is_active' => false])->save();
        }

        $after = ['active' => $enabled, 'workflow' => $bot->refresh()->workflow_key];
        $changes = array_values(array_filter([
            $before['active'] !== $after['active'] ? AuditLogger::change('Soporte con IA', $before['active'] ? 'activo' : 'desactivado', $after['active'] ? 'activo' : 'desactivado') : null,
            $before['workflow'] !== $after['workflow'] ? AuditLogger::change('Workflow', $before['workflow'], $after['workflow']) : null,
        ]));
        $this->audit->record('updated', 'chatbot', $bot->id, $bot->name, $changes);
    }
}
