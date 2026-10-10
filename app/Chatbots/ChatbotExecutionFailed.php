<?php

namespace App\Chatbots;

use RuntimeException;

/**
 * An execution that cannot succeed. It carries only a fixed code (never message text, contact data or provider output), so
 * what the queue keeps in `failed_jobs` and what reaches the logs is safe to read.
 */
class ChatbotExecutionFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("chatbot_execution_failed:{$reason}");
    }
}
