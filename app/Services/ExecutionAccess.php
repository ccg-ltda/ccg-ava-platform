<?php

namespace App\Services;

use App\Models\ChatbotExecution;

/**
 * A short-lived credential that Ava hands to the workflow it starts, so the workflow can use Ava's booking and order tools for THAT
 * conversation without anyone copying a long-lived chatbot token into n8n. It is stateless (an HMAC of the execution id and the
 * expiry with the application key, nothing stored) and it grants less than a chatbot token on purpose:
 *  - it works only while its execution is `running` (a finished, failed or discarded execution makes it useless) AND the AI still has the
 *    conversation exactly as when the execution was queued (`handling_version`): once a person takes it, asks for one or resolves it, the
 *    token stops working, so a workflow that is still running cannot keep booking for a conversation a person now handles;
 *  - it expires (`n8n.run_token_ttl`);
 *  - it works only on the booking and order tools (`agent.booking.*`, `agent.orders.*`) and only for the conversation of its execution: the contact is
 *    taken from the execution, never from the request (see AgentConversationContext).
 * It cannot read the chatbot configuration, report messages, change who answers or reach any other Workspace.
 */
class ExecutionAccess
{
    public const PREFIX = 'avx_';

    /** The only routes a run-scoped token reaches (by route name): the booking and the order tools. */
    public const TOOL_ROUTES = ['agent.booking.', 'agent.orders.'];

    public function issue(ChatbotExecution $execution): string
    {
        $expires = now()->addSeconds((int) config('n8n.run_token_ttl'))->timestamp;

        return self::PREFIX."{$execution->id}.{$expires}.".$this->signature($execution->id, $expires);
    }

    public function expiry(string $token): ?int
    {
        return $this->parse($token)[1] ?? null;
    }

    /** The running execution a token belongs to, or null when it is malformed, forged, expired or its execution is no longer running. */
    public function resolve(string $token): ?ChatbotExecution
    {
        $parsed = $this->parse($token);

        if (! $parsed || $parsed[1] < now()->timestamp || ! hash_equals($this->signature($parsed[0], $parsed[1]), $parsed[2])) {
            return null;
        }

        $execution = ChatbotExecution::with(['conversation', 'chatbot.workspace.organization'])->whereKey($parsed[0])->where('status', ChatbotExecution::RUNNING)->first();

        return $execution && $execution->conversation->aiMayReply() && $execution->conversation->handling_version === $execution->handling_version ? $execution : null;
    }

    /** @return array{0: int, 1: int, 2: string}|null */
    private function parse(string $token): ?array
    {
        return str_starts_with($token, self::PREFIX) && preg_match('/^(\d{1,18})\.(\d{9,12})\.([a-f0-9]{40})$/', substr($token, strlen(self::PREFIX)), $m)
            ? [(int) $m[1], (int) $m[2], $m[3]]
            : null;
    }

    private function signature(int $id, int $expires): string
    {
        return substr(hash_hmac('sha256', "avx|{$id}|{$expires}", (string) config('app.key')), 0, 40);
    }
}
