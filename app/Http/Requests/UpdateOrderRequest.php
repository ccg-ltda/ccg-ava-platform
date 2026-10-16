<?php

namespace App\Http\Requests;

use App\Rules\InternationalPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The operational details of an order that may still change (notes and delivery data). Route middleware: `manage-orders`. */
class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:'.config('orders.limits.notes')],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'delivery_city' => ['nullable', 'string', 'max:120'],
            'delivery_reference' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:32', new InternationalPhone],
        ];
    }

    public function attributes(): array
    {
        return ['notes' => 'la nota', 'delivery_address' => 'la dirección', 'recipient_phone' => 'el teléfono de quien recibe'];
    }
}
