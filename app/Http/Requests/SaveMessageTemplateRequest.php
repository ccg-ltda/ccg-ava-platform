<?php

namespace App\Http\Requests;

use App\Campaigns\MessageTemplates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create / update of a message template of the ACTIVE Workspace. The body may only use variables Ava knows. */
class SaveMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $workspace = $this->attributes->get('workspace');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('message_templates', 'name')->where('workspace_id', $workspace->id)->ignore($this->route('template'))],
            'category' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:'.config('campaigns.body_max')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $unknown = app(MessageTemplates::class)->unknown((string) $this->input('body'));

            if ($unknown !== []) {
                $validator->errors()->add('body', 'Variables que no existen: '.collect($unknown)->map(fn ($v) => '{{'.$v.'}}')->implode(', ').'. Usa: '.collect(array_keys(config('campaigns.variables')))->map(fn ($v) => '{{'.$v.'}}')->implode(', ').'.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['name' => 'el nombre', 'body' => 'el mensaje', 'category' => 'la categoría'];
    }

    /** @return array<string, mixed> */
    public function template(): array
    {
        $data = $this->validated();

        return ['name' => trim($data['name']), 'category' => filled($data['category'] ?? null) ? trim($data['category']) : null, 'body' => trim($data['body'])];
    }
}
