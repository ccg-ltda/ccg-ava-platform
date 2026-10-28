<?php

use App\Observability\ApiCallRecorder;
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

// Closes support sessions that went quiet for support.inactivity_minutes (4) and settles outgoing messages nobody confirmed (never «Enviando» forever). Both are idempotent;
// reading or writing a support session also closes an idle one, so a stopped scheduler never keeps a session alive.
ScheduledCommandMonitor::watch(Schedule::command('conversations:maintain')->everyMinute()->withoutOverlapping()->onOneServer(), 'conversations-maintain');

// Plans and queues the confirmations and reminders of appointments (idempotent; needs the queue worker to deliver what it queues).
ScheduledCommandMonitor::watch(Schedule::command('appointments:notify')->everyMinute()->withoutOverlapping()->onOneServer(), 'appointments-notify');

// Queues the due reminders of the Catálogo (emails to the person who wrote them) and recovers the stuck ones. Idempotent; needs the queue worker to deliver them.
ScheduledCommandMonitor::watch(Schedule::command('catalog:send-reminders')->everyMinute()->withoutOverlapping()->onOneServer(), 'catalog-reminders');

// Settles the traces of assistant tool calls that never recorded an outcome (see AgentToolTrace::reconcile; a no-op until its table exists).
ScheduledCommandMonitor::watch(Schedule::command('agent:tools:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer(), 'agent-tools-reconcile');

// Moves free trials whose end date passed to `expired` (idempotent; the server already withdraws the access on every request; no data is touched).
ScheduledCommandMonitor::watch(Schedule::command('subscriptions:expire-trials')->everyTenMinutes()->withoutOverlapping()->onOneServer(), 'trials-expire');

// Looks for public sign-ups that were PAID but not provisioned (read only: it never retries, charges or creates anything). It exits with failure while there is
// one, which the monitor counts and `integrations:diagnose` blocks on; repeating the provisioning (`signup:reconcile --retry`) stays a deliberate act of a person.
ScheduledCommandMonitor::watch(Schedule::command('signup:reconcile')->everyFifteenMinutes()->withoutOverlapping()->onOneServer(), 'signup-reconcile');

// Deletes the redacted API call records older than the retention window (ApiCallRecorder::RETENTION_DAYS). Idempotent.
Schedule::call(fn () => app(ApiCallRecorder::class)->prune())->name('api-calls-prune')->daily();
