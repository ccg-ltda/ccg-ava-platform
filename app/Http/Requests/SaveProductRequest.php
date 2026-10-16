<?php

namespace App\Http\Requests;

use App\Booking\Locations;
use App\Orders\CommerceSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create / update of a product of the ACTIVE Workspace. Authorization is the `workspace.permission:manage-catalog` route middleware. */
class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // Before the migration there are no tables to validate against: the controller then refuses the change with a clear message.
        if (! app(CommerceSchema::class)->ready()) {
            return ['name' => ['required', 'string', 'max:160']];
        }

        $workspace = $this->attributes->get('workspace');
        $current = $this->route('product');

        return [
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/', Rule::unique('products', 'sku')->where('workspace_id', $workspace->id)->ignore($current)],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0', 'max:'.config('orders.limits.price'), 'decimal:0,2'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (filled($this->input('location_ids')) && ! app(Locations::class)->enabled($this->attributes->get('workspace'))) {
                $validator->errors()->add('location_ids', 'El plan de este Workspace no incluye Sedes.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['sku' => 'el SKU', 'name' => 'el nombre', 'description' => 'la descripción', 'category' => 'la categoría', 'price' => 'el precio'];
    }

    public function messages(): array
    {
        return [
            'sku.regex' => 'El SKU solo puede llevar letras, números, punto, guion, guion bajo y barra.',
            'sku.unique' => 'Ya existe un producto con ese SKU en este Workspace.',
        ];
    }

    /** @return array<string, mixed> the columns of the product */
    public function product(): array
    {
        $data = $this->validated();
        $clean = fn (string $key) => filled($data[$key] ?? null) ? trim($data[$key]) : null;

        return ['sku' => $clean('sku'), 'name' => trim($data['name']), 'description' => $clean('description'), 'category' => $clean('category'), 'price' => $data['price']];
    }

    /** @return list<int> */
    public function locationIds(): array
    {
        return array_map('intval', $this->validated('location_ids') ?? []);
    }
}
