<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes a scheduled command observable. Before, the scheduler ran each command with its output sent to /dev/null: when one failed, Laravel only
 * logged "failed with exit code 1" and the real cause (the exception the child process printed) was lost for good. Now each watched command:
 *  - keeps the output of its LAST run in `storage/logs/scheduler-last-<name>.log` (overwritten each minute: it never grows);
 *  - on success stores when it last succeeded (`ava:scheduler:ok:<name>`), which is what tells "it did not run" apart from "it ran and failed";
 *  - on failure appends that output (cut to 4 KB) to `storage/logs/scheduler-failures.log`, counts the failure for 24 h and logs `scheduled_command_failed`
 *    with the command name only. The output of these commands is counts and, on failure, the exception the framework printed (the same text the
 *    framework would log for any other exception); the files stay on the server and are never shown in the interface.
 * `integrations:diagnose` reads these facts. Nothing here retries or changes what a command does: the commands are idempotent and run again on the next tick.
 */
class ScheduledCommandMonitor
{
    public const FAILURE_LOG = 'logs/scheduler-failures.log';

    private const MAX_OUTPUT = 4000;

    private const MAX_LOG_BYTES = 1_000_000;

    /** The commands that are watched and how often each must run (minutes): the single list the schedule and the diagnosis share. */
    public const WATCHED = ['executions-recover' => 1, 'appointments-notify' => 1, 'catalog-reminders' => 1, 'agent-tokens-notify' => 60, 'webhooks-sweep' => 1, 'webhooks-prune' => 1440, 'agent-tools-reconcile' => 5, 'agent-tools-prune' => 1440, 'reminders-dispatch' => 1, 'signup-reconcile' => 15, 'trials-expire' => 10, 'conversations-maintain' => 1];

    public static function watch(Event $event, string $name): Event
    {
        return $event->sendOutputTo(self::lastOutputPath($name))
            ->onSuccess(fn () => self::succeeded($name))
            ->onFailure(fn () => self::failed($name));
    }

    public static function lastOutputPath(string $name): string
    {
        return storage_path("logs/scheduler-last-{$name}.log");
    }

    public static function succeeded(string $name): void
    {
        try {
            Cache::put("ava:scheduler:ok:{$name}", now()->timestamp, 7 * 86400);
        } catch (Throwable) {
            // Observability must never break the command it observes.
        }
    }

    public static function failed(string $name): void
    {
        try {
            $now = now()->timestamp;
            $recent = array_values(array_filter((array) Cache::get("ava:scheduler:failures:{$name}", []), fn ($at) => $now - (int) $at < 86400));
            $recent[] = $now;
            Cache::put("ava:scheduler:failures:{$name}", array_slice($recent, -50), 2 * 86400);
            Log::error('scheduled_command_failed', ['command' => $name]);
            self::appendFailure($name, $now);
        } catch (Throwable) {
            // Same rule: never let the observer fail the scheduler.
        }
    }

    /** @return array{last_ok: ?int, last_failure: ?int, failures_24h: int, every: int} */
    public static function status(string $name): array
    {
        $now = now()->timestamp;
        $failures = array_values(array_filter((array) Cache::get("ava:scheduler:failures:{$name}", []), fn ($at) => $now - (int) $at < 86400));
        $ok = Cache::get("ava:scheduler:ok:{$name}");

        return ['last_ok' => $ok === null ? null : (int) $ok, 'last_failure' => $failures === [] ? null : max($failures), 'failures_24h' => count($failures), 'every' => self::WATCHED[$name] ?? 1];
    }

    private static function appendFailure(string $name, int $at): void
    {
        $path = storage_path(self::FAILURE_LOG);

        if (is_file($path) && filesize($path) > self::MAX_LOG_BYTES) {
            @rename($path, $path.'.1'); // one rotated copy: the log never grows without bound
        }

        $output = is_file(self::lastOutputPath($name)) ? (string) file_get_contents(self::lastOutputPath($name)) : '(sin salida)';
        file_put_contents($path, '['.date('Y-m-d H:i:s', $at)." UTC] {$name}\n".mb_substr($output, 0, self::MAX_OUTPUT)."\n\n", FILE_APPEND | LOCK_EX);
    }
}
