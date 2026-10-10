<?php

namespace App\Services;

use App\Chatbots\ResolvedWorkflow;
use App\Models\Chatbot;
use InvalidArgumentException;

/**
 * Which workflow answers for a chatbot. The catalog (what exists in the platform's n8n) is `config/n8n.php`
 * `workflows`, reviewed and deployed with the code; the assignment is `chatbots.workflow_key`, the ONLY source of truth.
 * Being a column of the chatbot, it can only belong to that chatbot's own Workspace, there is at most one per chatbot and
 * it goes away with the chatbot. Nothing here comes from a request.
 *
 * What the database cannot hold (the key must be in a catalog that lives in code) is checked on every path:
 *  - when WRITING: `assign()` and the `saving` hook of `Chatbot` (any Eloquent write that sets the key) refuse a key that
 *    is not registered, is disabled or has an unsafe path, and a chatbot that is inactive or a demo one;
 *  - when READING: `for()` checks the key against the catalog again, so a key written by raw SQL or left stale by a catalog
 *    change grants nothing.
 */
class ChatbotWorkflows
{
    private const PATH = '/^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/';

    /** The authorized workflow of the chatbot, or null when it has none (or it is switched off, unknown or malformed). */
    public function for(Chatbot $chatbot): ?ResolvedWorkflow
    {
        return filled($chatbot->workflow_key) ? $this->catalogEntry($chatbot->workflow_key) : null;
    }

    /** Gives an ACTIVE chatbot a workflow of the catalog (platform team only). */
    public function assign(Chatbot $chatbot, string $key): void
    {
        $chatbot->forceFill(['workflow_key' => $key])->save(); // the `saving` hook refuses what is not valid
    }

    public function unassign(Chatbot $chatbot): void
    {
        $chatbot->forceFill(['workflow_key' => null])->save();
    }

    /**
     * Refuses an assignment that is not valid, wherever it is written from. Called by the `saving` hook of `Chatbot`
     * when `workflow_key` is being set.
     *
     * @throws InvalidArgumentException
     */
    public function assertAssignable(Chatbot $chatbot): void
    {
        if (! $chatbot->is_active || $chatbot->is_demo) {
            throw new InvalidArgumentException('El chatbot está inactivo o es de demostración: no se le puede asignar un workflow.');
        }

        if ($this->catalogEntry((string) $chatbot->workflow_key) === null) {
            throw new InvalidArgumentException("El workflow «{$chatbot->workflow_key}» no está registrado, está deshabilitado o su ruta no es válida.");
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        return (array) config('n8n.workflows');
    }

    private function catalogEntry(string $key): ?ResolvedWorkflow
    {
        $entry = $this->catalog()[$key] ?? null;

        if (! is_array($entry) || ($entry['enabled'] ?? true) !== true || ! is_string($entry['webhook_path'] ?? null) || ! preg_match(self::PATH, $entry['webhook_path']) || ! is_string($entry['n8n_workflow_id'] ?? null)) {
            return null;
        }

        return new ResolvedWorkflow($key, (string) ($entry['name'] ?? $key), $entry['n8n_workflow_id'], $entry['webhook_path'], isset($entry['version']) ? (string) $entry['version'] : null);
    }
}
