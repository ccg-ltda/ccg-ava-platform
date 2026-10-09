<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The text an agent sends to a contact. Who may send it is decided by the route and by ConversationMessenger. */
class SendConversationMessageRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:'.config('chatbots.agent_message_max')]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['body' => 'mensaje'];
    }
}
