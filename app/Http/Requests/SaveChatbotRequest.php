<?php

namespace App\Http\Requests;

use App\Models\Chatbot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / update of a chatbot. Authorization is the `workspace.permission:manage-chatbots` route middleware; the
 * chatbot being edited is looked up INSIDE the active Workspace (another Workspace's id is a 404).
 */
class SaveChatbotRequest extends FormRequest
{
    public ?Chatbot $current = null;

    public function authorize(): bool
    {
        if ($this->route('chatbot') !== null) {
            $this->current = $this->attributes->get('workspace')->chatbots()->findOrFail($this->route('chatbot'));
        }

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $workspace = $this->attributes->get('workspace');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('chatbots', 'name')->where('workspace_id', $workspace->id)->ignore($this->current?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:'.config('chatbots.instructions_max')],
            'avatar' => ['nullable', 'image', 'mimes:'.implode(',', config('chatbots.avatar.mimes')), 'max:'.config('chatbots.avatar.max_kb')],
            'remove_avatar' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'description' => 'la descripción',
            'instructions' => 'las instrucciones',
            'avatar' => 'el avatar',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un chatbot con ese nombre en este Workspace.',
            'avatar.max' => 'El avatar no puede pesar más de 1 MB.',
            'avatar.image' => 'El avatar debe ser una imagen PNG, JPG o WebP.',
            'avatar.mimes' => 'El avatar debe ser una imagen PNG, JPG o WebP.',
        ];
    }
}
