<?php

namespace App\Orders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The valid categories of the products of a Workspace. The ONLY place that creates one: the same text with another capitalization or spacing resolves to the
 * category that already exists (`normalized_name` is unique per Workspace in the database too, so two simultaneous requests cannot make a duplicate).
 */
class ProductCategories
{
    /** Lower case with single spaces: what two spellings of the same category have in common. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** The name as it is kept: trimmed, single spaces, the capitalization of whoever wrote it first. */
    public static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** Whether the categories exist in this database (migration `create_inventory_tables`). */
    public function ready(): bool
    {
        return app(CommerceSchema::class)->inventoryReady();
    }

    /** @return Collection<int, ProductCategory> the active categories, by name */
    public function options(Workspace $workspace): Collection
    {
        return ProductCategory::where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * The categories of the administration tab (active and inactive) with how many products use each, optionally filtered by text (any capitalization or accent).
     *
     * @return Collection<int, ProductCategory>
     */
    public function administration(Workspace $workspace, string $search = ''): Collection
    {
        $needle = self::normalize($search);

        return ProductCategory::where('workspace_id', $workspace->id)->withCount('products')
            ->when($needle !== '', fn ($q) => $q->where('normalized_name', 'like', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%'))
            ->orderByDesc('is_active')->orderBy('name')->limit(500)->get();
    }

    /**
     * Renames a category. The name keeps the capitalization the person wrote; the same name as ANOTHER category of the Workspace (in any capitalization or
     * spacing) is refused, and the copy of the name that the products keep (`products.category`) follows, in one transaction. Products stay linked by id.
     *
     * @throws ValidationException
     */
    public function rename(ProductCategory $category, string $name): ProductCategory
    {
        $normalized = self::normalize($name);

        if ($normalized === '') {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre de la categoría.']);
        }

        $taken = fn () => ProductCategory::where('workspace_id', $category->workspace_id)->where('normalized_name', $normalized)->whereKeyNot($category->id)->exists();

        if ($taken()) {
            throw ValidationException::withMessages(['name' => 'Ya existe una categoría con ese nombre.']);
        }

        try {
            DB::transaction(function () use ($category, $name, $normalized) {
                $category->update(['name' => self::clean($name), 'normalized_name' => $normalized]);
                Product::where('workspace_id', $category->workspace_id)->where('category_id', $category->id)->update(['category' => $category->name]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Ya existe una categoría con ese nombre.']);
        }

        return $category;
    }

    /** The existing category with that name (any spelling), or null. */
    public function find(Workspace $workspace, string $name): ?ProductCategory
    {
        $normalized = self::normalize($name);

        return $normalized === '' ? null : ProductCategory::where('workspace_id', $workspace->id)->where('normalized_name', $normalized)->first();
    }

    /** The category with that name, created when none exists. The boolean says whether it was created now. @return array{0: ProductCategory, 1: bool} */
    public function ensure(Workspace $workspace, string $name): array
    {
        $existing = $this->find($workspace, $name);

        if ($existing) {
            return [$existing, false];
        }

        try {
            return [ProductCategory::create(['workspace_id' => $workspace->id, 'name' => self::clean($name), 'normalized_name' => self::normalize($name), 'is_active' => true]), true];
        } catch (UniqueConstraintViolationException) {
            // Somebody created the same category at the same time: that one is the category.
            return [$this->find($workspace, $name), false];
        }
    }
}
