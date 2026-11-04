<?php

namespace App\Console\Commands;

use App\Models\AgentToolCall;
use App\Services\AgentToolTrace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent:tools:prune')]
#[Description('Retention of the technical trace of assistant tool calls: the request / response bodies are removed after chatbots.tool_trace_body_retention_days (the metadata stays) and the whole row after chatbots.tool_trace_retention_days. Never touches a call without a recorded outcome nor any business data.')]
class AgentToolsPruneCommand extends Command
{
    public function handle(AgentToolTrace $trace): int
    {
        if (! $trace->ready()) {
            return self::SUCCESS;
        }

        $days = max(30, (int) config('chatbots.tool_trace_retention_days'));
        $bodyDays = min($days, max(7, (int) config('chatbots.tool_trace_body_retention_days')));

        // Phase 1: the bodies go first (they are where customer data can appear); the metadata stays for the longer window.
        $purged = AgentToolCall::query()->where('status', '!=', AgentToolCall::STARTED)->where('created_at', '<', now()->subDays($bodyDays))->whereNull('bodies_purged_at')
            ->where(fn ($q) => $q->whereNotNull('request_body')->orWhereNotNull('response_body')->orWhereNotNull('request_headers')->orWhereNotNull('request_query')->orWhereNotNull('response_headers')->orWhereNotNull('error_message'))
            ->update(['request_body' => null, 'response_body' => null, 'request_headers' => null, 'request_query' => null, 'response_headers' => null, 'error_message' => null, 'bodies_purged_at' => now()]);

        // Phase 2: the whole row.
        $deleted = AgentToolCall::query()->where('status', '!=', AgentToolCall::STARTED)->where('created_at', '<', now()->subDays($days))->delete();

        $this->components->info("{$purged} llamada(s): contenido eliminado (retención {$bodyDays} días). {$deleted} llamada(s) eliminada(s) por completo (retención {$days} días).");

        return self::SUCCESS;
    }
}
