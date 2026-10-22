<?php

namespace Tests\Feature;

use App\Support\ScheduledCommandMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A scheduled command that fails must leave evidence. Before, the scheduler discarded the output of each command: when appointments:notify failed once
 * ("failed with exit code 1") its real cause was lost and nobody could tell a command that did not run from one that failed. These tests fix the contract:
 * the last output is kept, a success is timestamped, a failure is counted and its output saved, and the diagnosis tells the three situations apart.
 */
class ScheduledCommandMonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();

        // The monitor writes under storage/logs: use a private folder so the development logs are never touched.
        $this->originalStorage = $this->app->storagePath();
        $this->storage = sys_get_temp_dir().'/ava-monitor-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/logs', 0777, true);
        $this->app->useStoragePath($this->storage);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage.'/logs/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storage.'/logs');
        @rmdir($this->storage);
        $this->app->useStoragePath($this->originalStorage);

        parent::tearDown();
    }

    private function event(string $needle)
    {
        return collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, $needle));
    }

    private function diagnose(): string
    {
        Artisan::call('integrations:diagnose');

        return Artisan::output();
    }

    public function test_every_watched_command_keeps_its_last_output_and_reports_its_result(): void
    {
        foreach (['executions:recover' => 'executions-recover', 'appointments:notify' => 'appointments-notify', 'catalog:send-reminders' => 'catalog-reminders', 'agent:tools:reconcile' => 'agent-tools-reconcile', 'signup:reconcile' => 'signup-reconcile', 'subscriptions:expire-trials' => 'trials-expire'] as $command => $name) {
            $event = $this->event($command);

            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertStringEndsWith("scheduler-last-{$name}.log", (string) $event->output, 'its output is no longer sent to /dev/null');
        }
        $this->assertSame(['executions-recover', 'appointments-notify', 'catalog-reminders', 'agent-tools-reconcile', 'signup-reconcile', 'trials-expire'], array_keys(ScheduledCommandMonitor::WATCHED));
    }

    public function test_a_success_is_timestamped_and_a_failure_is_counted_and_keeps_the_real_cause(): void
    {
        $event = $this->event('appointments:notify');
        file_put_contents(ScheduledCommandMonitor::lastOutputPath('appointments-notify'), "Illuminate\\Database\\QueryException: SQLSTATE[42P01]: relation missing\n".str_repeat('x', 6000));
        Log::spy();

        $event->finish($this->app, 0);
        $ok = ScheduledCommandMonitor::status('appointments-notify');
        $this->assertNotNull($ok['last_ok']);
        $this->assertSame([null, 0], [$ok['last_failure'], $ok['failures_24h']]);

        $this->travel(2)->minutes();
        $event->finish($this->app, 1);
        $failed = ScheduledCommandMonitor::status('appointments-notify');

        $this->assertSame(1, $failed['failures_24h']);
        $this->assertGreaterThan($failed['last_ok'], $failed['last_failure'], 'failing NOW: the last failure is newer than the last success');
        $log = file_get_contents($this->storage.'/'.ScheduledCommandMonitor::FAILURE_LOG);
        $this->assertStringContainsString('appointments-notify', $log);
        $this->assertStringContainsString('relation missing', $log, 'the cause the child process printed is no longer lost');
        $this->assertLessThan(4300, strlen($log), 'the output is cut so one failure cannot flood the log');
        Log::shouldHaveReceived('error')->with('scheduled_command_failed', ['command' => 'appointments-notify'])->once();
    }

    public function test_the_failure_log_is_rotated_instead_of_growing_forever(): void
    {
        $path = $this->storage.'/'.ScheduledCommandMonitor::FAILURE_LOG;
        file_put_contents($path, str_repeat('a', 1_100_000));

        ScheduledCommandMonitor::failed('executions-recover');

        $this->assertFileExists($path.'.1');
        $this->assertLessThan(1000, filesize($path));
    }

    public function test_the_diagnosis_tells_apart_failing_not_running_recovered_and_healthy(): void
    {
        $now = now()->timestamp;

        // Never ran (just started): a warning, not a verdict.
        $this->assertStringContainsString('sin ninguna ejecución correcta registrada', $this->diagnose());

        // Healthy.
        foreach (array_keys(ScheduledCommandMonitor::WATCHED) as $name) {
            Cache::put("ava:scheduler:ok:{$name}", $now - 30);
        }
        $healthy = $this->diagnose();
        $this->assertStringContainsString('appointments-notify: último éxito hace 0 min', $healthy);
        $this->assertStringNotContainsString('FALLA ahora', $healthy);

        // It did not run: the last success is old and there is no failure.
        Cache::put('ava:scheduler:ok:appointments-notify', $now - 20 * 60);
        $this->assertStringContainsString('appointments-notify: NO SE EJECUTA desde hace 20 min', $this->diagnose());

        // It ran and failed after its last success.
        Cache::put('ava:scheduler:ok:appointments-notify', $now - 120);
        Cache::put('ava:scheduler:failures:appointments-notify', [$now - 60]);
        $failing = $this->diagnose();
        $this->assertStringContainsString('appointments-notify: FALLA ahora', $failing);
        $this->assertStringContainsString('scheduler-failures.log', $failing);

        // It failed once and then recovered.
        Cache::put('ava:scheduler:ok:appointments-notify', $now - 10);
        $recovered = $this->diagnose();
        $this->assertStringContainsString('1 falla(s) en 24 h, ya recuperada(s)', $recovered);
        $this->assertStringNotContainsString('FALLA ahora', $recovered);
    }

    public function test_in_production_a_command_that_is_failing_or_not_running_blocks(): void
    {
        $now = now()->timestamp;
        foreach (array_keys(ScheduledCommandMonitor::WATCHED) as $name) {
            Cache::put("ava:scheduler:ok:{$name}", $now - 30);
        }
        Cache::put('ava:scheduler:failures:executions-recover', [$now]);
        Cache::put('ava:scheduler:ok:executions-recover', $now - 90);
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call('integrations:diagnose'));
        $this->assertStringContainsString('executions-recover: FALLA ahora', Artisan::output());
    }

    public function test_the_diagnosis_prints_no_secret_and_no_private_data(): void
    {
        Cache::put('ava:scheduler:failures:appointments-notify', [now()->timestamp]);
        file_put_contents(ScheduledCommandMonitor::lastOutputPath('appointments-notify'), 'SQLSTATE: phone 573001112233 token EAAB-SECRET');

        $output = $this->diagnose();

        $this->assertStringNotContainsString('573001112233', $output);
        $this->assertStringNotContainsString('EAAB-SECRET', $output, 'the report names the log file; it never prints its content');
    }

    public function test_the_monitor_never_breaks_the_command_it_observes(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache down'));

        ScheduledCommandMonitor::succeeded('appointments-notify');
        ScheduledCommandMonitor::failed('appointments-notify');

        $this->addToAssertionCount(1);
    }
}
