<?php

namespace App\Console\Commands;

use App\Services\AgentToolTrace;
use Illuminate\Console\Command;

/**
 * Settles the traces of tool calls that never recorded an outcome (the process ended after the operation and before the trace could say so). An order
 * request is settled by what the database proves; anything else becomes `uncertain`. Prints only counts. Safe to repeat; a no-op until the table exists.
 */
class AgentToolsReconcileCommand extends Command
{
    protected $signature = 'agent:tools:reconcile {--minutes=5 : only calls that started more than this many minutes ago}';

    protected $description = 'Settle the traces of assistant tool calls that never recorded an outcome';

    public function handle(AgentToolTrace $trace): int
    {
        if (! $trace->ready()) {
            $this->line('La tabla agent_tool_calls no existe todavía: nada que conciliar.');

            return self::SUCCESS;
        }

        $counts = $trace->reconcile(max(1, (int) $this->option('minutes')));
        $this->line("Conciliadas: {$counts['succeeded']} hechas, {$counts['failed']} fallidas, {$counts['uncertain']} inciertas.");

        return self::SUCCESS;
    }
}
