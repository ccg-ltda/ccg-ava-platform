<?php

namespace App\Http\Requests;

use App\Customers\CustomerSearch;
use App\Http\Requests\Concerns\ValidatesMessageBody;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update of a campaign DRAFT of the ACTIVE Workspace. The message comes from an active template of the same Workspace or is
 * written here; the hand-picked customers must belong to the Workspace (the server re-checks, an id is never trusted).
 */
class SaveCampaignRequest extends FormRequest
{
    use ValidatesMessageBody;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $workspace = $this->attributes->get('workspace');

        return [
            'name' => ['required', 'string', 'max:120'],
            'message_template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('workspace_id', $workspace->id)->where('is_active', true)],
            'body' => ['required', 'string'],
            'channel' => ['required', Rule::in(array_keys(config('campaigns.channels')))],
            'audience' => ['required', Rule::in(['all', 'selected'])],
            'filters' => ['nullable', 'array'],
            'filters.source' => ['nullable', Rule::in(array_keys(config('customers.sources')))],
            'filters.tag' => ['nullable', 'string', 'max:40'],
            'filters.identity_channel' => ['nullable', Rule::in(config('customers.identity_channels'))],
            'filters.created_from' => ['nullable', 'date'],
            'filters.created_to' => ['nullable', 'date', 'after_or_equal:filters.created_from'],
            'customer_ids' => ['nullable', 'array', 'max:'.config('campaigns.max_selected')],
            'customer_ids.*' => ['integer', Rule::exists('customers', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $this->validateMessageBody($validator);
        }];
    }

    public function attributes(): array
    {
        return ['name' => 'el nombre', 'body' => 'el mensaje', 'channel' => 'el canal', 'filters.created_to' => 'la fecha final'];
    }

    /** @return array<string, mixed> */
    public function campaign(): array
    {
        $data = $this->validated();
        $selected = $data['audience'] === 'selected';
        $filters = collect($data['filters'] ?? [])->only(CustomerSearch::AUDIENCE_FILTERS)->filter(fn ($value) => filled($value))->all();

        return [
            'name' => trim($data['name']),
            'message_template_id' => $data['message_template_id'] ?? null,
            'body' => trim($data['body']),
            'channel' => $data['channel'],
            'audience' => $data['audience'],
            // The filters describe "everyone"; a hand-picked list ignores them (and the reverse), so only one of the two is stored.
            'filters' => $selected ? null : ($filters ?: null),
            'customer_ids' => $selected ? array_values(array_unique(array_map('intval', $data['customer_ids'] ?? []))) : null,
        ];
    }
}
