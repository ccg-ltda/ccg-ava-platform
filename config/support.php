<?php

/*
 * The public support chat of CCG ("Chat de Ava" on CCG's public pages). It is NOT a new kind of channel: it is a normal web channel of a chatbot of the
 * administrative Workspace (config `workspace.admin_code`, Desarrollo_DEV), reached through its public key like any other web widget. What is configured here
 * is only WHICH channel is CCG's own, so the application can verify, before showing the button, that the key really belongs to the administrative Workspace.
 *
 * Nothing here is a secret except `notify_secret`; none of it reaches the browser except the public key (which is public by design).
 */
return [
    /*
     * Public key (32 characters) of the web channel that receives CCG's public chat. Create the channel in Asistentes of the administrative Workspace
     * and copy its key here. Empty = the public chat is not offered anywhere (no button is drawn). A key that does not belong to the administrative
     * Workspace is refused and reported (SupportChannel), never used.
     */
    'public_chat_key' => env('CCG_SUPPORT_CHAT_KEY'),

    /*
     * Optional alert to CCG's team (an n8n webhook of the PLATFORM, called by the server only): a new support conversation and a request for a person.
     * No message text, contact data or credentials are sent, only identifiers and the link to the conversation. Empty = no alert leaves Ava; the
     * conversation is in the inbox of the Workspace either way.
     */
    'notify_webhook_url' => env('CCG_SUPPORT_NOTIFY_URL'),
    'notify_secret' => env('CCG_SUPPORT_NOTIFY_SECRET'),
    'notify_timeout' => 10,
];
