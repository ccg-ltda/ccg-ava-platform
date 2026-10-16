<?php

namespace App\Http\Requests;

use App\Orders\CommerceSchema;
use App\Rules\InternationalPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Places an order from the admin. It carries ONLY what the person typed: which products and how many. Prices, totals, currency, number, state and the
 * Workspace are never read from here: OrderService takes them from the catalog and the session. Authorization is the `workspace.permission:manage-orders` route middleware.
 */
class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        if (! app(CommerceSchema::class)->ready()) {
            return ['customer_name' => ['required', 'string', 'max:120']];
        }

        $workspace = $this->attributes->get('workspace');
        $limits = config('orders.limits');

        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('workspace_id', $workspace->id)],
            'customer_name' => ['nullable', 'required_without:customer_id', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32', new InternationalPhone],
            'customer_email' => ['nullable', 'email:rfc', 'max:160'],
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

    public function attributes(): array
    {
        return [
            'customer_name' => 'el nombre', 'customer_phone' => 'el teléfono', 'customer_email' => 'el correo', 'delivery_method' => 'la forma de entrega', 'delivery_address' => 'la dirección',
            'recipient_phone' => 'el teléfono de quien recibe', 'notes' => 'la nota', 'lines' => 'los productos', 'lines.*.product_id' => 'el producto', 'lines.*.quantity' => 'la cantidad',
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Añade al menos un producto al pedido.', 'lines.min' => 'Añade al menos un producto al pedido.',
            'customer_name.required_without' => 'Indica el nombre de quien hace el pedido.', 'delivery_address.required_if' => 'Un pedido a domicilio necesita la dirección de entrega.',
        ];
    }

    /** @return array<string, mixed> what OrderService::create takes: no money, no number, no state */
    public function order(): array
    {
        return $this->safe()->only(['customer_id', 'customer_name', 'customer_phone', 'customer_email', 'delivery_method', 'delivery_address', 'delivery_city', 'delivery_reference', 'recipient_name', 'recipient_phone', 'location_id', 'notes', 'lines']);
    }
}
