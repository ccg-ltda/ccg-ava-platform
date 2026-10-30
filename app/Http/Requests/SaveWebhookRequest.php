<?php

namespace App\Http\Requests;

use App\Models\WebhookEndpoint;
use App\Webhooks\Webhooks;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or edits a webhook of the ACTIVE Workspace (taken from the request context, never from the client). Authorization is the `workspace.permission:manage-webhooks`
 * route middleware. The destination is judged for safety by WebhookService (the same check runs again before every delivery); `events` can only be names of the catalog.
 */
class SaveWebhookRequest extends FormRequest
{
    public ?WebhookEndpoint $current = null;

    public function authorize(): bool
    {
        $id = $this->route('webhook');

        if ($id !== null) {
            $this->current = $this->attributes->get('workspace')->webhookEndpoints()->findOrFail($id);
        }

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $workspace = $this->attributes->get('workspace');
        // Without the tables (a database not migrated yet) the service answers with a clear message instead of a query error.
        $unique = Webhooks::ready() ? [Rule::unique('webhook_endpoints', 'name')->where('workspace_id', $workspace->id)->ignore($this->current?->id)] : [];

        return [
            'name' => ['required', 'string', 'max:'.config('webhooks.limits.name_max'), ...$unique],
            'url' => ['required', 'string', 'max:'.config('webhooks.limits.url_max'), 'url:http,https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys((array) config('webhooks.events')))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'el nombre', 'url' => 'la dirección de destino', 'events' => 'los eventos'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya tienes un webhook con ese nombre.',
            'url.required' => 'La dirección de destino es obligatoria.',
            'url.url' => 'Escribe la dirección completa, por ejemplo https://mi-sistema.com/webhooks/ava',
            'events.required' => 'Elige al menos un evento.',
            'events.min' => 'Elige al menos un evento.',
            'events.*.in' => 'Uno de los eventos elegidos no existe.',
        ];
    }
}
