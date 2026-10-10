<?php

/*
 * The platform's own n8n, used as the (provisional) engine that runs a chatbot's workflow. Ava starts every execution
 * and receives the answer in the same HTTP response; n8n never gets Workspace credentials and clients never reach it.
 * The workflows a chatbot may run are registered by the platform team (table `workflow_definitions`). Without
 * `execution_url` and `secret` nothing is run: the message is still stored and shown in the inbox.
 */
return [
    /* Base URL of the n8n webhooks (e.g. https://n8n.example.com/webhook). The path of each workflow is appended. */
    'execution_url' => env('N8N_EXECUTION_URL'),

    /* Shared secret: every request is signed with it (HMAC-SHA256) so the workflow can check it comes from Ava. */
    'secret' => env('N8N_EXECUTION_SECRET'),

    /*
     * Catalog of the workflows that exist in the platform's n8n and may be given to a chatbot (`workflows:manage assign`).
     * Reviewed and deployed with the code: a client can neither add nor change an entry. `webhook_path` is appended to
     * `execution_url` and may only contain letters, digits, `-`, `_` and `/`.
     * `enabled` (default true) switches a workflow off for every chatbot that has it, without touching the assignments.
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
    'max_reply' => 4000,

    /* Longest instructions text sent to the workflow, in characters. */
    'max_instructions' => 8000,
];
