<?php

namespace App\Chatbots;

/**
 * Whatever runs the workflow of a chatbot (today the platform's n8n; later an engine of our own). Ava decides WHETHER and
 * FOR WHOM an execution runs and what happens with its answer; the executor only delivers the payload and returns an
 * outcome. It must never throw for a failed run: a temporary failure (worth retrying) and a permanent one are told apart.
 */
interface ChatbotExecutor
{
    /**
     * @param  string  $correlationId  identifies this execution (stable across its retries, so the workflow can ignore a repeat)
     * @param  array<string, mixed>  $payload  what the workflow may know (see ChatbotExecutions::payload)
     */
    public function run(ResolvedWorkflow $workflow, string $correlationId, array $payload): ExecutionOutcome;
}
