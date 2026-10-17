<?php

namespace App\Services;

use App\Models\Conversation;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Which conversation an assistant tool call (booking, orders) is about. One rule for every tool, so none can drift from it:
 *  - with a run-scoped token the conversation is the one of the execution and the caller cannot name another contact;
 *  - with a chatbot token the caller names `channel` + `contact_id`, validated as text and never trusted as an id of ours, and the conversation
 *    must belong to THAT chatbot (so a contact can only reach what was made from their own conversation).
 */
class AgentConversationContext
{
    /** @return array{channel: string, contact_id: string} */
    public function contact(Request $request): array
    {
        if ($execution = $request->attributes->get('execution')) {
            $conversation = $execution->conversation;
            abort_if(($request->input('channel') !== null && $request->input('channel') !== $conversation->channel)
                || ($request->input('contact_id') !== null && (string) $request->input('contact_id') !== $conversation->contact_id), 403, 'Este token solo puede actuar en la conversación de su ejecución.');

            return ['channel' => $conversation->channel, 'contact_id' => $conversation->contact_id];
        }

        return $request->validate(['channel' => ['required', 'string', 'max:32'], 'contact_id' => ['required', 'string', 'max:64']]);
    }

    /** The conversation of the request's chatbot with that contact, or null when there is none. @param  array{channel: string, contact_id: string}  $contact */
    public function conversation(Request $request, array $contact): ?Conversation
    {
        return $request->attributes->get('chatbot')->conversations()->where('channel', $contact['channel'])->where('contact_id', $contact['contact_id'])->first();
    }

    /**
     * The id of a WhatsApp conversation is a full international number that Meta writes without the "+": a phone equal to THAT conversation's id is
     * international even without it. Only for the assistant's tools; nothing else infers a country. Null when the contact is not a WhatsApp number.
     */
    public function verifiedPhoneDigits(Request $request): ?string
    {
        if (! $request->attributes->has('chatbot')) {
            return null;
        }

        $execution = $request->attributes->get('execution');
        $channel = $execution ? $execution->conversation->channel : $request->input('channel');
        $contact = $execution ? $execution->conversation->contact_id : $request->input('contact_id');

        return $channel === 'whatsapp' && is_string($contact) && PhoneNumber::e164('+'.$contact) !== null ? $contact : null;
    }
}
