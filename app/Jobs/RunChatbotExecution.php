<?php

namespace App\Jobs;

use App\Chatbots\ChatbotExecutionFailed;
use App\Services\ChatbotExecutions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one `chatbot_executions` row (see ChatbotExecutions). Everything the run needs, and how many times it has run, is
 * in that row, so the job only carries its id: if the queue loses or repeats it, `executions:recover` and the atomic
 * claim make the outcome the same. A temporary failure releases the job with the configured wait; a permanent one fails it
 * with a fixed code, which the queue keeps in `failed_jobs`. Nothing an exception could carry (SQL with the message text,
 * provider output) is allowed to reach the queue's records or the logs: it is replaced by a fixed code.
 */
class RunChatbotExecution implements ShouldQueue
{
    use Queueable;

    /** Attempts of the queue (a release counts as one); the real limit is `n8n.tries`, counted in the execution row. */
    public int $tries = 6;

    /**
     * Seconds a run may take (n8n's wait plus the send to the channel). It must stay below the connection's `retry_after`
     * (and the worker's timeout), or the queue would hand the same job to a second worker while the first still runs.
     */
    public int $timeout = 50;

    public function __construct(public readonly int $executionId) {}

    public function handle(ChatbotExecutions $executions): void
    {
        try {
            $result = $executions->run($this->executionId);
        } catch (Throwable $e) {
            Log::error('chatbot_execution_error', ['exception' => $e::class, 'execution_id' => $this->executionId]);
            $this->fail(new ChatbotExecutionFailed('internal_error'));

            return;
        }

        match ($result->kind) {
            'retry' => $this->release($result->delay),
            'failed' => $this->fail(new ChatbotExecutionFailed($result->code)),
            default => null,
        };
    }
}
