<?php

namespace App\Http\Requests;

use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use App\Payments\Gateways\PaymentGatewayManager;
use App\Payments\Gateways\PaymentGatewayType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Configuration of a payment gateway of the ACTIVE Workspace (taken from the request context, never from the client). Authorization is the
 * `workspace.permission:manage-integrations` route middleware; the gateway must be one Ava registered and whose integration type describes its own form. Whether THIS Workspace
 * may use it (plan, administrative exception) is checked again by IntegrationService::savePaymentGateway.
 */
class SavePaymentGatewayIntegrationRequest extends FormRequest
{
    public ?Integration $current = null;

    public function authorize(): bool
    {
        $type = $this->type();

        abort_unless($type instanceof PaymentGatewayType, 404);

        $this->current = $this->attributes->get('workspace')->integrations()->where('type', $type->key())->first();

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return $this->type()->rules($this->all(), $this->current);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return method_exists($this->type(), 'messages') ? $this->type()->messages() : [];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return collect($this->type()->fields())->mapWithKeys(fn (array $field) => [$field['name'] => 'el campo «'.$field['label'].'»'])->all();
    }

    private function type(): ?object
    {
        $key = (string) $this->route('gateway');

        return app(PaymentGatewayManager::class)->has($key) ? app(IntegrationRegistry::class)->get($key) : null;
    }
}
