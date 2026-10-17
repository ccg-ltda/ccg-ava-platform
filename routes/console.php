<?php

use App\Support\ScheduledCommandMonitor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Proof of life of the scheduler for `integrations:diagnose` (a deployment whose scheduler is not running would otherwise look healthy).
Schedule::call(fn () => Cache::put('ava:scheduler:heartbeat', now()->timestamp, 900))->name('scheduler-heartbeat')->everyMinute();

// Picks up chatbot executions a crash left half done (never resends a message: see ChatbotExecutions::recover).
ScheduledCommandMonitor::watch(Schedule::command('executions:recover')->everyMinute()->withoutOverlapping()->onOneServer(), 'executions-recover');

// Plans and queues the confirmations and reminders of appointments (idempotent; needs the queue worker to deliver what it queues).
ScheduledCommandMonitor::watch(Schedule::command('appointments:notify')->everyMinute()->withoutOverlapping()->onOneServer(), 'appointments-notify');

// Settles the traces of assistant tool calls that never recorded an outcome (see AgentToolTrace::reconcile; a no-op until its table exists).
ScheduledCommandMonitor::watch(Schedule::command('agent:tools:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer(), 'agent-tools-reconcile');
