<?php

namespace App\Http\Requests;

use App\Models\Chatbot;
use App\Services\ChannelAppearanceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saves the look of one channel of a chatbot. Authorization is the `workspace.permission:manage-chatbots` route
 * middleware; the chatbot is looked up INSIDE the active Workspace (another Workspace's id is a 404) and a channel
 * without visual configuration, or not available yet, is a 404 too.
 */
class SaveChannelAppearanceRequest extends FormRequest
{
    public Chatbot $chatbot;

    public string $channel;

    public function authorize(): bool
    {
        $this->chatbot = $this->attributes->get('workspace')->chatbots()->findOrFail($this->route('chatbot'));
        $this->channel = $this->route('channel');
        app(ChannelAppearanceService::class)->kindOrFail($this->channel);

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $service = app(ChannelAppearanceService::class);
        $color = $this->input('primary_color');
        $primary = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $service->defaultPrimary($this->chatbot->workspace, $this->channel);

        return $service->rules($this->channel, $primary);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return collect(app(ChannelAppearanceService::class)->labels())->map(fn (string $label) => mb_strtolower($label))->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'primary_color.regex' => 'El color principal debe tener el formato #RRGGBB.',
            'text_color.regex' => 'El color del texto debe tener el formato #RRGGBB.',
            'radius.between' => 'El redondeo debe estar entre :min y :max.',
            'in' => 'Elige una opción de la lista.',
        ];
    }
}
