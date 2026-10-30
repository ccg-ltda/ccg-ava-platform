<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\WorkspaceSetting;

/**
 * The SIMULATED journey of the demonstration conversation «DEMOSTRACIÓN — Actividad técnica simulada» (App\Services\DemoConversations::ACTIVITY_KEY), so the panel «Actividad
 * técnica» can be shown without connecting any external API. It is generated here, in memory, from this fixed script: NOTHING of it is written to `agent_tool_calls`,
 * `chatbot_executions` or any other table of real activity, so no report, metric or reconciler can ever read it as a real call, and it needs no cleaning beyond deleting the demo
 * conversation. It is returned only for that one conversation (never for another, whatever the request says) and is always flagged `simulated`.
 */
class DemoTechnicalActivity
{
    /** [offset seconds from the first message, tool key, label, kind, status, http, duration ms, reason, result, retry of the previous one] */
    private const SCRIPT = [
        [2, 'intent', 'Identificación de la intención (simulada)', 'read', 'succeeded', null, 180, 'Entender qué pide el cliente en su primer mensaje.', 'Intención: consultar disponibilidad de un servicio.', false],
        [3, 'customer.lookup', 'Consulta del cliente (simulada)', 'read', 'succeeded', 200, 64, 'Saber si quien escribe ya es un cliente registrado.', 'Cliente de demostración encontrado (sin datos reales).', false],
        [4, 'booking.services', 'Consulta de servicios (simulada)', 'read', 'succeeded', 200, 112, 'Buscar el servicio que el cliente mencionó.', '3 servicios ficticios; «Limpieza dental» coincide.', false],
        [6, 'booking.availability', 'Consulta de disponibilidad (simulada)', 'read', 'failed', 503, 5004, 'Pedir los horarios libres de mañana.', 'El servicio de agenda simulado no respondió a tiempo.', false],
        [9, 'booking.availability', 'Consulta de disponibilidad (simulada)', 'read', 'succeeded', 200, 241, 'Reintento de la consulta anterior.', '4 horarios libres: 09:00, 10:30, 15:00 y 16:30.', true],
        [10, 'reply', 'Preparación de la respuesta (simulada)', 'read', 'succeeded', null, 95, 'Redactar la respuesta con los horarios encontrados.', 'Respuesta lista para enviar (el texto no se guarda en esta traza).', false],
    ];

    /**
     * @param  array{status?: ?string}  $filters
     * @return array{ready: bool, simulated: bool, runs: list<array<string, mixed>>, calls: list<array<string, mixed>>, meta: array<string, int>}
     */
    public static function for(Conversation $conversation, WorkspaceSetting $settings, array $filters = []): array
    {
        $first = $conversation->messages()->where('direction', 'in')->orderBy('id')->first();
        $start = ($first?->sent_at ?? $conversation->created_at)->copy();
        $when = fn ($date) => $settings->formatDate($date).' '.$settings->formatTime($date);
        $total = count(self::SCRIPT);

        $calls = [];

        foreach (self::SCRIPT as $index => [$offset, $tool, $label, $kind, $status, $http, $duration, $reason, $result, $retry]) {
            $calls[] = [
                'id' => 'demo-'.($index + 1),
                'step' => $index + 1,
                'steps' => $total,
                'tool' => $tool,
                'label' => $label,
                'kind' => $kind,
                'auth' => 'execution',
                'status' => $status,
                'statusLabel' => config("chatbots.tool_statuses.{$status}"),
                'httpStatus' => $http,
                'reason' => null,
                'reasonText' => $reason,
                'resultText' => $result,
                'durationMs' => $duration,
                'replayed' => false,
                'retryOfPrevious' => $retry,
                'resourceType' => null,
                'resourceId' => null,
                'resultState' => null,
                'notification' => 'none',
                'executionRef' => 'demo-0001',
                'run' => ['status' => 'succeeded', 'attempts' => 1],
                'sourceMessageId' => $first?->id,
                'when' => $when($start->copy()->addSeconds($offset)),
                'summary' => $reason.' '.$result,
            ];
        }

        $calls = array_reverse($calls); // newest first, like the real panel; the step number keeps the story readable

        if ($status = $filters['status'] ?? null) {
            $calls = array_values(array_filter($calls, fn (array $call) => $call['status'] === $status));
        }

        return [
            'ready' => true,
            'simulated' => true,
            'runs' => [[
                'id' => 'demo-run', 'status' => 'succeeded', 'attempts' => 1, 'outcome' => null, 'control' => 'replied', 'delivery' => null, 'workflow' => 'demostración', 'ref' => 'demo-0001',
                'when' => $when($start->copy()->addSecond()), 'durationMs' => 10_000,
            ]],
            'calls' => $calls,
            'meta' => ['current_page' => 1, 'last_page' => 1, 'from' => $calls === [] ? 0 : 1, 'to' => count($calls), 'total' => count($calls)],
        ];
    }
}
