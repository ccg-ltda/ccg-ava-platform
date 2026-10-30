<?php

namespace App\Console\Commands;

use App\Reminders\ReminderDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('reminders:dispatch')]
#[Description('Queue the commercial reminders (module Recordatorios) whose time has come and close the ones a dead worker left half sent. Never touches Agenda notifications.')]
class RemindersDispatchCommand extends Command
{
    public function handle(ReminderDispatcher $dispatcher): int
    {
        if (! Schema::hasTable('reminders')) {
            return self::SUCCESS;
        }

        $result = $dispatcher->dispatchDue();
        $this->components->info("{$result['queued']} recordatorio(s) encolado(s); {$result['interrupted']} interrumpido(s) cerrado(s) como sin confirmar.");

        return self::SUCCESS;
    }
}
