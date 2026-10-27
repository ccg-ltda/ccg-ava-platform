<?php

namespace Tests\Feature;

use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

/**
 * The queue strategy of the development stack, checked on the file that defines it: Docker's workers (Horizon) read Redis, and the only consumer of the `database`
 * queue is OPT-IN, so it can never start by itself and run real pending jobs (mails) nobody reviewed.
 */
class QueueStrategyTest extends TestCase
{
    private function compose(): string
    {
        return (string) file_get_contents(base_path('docker-compose.yml'));
    }

    /** The text of one service block (from its key to the next service). */
    private function service(string $name): string
    {
        $this->assertSame(1, preg_match('/^  '.preg_quote($name, '/').':\n(.*?)(?=^  \S|\z)/ms', $this->compose(), $match), "service {$name} exists");

        return $match[1];
    }

    public function test_the_default_worker_is_horizon_on_redis_and_the_stack_enqueues_on_redis(): void
    {
        $this->assertStringContainsString('"horizon"', $this->service('worker'));
        $this->assertStringNotContainsString('profiles', $this->service('worker'));
        $this->assertMatchesRegularExpression('/^    QUEUE_CONNECTION: redis$/m', $this->compose());
    }

    public function test_the_consumer_of_the_database_queue_exists_but_only_starts_when_asked_for(): void
    {
        $worker = $this->service('worker-db');

        $this->assertStringContainsString('profiles: ["host-queue"]', $worker, 'a plain `docker compose up` must not start it');
        $this->assertStringContainsString('"queue:work", "database"', $worker);
        $this->assertStringNotContainsString('--stop-when-empty', $worker, 'it keeps serving the host instance until stopped');
    }

    public function test_the_invitation_mail_is_queued_never_sent_inline(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(WorkspaceInvitationNotification::class));
    }
}
