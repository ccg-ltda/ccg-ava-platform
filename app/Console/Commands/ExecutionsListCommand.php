<?php

namespace App\Console\Commands;

use App\Models\ChatbotExecution;
use App\Models\Message;
use Illuminate\Console\Command;

/**
 * Lists chatbot executions (and shows one) so a failed one can be diagnosed without opening the database. Read-only. It prints only
 * identifiers, states, fixed codes and times: never the text of a message, a contact, a name or a secret. To run a failed execution
 * again use `executions:recover --retry=ID`.
 */
class ExecutionsListCommand extends Command
{
    protected $signature = 'executions:list
        {--status= : pending, running, succeeded, discarded or failed}
        {--failed : shortcut for failed executions and replies the channel refused or left uncertain}
        {--workspace= : only this Workspace id}
        {--hours=24 : how far back to look}
        {--limit=25 : how many rows}
        {--show= : show one execution by id, with what its code means}';

    protected $description = 'List chatbot executions and their outcome codes without exposing conversations (read-only)';

    /** What each fixed code means and what to do. */
    private const CODES = [
        'not_configured' => 'Falta N8N_EXECUTION_URL o N8N_EXECUTION_SECRET. Defínelos y reinicia los workers.',
        'insecure_target' => 'La URL de n8n no usa https en producción. Corrígela.',
        'unsafe_target' => 'La URL de n8n resuelve a una red interna o reservada. Usa una dirección pública.',
        'unreachable' => 'Ava no pudo conectar con n8n (red, DNS o tiempo de espera). Revisa que n8n esté arriba y alcanzable.',
        'request_failed' => 'La llamada a n8n falló por un error inesperado de red.',
        'n8n_unavailable' => 'n8n respondió 408, 425, 429 o 5xx: se reintentó. Revisa el workflow en n8n.',
        'n8n_rejected' => 'n8n respondió 4xx: el workflow rechazó la solicitud (¿falló la verificación de la firma o la ruta del webhook?).',
        'invalid_response' => 'n8n respondió, pero no con el contrato: debe repetir execution_id y traer reply o handoff.',
        'retries_exhausted' => 'Fallo temporal que siguió fallando en todos los intentos.',
        'late_response' => 'La respuesta llegó cuando una persona ya tenía la conversación: se descartó.',
        'superseded' => 'Cambió el control de la conversación durante la ejecución: se descartó.',
        'superseded_before_send' => 'Cambió el control antes de enviar: no se envió.',
        'workflow_changed' => 'Se cambió o quitó el workflow del chatbot antes de correr: se descartó.',
        'not_runnable' => 'La ejecución ya no estaba pendiente (otra la tomó o ya terminó).',
    ];

    public function handle(): int
    {
        if ($this->option('show') !== null) {
            return $this->show((int) $this->option('show'));
        }

        $query = ChatbotExecution::query()->where('created_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))->orderByDesc('id');

        if ($this->option('workspace') !== null) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        if ($this->option('status') !== null) {
            $query->where('status', $this->option('status'));
        }

        if ($this->option('failed')) {
            $query->where(fn ($q) => $q->where('status', ChatbotExecution::FAILED)->orWhereIn('delivery', [ChatbotExecution::DELIVERY_FAILED, ChatbotExecution::DELIVERY_UNCERTAIN]));
        }

        $rows = $query->limit(max(1, min(200, (int) $this->option('limit'))))->get();

        if ($rows->isEmpty()) {
            $this->components->info('Ninguna ejecución con esos filtros.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'ws', 'bot', 'conv', 'estado', 'int.', 'código', 'control', 'entrega', 'workflow', 'creada'],
            $rows->map(fn (ChatbotExecution $e) => [$e->id, $e->workspace_id, $e->chatbot_id, $e->conversation_id, $e->status, $e->attempts, $e->outcome_code ?? '-', $e->control_result ?? '-', $e->delivery ?? '-', $e->workflow_key, $e->created_at->format('m-d H:i:s')])->all(),
        );
        $this->line('Detalle y significado del código: php artisan executions:list --show=ID · Repetir una fallida sin respuesta: php artisan executions:recover --retry=ID');

        return self::SUCCESS;
    }

    private function show(int $id): int
    {
        $execution = ChatbotExecution::find($id);

        if (! $execution) {
            $this->components->error('No existe esa ejecución.');

            return self::FAILURE;
        }

        $reply = $execution->reply_message_id ? Message::find($execution->reply_message_id, ['id', 'status', 'failure_reason']) : null;

        $this->table(['campo', 'valor'], [
            ['id', $execution->id], ['Workspace', $execution->workspace_id], ['chatbot', $execution->chatbot_id], ['conversación', $execution->conversation_id], ['mensaje entrante', $execution->message_id],
            ['correlation_id', $execution->correlation_id], ['workflow', "{$execution->workflow_key} (n8n {$execution->n8n_workflow_id}, v".($execution->workflow_version ?? '-').')'],
            ['estado', $execution->status], ['intentos', $execution->attempts], ['código', $execution->outcome_code ?? '-'], ['control', $execution->control_result ?? '-'],
            ['entrega', $execution->delivery ?? '-'], ['mensaje de respuesta', $reply ? "#{$reply->id} estado={$reply->status}".($reply->failure_reason ? " motivo={$reply->failure_reason}" : '') : '-'],
            ['creada', $execution->created_at], ['iniciada', $execution->started_at ?? '-'], ['terminada', $execution->finished_at ?? '-'],
        ]);

        if ($execution->outcome_code && isset(self::CODES[$execution->outcome_code])) {
            $this->line('Significado: '.self::CODES[$execution->outcome_code]);
        }

        $execution->delivery === ChatbotExecution::DELIVERY_UNCERTAIN
            ? $this->line('Entrega incierta: el canal pudo haberla recibido. Ava no la reenvía; verifica en WhatsApp antes de reenviar a mano.')
            : null;

        return self::SUCCESS;
    }
}
