<?php

namespace App\Http\Requests;

use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Connects (or changes) the n8n instance of the ACTIVE Workspace, taken from the request context and never from the
 * client. Authorization is the `workspace.permission:manage-settings` route middleware.
 */
class SaveN8nIntegrationRequest extends FormRequest
{
    public ?Integration $current = null;

    public function authorize(): bool
    {
        $this->current = $this->attributes->get('workspace')->integrations()->where('type', 'n8n')->first();

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return app(IntegrationRegistry::class)->get('n8n')->rules($this->all(), $this->current);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['base_url' => 'la URL de tu n8n', 'api_key' => 'la API Key de n8n'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'base_url.required' => 'Escribe la dirección de tu n8n.',
            'base_url.url' => 'Escribe la dirección completa de tu n8n, por ejemplo https://miempresa.app.n8n.cloud',
        ];
    }
}
