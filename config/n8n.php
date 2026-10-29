<?php

/*
 * How Ava runs a chatbot's workflow. Ava starts every execution and receives the answer in the same HTTP response; n8n never gets Workspace
 * credentials. Each Workspace runs its conversations on ITS OWN n8n connection (Integraciones > n8n: instance + execution secret, App\Chatbots\N8nConnection);
 * nothing here is shared between Workspaces. The workflows a chatbot may run are the platform team's catalog below (`workflows`, in code; there is no table).
 * Without a connection nothing is run: the message is still stored and shown in the inbox, and a person answers.
 */
return [
    /*
     * TRANSITION ONLY: the platform's own n8n through the environment (N8N_EXECUTION_URL / N8N_EXECUTION_SECRET), the way it worked before each Workspace had its connection.
     * `legacy_environment` says who may still use it when the Workspace has no connection of its own: `administrative` (only Desarrollo_DEV, the default), `all` (every Workspace
     * without its own connection: the old behavior) or `none`. A Workspace that has its own connection is never served by these variables.
     */
    'legacy_environment' => 'administrative',

    /* Legacy: base URL of the n8n webhooks (e.g. https://n8n.example.com/webhook). The path of each workflow is appended. */
    'execution_url' => env('N8N_EXECUTION_URL'),

    /* Legacy: shared secret, every request is signed with it (HMAC-SHA256) so the workflow can check it comes from Ava. Each Workspace's own connection carries its own. */
    'secret' => env('N8N_EXECUTION_SECRET'),

    /*
     * Catalog of the workflows that exist in the platform's n8n and may be given to a chatbot (`workflows:manage assign`).
     * Reviewed and deployed with the code: a client can neither add nor change an entry. `webhook_path` is appended to
     * `execution_url` and may only contain letters, digits, `-`, `_` and `/`.
     * `enabled` (default true) switches a workflow off for every chatbot that has it, without touching the assignments.
     * `workspaces` publishes it: a list of Workspace codes that may use it, or ['*'] for all. Without it the workflow is reserved to the administrative Workspace.
     * The webhook path must exist in the n8n of each Workspace that uses it.
     * Example: 'soporte' => ['name' => 'Soporte general', 'n8n_workflow_id' => 'AbC123', 'webhook_path' => 'ava/soporte-v1', 'version' => '1', 'enabled' => true],
     */
    'workflows' => [],

    /* Seconds Ava waits for the workflow's answer. */
    'timeout' => (int) env('N8N_EXECUTION_TIMEOUT', 25),

    /* Runs of one execution (the first and its retries after a temporary failure) and the wait before each retry, in seconds. */
    'tries' => 3,
    'backoff' => [10, 60],

    /* An inbound message older than this (minutes) is no longer answered automatically: the contact has moved on. */
    'max_age_minutes' => 10,

    /* Messages of the conversation sent to the workflow as context, and the longest reply accepted (WhatsApp allows 4096). */
    'history_messages' => 10,

    /* Messages sent as history while the conversation has just come back from a person (what the agent asked and the customer answered must be inside). */
    'resume_history_messages' => 40,

    'max_reply' => 4000,

    /* Seconds the run-scoped token given to a workflow (see App\Services\ExecutionAccess) stays valid; it also dies when the execution ends. */
    'run_token_ttl' => 600,

    /* Longest instructions text sent to the workflow, in characters. */
    'max_instructions' => 8000,
];
