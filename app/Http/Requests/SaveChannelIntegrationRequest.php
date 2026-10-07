<?php

namespace App\Http\Requests;

use App\Integrations\ChannelIntegrationType;
use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use App\Services\ChannelCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Configuration of a channel's integration in the ACTIVE Workspace (taken from the request context, never from
 * the client). Authorization is the `workspace.permission:manage-settings` route middleware; the channel must
 * exist, be available and use an integration (a channel without credentials has nothing to configure).
 */
class SaveChannelIntegrationRequest extends FormRequest
{
    public ?Integration $current = null;

    public function authorize(): bool
    {
        $channel = app(ChannelCatalog::class)->get($this->route('channel'));

        abort_unless($channel['available'] && $channel['integration'], 404);

        $this->current = $this->attributes->get('workspace')->integrations()->where('type', $channel['integration'])->first();

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $channel = app(ChannelCatalog::class)->get($this->route('channel'));

        return app(IntegrationRegistry::class)->get($channel['integration'])->rules($this->all(), $this->current);
    }

    /** @return array<string, string> the labels of the fields the integration type itself declares */
    public function attributes(): array
    {
        $channel = app(ChannelCatalog::class)->get($this->route('channel'));
        $type = app(IntegrationRegistry::class)->get($channel['integration']);

        return $type instanceof ChannelIntegrationType
            ? collect($type->fields())->mapWithKeys(fn (array $field) => [$field['name'] => 'el campo «'.$field['label'].'»'])->all()
            : [];
    }
}
