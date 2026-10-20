<?php

namespace App\Orders;

use App\Models\ProductCategory;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

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
