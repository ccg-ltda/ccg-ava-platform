<?php

namespace App\Console\Commands;

use App\Reminders\ReminderRuleScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('reminders:schedule-rules')]
#[Description('Schedule one reminder per customer for every service Agenda marked as done and an active automatic rule covers. Idempotent; only reads Agenda.')]
class RemindersScheduleRulesCommand extends Command
{
    public function handle(ReminderRuleScheduler $scheduler): int
    {
        if (! Schema::hasTable('reminder_rules')) {
            return self::SUCCESS;
        }

        $result = $scheduler->scan();
        $this->components->info("{$result['scheduled']} recordatorio(s) programado(s); {$result['skipped']} cita(s) omitida(s); {$result['cancelled']} cancelado(s) por cambios en el origen.");

        return self::SUCCESS;
    }
}
