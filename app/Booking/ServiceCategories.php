<?php

namespace App\Booking;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** The valid categories of the services of a Workspace. The ONLY place that creates or renames one (same rules as App\Orders\ProductCategories). */
class ServiceCategories
{
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** Whether the table exists in this database (the code can ship before the migration). */
    public function ready(): bool
    {
        return Schema::hasTable('service_categories') && Schema::hasColumn('services', 'category_id');
    }

    /** @return Collection<int, ServiceCategory> the active ones, by name */
    public function options(Workspace $workspace): Collection
    {
        return ServiceCategory::where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('name')->get();
    }

    /** @return Collection<int, ServiceCategory> every category with how many services use it */
    public function administration(Workspace $workspace): Collection
    {
        return ServiceCategory::where('workspace_id', $workspace->id)->withCount('services')->orderByDesc('is_active')->orderBy('name')->limit(500)->get();
    }

    public function find(Workspace $workspace, string $name): ?ServiceCategory
    {
        $normalized = self::normalize($name);

        return $normalized === '' ? null : ServiceCategory::where('workspace_id', $workspace->id)->where('normalized_name', $normalized)->first();
    }

    /** @return array{0: ServiceCategory, 1: bool} the category and whether it was created now */
    public function ensure(Workspace $workspace, string $name): array
    {
        $existing = $this->find($workspace, $name);

        if ($existing) {
            return [$existing, false];
        }

        try {
            return [ServiceCategory::create(['workspace_id' => $workspace->id, 'name' => self::clean($name), 'normalized_name' => self::normalize($name), 'is_active' => true]), true];
        } catch (UniqueConstraintViolationException) {
            return [$this->find($workspace, $name), false];
        }
    }

    /** @throws ValidationException */
    public function rename(ServiceCategory $category, string $name): ServiceCategory
    {
        $normalized = self::normalize($name);

        if ($normalized === '') {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre de la categoría.']);
        }

        if (ServiceCategory::where('workspace_id', $category->workspace_id)->where('normalized_name', $normalized)->whereKeyNot($category->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'Ya existe una categoría con ese nombre.']);
        }

        try {
            DB::transaction(function () use ($category, $name, $normalized) {
                $category->update(['name' => self::clean($name), 'normalized_name' => $normalized]);
                Service::where('workspace_id', $category->workspace_id)->where('category_id', $category->id)->update(['category' => $category->name]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Ya existe una categoría con ese nombre.']);
        }

        return $category;
    }
}
