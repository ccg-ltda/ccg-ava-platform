<?php

namespace App\Http\Requests;

use App\Integrations\CalendarProviderType;
use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Configuration of an external agenda (Booker...) of the ACTIVE Workspace (taken from the request context, never from the client).
 * Authorization is the `workspace.permission:manage-settings` and `workspace.module:calendar_providers` route middleware; the
 * provider must be one the booking configuration declares and whose integration type describes its own form.
 */
class SaveProviderIntegrationRequest extends FormRequest
{
    public ?Integration $current = null;

    public function authorize(): bool
    {
        $type = $this->type();

        abort_unless($type instanceof CalendarProviderType && in_array($type->key(), array_filter(array_column(config('bookings.providers'), 'integration')), true), 404);

        $this->current = $this->attributes->get('workspace')->integrations()->where('type', $type->key())->first();

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return $this->type()->rules($this->all(), $this->current);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return collect($this->type()->fields())->mapWithKeys(fn (array $field) => [$field['name'] => 'el campo «'.$field['label'].'»'])->all();
    }

    private function type(): ?object
    {
        $key = (string) $this->route('provider');

        return array_key_exists($key, config('integrations.types')) ? app(IntegrationRegistry::class)->get($key) : null;
    }
}
