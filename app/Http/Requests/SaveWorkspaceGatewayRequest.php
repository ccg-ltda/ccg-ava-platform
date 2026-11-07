<?php

namespace App\Http\Requests;

use App\Models\WorkspacePaymentGatewayOverride;
use App\Payments\Gateways\PaymentGatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One administrative exception over the plan: grant, revoke or go back to what the plan says. Granting or revoking needs a written reason. */
class SaveWorkspaceGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => ($reason = trim((string) $this->input('reason'))) === '' ? null : $reason]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'gateway' => ['required', 'string', Rule::in(app(PaymentGatewayManager::class)->keys())],
            'mode' => ['required', 'string', Rule::in([WorkspacePaymentGatewayOverride::GRANT, WorkspacePaymentGatewayOverride::REVOKE, 'clear'])],
            'reason' => ['nullable', 'required_unless:mode,clear', 'string', 'min:5', 'max:255'],
        ];
    }
}
