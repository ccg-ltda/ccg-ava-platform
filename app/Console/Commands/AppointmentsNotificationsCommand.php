<?php

namespace App\Console\Commands;

use App\Models\AppointmentNotification;
use Illuminate\Console\Command;

/** Lists the planned and sent messages of appointments to diagnose them: ids, states and fixed reasons only (no phone, no text, no names). */
class AppointmentsNotificationsCommand extends Command
{
    protected $signature = 'appointments:notifications {--status= : scheduled|queued|sending|sent|delivered|read|failed|uncertain|skipped|cancelled} {--workspace= : Workspace id} {--hours=48 : planned within the last N hours} {--limit=50}';

    protected $description = 'List confirmations and reminders of appointments and what happened to each (no personal data)';

    public function handle(): int
    {
        $rows = AppointmentNotification::query()
            ->when($this->option('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($this->option('workspace'), fn ($query, $workspace) => $query->where('workspace_id', (int) $workspace))
            ->where('scheduled_for', '>=', now()->subHours((int) $this->option('hours')))
            ->orderByDesc('id')->limit(min(500, max(1, (int) $this->option('limit'))))->get();

        $this->table(['id', 'workspace', 'cita', 'tipo', 'antes (min)', 'estado', 'intentos', 'modo', 'programado (UTC)', 'motivo'], $rows->map(fn (AppointmentNotification $n) => [
            $n->id, $n->workspace_id, $n->appointment_id, $n->kind, $n->offset_minutes, $n->status, $n->attempts, $n->mode ?? '-', $n->scheduled_for->utc()->format('Y-m-d H:i'), $n->reason ?? '-',
        ])->all());

        return self::SUCCESS;
    }
}
