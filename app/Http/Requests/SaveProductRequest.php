<?php

namespace App\Http\Requests;

use App\Booking\Locations;
use App\Models\ProductCategory;
use App\Orders\CommerceSchema;
use App\Services\LocationScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update of a product of the ACTIVE Workspace. Authorization is the `workspace.permission:create-products` / `edit-products` route middleware.
 * The category is chosen from the Workspace's own list (`category_id`); the stock is not part of an update (it changes only through the inventory movements) and
 * the picture is optional. Before the inventory migration is applied the form keeps working with the text category it always had.
 */
class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $schema = app(CommerceSchema::class);

        // Before the migration there are no tables to validate against: the controller then refuses the change with a clear message.
        if (! $schema->ready()) {
            return ['name' => ['required', 'string', 'max:160']];
        }

        $workspace = $this->attributes->get('workspace');
        $current = $this->route('product');
        $rules = [
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/', Rule::unique('products', 'sku')->where('workspace_id', $workspace->id)->ignore($current)],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:'.config('orders.limits.price'), 'decimal:0,2'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('workspace_id', $workspace->id)],
        ];

        if (! $schema->inventoryReady()) {
            return $rules + ['category' => ['nullable', 'string', 'max:80']];
        }

        return $rules + [
            // A valid category of THIS Workspace (an id of another Workspace, or an inactive one, is refused).
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('workspace_id', $workspace->id)->where('is_active', true)],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:'.config('orders.limits.price'), 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'tracks_inventory' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:'.implode(',', config('orders.image.mimes')), 'max:'.config('orders.image.max_kb')],
            'remove_image' => ['sometimes', 'boolean'],
            // Only a NEW product carries its first stock; later quantities change through entries and adjustments.
            'stocks' => ['nullable', 'array', 'max:'.config('orders.inventory.max_rows')],
            'stocks.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('workspace_id', $workspace->id)],
            'stocks.*.quantity' => ['required', 'integer', 'min:0', 'max:'.config('orders.inventory.max_quantity')],
            'stocks.*.min_quantity' => ['nullable', 'integer', 'min:0', 'max:'.config('orders.inventory.max_quantity')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $workspace = $this->attributes->get('workspace');
            $ids = collect((array) $this->input('location_ids', []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values();

            if ($ids->isNotEmpty() && ! app(Locations::class)->enabled($workspace)) {
                $validator->errors()->add('location_ids', 'El plan de este Workspace no incluye Sedes.');
            }

            // A product is offered in at least one ACTIVE location of this Workspace whenever the Workspace uses locations (a previously assigned location that
            // was switched off may stay). Without locations there is nothing to assign: the stock is the general one.
            $usesLocations = app(Locations::class)->enabled($workspace) && $workspace->locations()->where('is_active', true)->exists();

            if ($usesLocations) {
                $kept = $this->route('product') ? DB::table('location_product')->where('product_id', $this->route('product'))->pluck('location_id')->map(fn ($id) => (int) $id) : collect();
                $active = $workspace->locations()->where('is_active', true)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);

                if ($active->isEmpty()) {
                    $validator->errors()->add('location_ids', 'Elige al menos una sede activa donde se ofrece el producto.');
                } elseif ($ids->diff($active)->diff($kept)->isNotEmpty()) {
                    $validator->errors()->add('location_ids', 'Una de las sedes elegidas no está activa.');
                }
            }

            // A member limited to selected locations can only offer a product in their own, and only start stock there.
            $scope = app(LocationScope::class);

            if ($scope->restricted($workspace, $this->user())) {
                $kept = $this->route('product') ? DB::table('location_product')->where('product_id', $this->route('product'))->pluck('location_id')->map(fn ($id) => (int) $id) : collect();

                if ($ids->diff($scope->allowedIds($workspace, $this->user()))->diff($kept)->isNotEmpty()) {
                    $validator->errors()->add('location_ids', 'No tienes acceso a una de las sedes elegidas.');
                }

                if (collect($this->input('stocks', []))->pluck('location_id')->contains(fn ($id) => ! $scope->allows($workspace, $this->user(), filled($id) ? (int) $id : null))) {
                    $validator->errors()->add('stocks', 'No tienes acceso a una de las sedes de las existencias.');
                }
            }

            // A free-text category is not a valid one any more: the category is chosen from the Workspace's list.
            if (app(CommerceSchema::class)->inventoryReady() && filled($this->input('category')) && ! $this->filled('category_id')) {
                $validator->errors()->add('category_id', 'Elige una categoría de la lista. Las categorías nuevas se crean en la pestaña «Categorías» del catálogo.');
            }

            // The first stock of a new product can only be set in a location the product is offered in.
            if ($usesLocations && $ids->isNotEmpty() && collect($this->input('stocks', []))->pluck('location_id')->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->diff($ids)->isNotEmpty()) {
                $validator->errors()->add('stocks', 'Solo puedes registrar existencias en las sedes donde se ofrece el producto.');
            }

            $locations = collect($this->input('stocks', []))->pluck('location_id');

            if ($locations->count() !== $locations->unique()->count()) {
                $validator->errors()->add('stocks', 'Cada sede aparece una sola vez.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['sku' => 'el SKU', 'name' => 'el nombre', 'description' => 'la descripción', 'category' => 'la categoría', 'category_id' => 'la categoría', 'price' => 'el precio', 'cost' => 'el coste', 'image' => 'la imagen', 'stocks.*.quantity' => 'la existencia', 'stocks.*.min_quantity' => 'el stock mínimo'];
    }

    public function messages(): array
    {
        return [
            'sku.regex' => 'El SKU solo puede llevar letras, números, punto, guion, guion bajo y barra.',
            'sku.unique' => 'Ya existe un producto con ese SKU en este Workspace.',
            'category_id.exists' => 'Elige una categoría de la lista.',
            'image.max' => 'La imagen no puede pesar más de '.(config('orders.image.max_kb') / 1024).' MB.',
            'image.image' => 'La imagen debe ser PNG, JPG o WebP.',
            'image.mimes' => 'La imagen debe ser PNG, JPG o WebP.',
            'stocks.*.quantity.min' => 'La existencia no puede ser negativa.',
            'stocks.*.min_quantity.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }

    /** @return array<string, mixed> the columns of the product (without the picture, which the controller stores) */
    public function product(): array
    {
        $data = $this->validated();
        $clean = fn (string $key) => filled($data[$key] ?? null) ? trim($data[$key]) : null;
        $inventory = app(CommerceSchema::class)->inventoryReady();
        $category = $inventory ? ProductCategory::where('workspace_id', $this->attributes->get('workspace')->id)->find($data['category_id'] ?? null) : null;

        return ['sku' => $clean('sku'), 'name' => trim($data['name']), 'description' => $clean('description'), 'price' => $data['price']]
            + ($inventory
                ? ['category_id' => $category?->id, 'category' => $category?->name, 'cost' => filled($data['cost'] ?? null) ? $data['cost'] : null]
                : ['category' => $clean('category')]);
    }

    /** Whether the form changes the state / the stock control (each is applied only when the person may: see the controller). */
    public function wants(string $key): ?bool
    {
        return $this->has($key) ? $this->boolean($key) : null;
    }

    public function picture(): ?UploadedFile
    {
        return $this->file('image');
    }

    /** @return list<int> */
    public function locationIds(): array
    {
        return array_map('intval', $this->validated('location_ids') ?? []);
    }

    /** @return list<array{location_id: ?int, quantity: int, min_quantity: int}> */
    public function stocks(): array
    {
        return collect($this->validated('stocks') ?? [])->map(fn (array $row) => ['location_id' => filled($row['location_id'] ?? null) ? (int) $row['location_id'] : null, 'quantity' => (int) $row['quantity'], 'min_quantity' => (int) ($row['min_quantity'] ?? 0)])->values()->all();
    }
}
