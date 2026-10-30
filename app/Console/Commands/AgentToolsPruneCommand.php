<?php

namespace App\Console\Commands;

use App\Models\AgentToolCall;
use App\Services\AgentToolTrace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent:tools:prune')]
#[Description('Delete the technical trace of assistant tool calls past the retention window (config chatbots.tool_trace_retention_days). Never touches a call without a recorded outcome nor any business data.')]
class AgentToolsPruneCommand extends Command
{
    public function handle(AgentToolTrace $trace): int
    {
        if (! $trace->ready()) {
            return self::SUCCESS;
        }

        $days = max(30, (int) config('chatbots.tool_trace_retention_days'));
        $deleted = AgentToolCall::query()->where('status', '!=', AgentToolCall::STARTED)->where('created_at', '<', now()->subDays($days))->delete();

        $this->components->info("{$deleted} llamada(s) antigua(s) de la actividad técnica eliminada(s) (retención: {$days} días).");

        return self::SUCCESS;
    }
}
