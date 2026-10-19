<?php

namespace App\Http\Requests;

use App\Orders\CommerceSchema;
use App\Orders\OrderException;
use App\Rules\InternationalPhone;
use App\Services\AgentConversationContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An order placed by the assistant (Ava Chat) for the contact of a conversation. Like the admin form it carries ONLY what was chosen: which
 * products and how many, how it is delivered and who it is for. Prices, totals, currency, number, state, Workspace and the conversation are
 * never read from here: OrderService takes them from the catalog and the token. Anything else the caller sends (a `total`, a `price`) is ignored.
 */
class AgentCreateOrderRequest extends FormRequest
{
    /** Whose conversation this is is settled before anything is validated: a run-scoped token naming another contact is a 403, not a validation error. */
    public function authorize(): bool
    {
        app(AgentConversationContext::class)->contact($this);

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // Without the tables or the link the controller answers `not_ready` (503); validating against a missing table would fail on the database instead.
        if (! app(CommerceSchema::class)->conversationLinkReady()) {
            return [];
        }

        $workspace = $this->attributes->get('workspace');
        $limits = config('orders.limits');

        return [
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'min:2', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:32', new InternationalPhone(null, app(AgentConversationContext::class)->verifiedPhoneDigits($this))],
            'customer.email' => ['nullable', 'email:rfc', 'max:160'],
            'delivery_method' => ['required', Rule::in(array_keys(config('orders.methods')))],
            'delivery_address' => ['nullable', 'required_if:delivery_method,delivery', 'string', 'max:255'],
            'delivery_city' => ['nullable', 'string', 'max:120'],
            'delivery_reference' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:32', new InternationalPhone],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('workspace_id', $workspace->id)],
            'notes' => ['nullable', 'string', 'max:'.$limits['notes']],
            'lines' => ['required', 'array', 'min:1', 'max:'.$limits['lines']],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('workspace_id', $workspace->id)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$limits['quantity']],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Añade al menos un producto al pedido.', 'lines.min' => 'Añade al menos un producto al pedido.',
            'delivery_address.required_if' => 'Un pedido a domicilio necesita la dirección de entrega.', 'lines.*.product_id.exists' => 'Uno de los productos no existe en el catálogo.',
        ];
    }

    /** @return array<string, mixed> what OrderService::create takes: no money, no number, no state, no Workspace, no conversation */
    public function order(): array
    {
        $customer = $this->validated('customer');
        $verified = app(AgentConversationContext::class)->verifiedPhoneDigits($this);
        $phone = filled($customer['phone'] ?? null) ? trim($customer['phone']) : $verified; // D-TEL: the verified WhatsApp number when the workflow sends none

        return [
            'customer_name' => trim($customer['name']),
            'customer_phone' => $phone !== null && $phone === $verified ? '+'.$phone : $phone,
            'customer_email' => filled($customer['email'] ?? null) ? trim($customer['email']) : null,
        ] + $this->safe()->only(['delivery_method', 'delivery_address', 'delivery_city', 'delivery_reference', 'recipient_name', 'recipient_phone', 'location_id', 'notes', 'lines']);
    }

    /**
     * What identifies THIS order request, so repeating it (n8n retries a call, a webhook arrives twice) returns the order that already exists, and
     * reusing the key for something else is refused. Two digests, never the raw key the caller sent:
     *  - `key`: conversation + the caller's own key (the running execution, or an `Idempotency-Key` header). The conversation is part of it, so the same
     *    header in two conversations can never return the order of another contact;
     *  - `fingerprint`: the CONTENT of the order (every field the assistant chose, products merged and ordered). Same key and same fingerprint is a retry;
     *    same key with another fingerprint is a conflict.
     *
     * @return array{key: string, fingerprint: string}|null null when the caller gave no key at all
     *
     * @throws OrderException when the key is present but unusable
     */
    public function idempotency(int $conversationId): ?array
    {
        $execution = $this->attributes->get('execution');
        $caller = $execution ? 'x'.$execution->id : $this->header('Idempotency-Key');

        if ($caller === null || $caller === '') {
            return null;
        }

        if (! is_string($caller) || strlen($caller) > 128 || preg_match('/[^!-~]/', $caller)) {
            throw new OrderException('La clave de idempotencia debe tener entre 1 y 128 caracteres imprimibles, sin espacios.', 422, 'idempotency_key_invalid');
        }

        return ['key' => hash('sha256', "{$conversationId}|{$caller}"), 'fingerprint' => $this->contentDigest()];
    }

    /** The content of the order as OrderService will store it: a pickup order has no delivery data, the same product twice is one line, line order does not matter. */
    private function contentDigest(): string
    {
        $order = $this->order();
        $pickup = $order['delivery_method'] === 'pickup';
        $text = fn (mixed $value) => ($value = trim((string) $value)) === '' ? null : $value;
        $lines = collect($order['lines'])->groupBy(fn (array $line) => (int) $line['product_id'])->map(fn ($group) => $group->sum(fn (array $line) => (int) $line['quantity']))->sortKeys()->all();

        return hash('sha256', json_encode([
            'lines' => $lines,
            'method' => $order['delivery_method'],
            'location' => $order['location_id'] ?? null,
            'name' => $text($order['customer_name']),
            'phone' => $text($order['customer_phone'] ?? null),
            'email' => $text($order['customer_email'] ?? null),
            'notes' => $text($order['notes'] ?? null),
            'address' => $pickup ? null : $text($order['delivery_address'] ?? null),
            'city' => $pickup ? null : $text($order['delivery_city'] ?? null),
            'reference' => $pickup ? null : $text($order['delivery_reference'] ?? null),
            'recipient' => $pickup ? null : $text($order['recipient_name'] ?? null),
            'recipient_phone' => $pickup ? null : $text($order['recipient_phone'] ?? null),
        ], JSON_UNESCAPED_UNICODE));
    }
}
