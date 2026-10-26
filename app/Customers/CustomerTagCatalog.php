<?php

namespace App\Customers;

use App\Models\Campaign;
use App\Models\CustomerTag;
use App\Models\CustomerTagDefinition;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

/**
 * The catalog of the tags of the customers of a Workspace. The ONLY place that creates, renames, (de)activates or deletes one: the same tag written with another
 * capitalization, extra spaces or a decomposed Unicode accent resolves to the tag that already exists (`normalized_name` is unique per Workspace in the database
 * too, so two simultaneous requests cannot make a duplicate). Accents are NOT folded: «vía» and «via» are two tags, listed as possible duplicates for a person to
 * review; nothing is merged automatically. A tag in use is deactivated (it is not offered again, the customers keep it); only an unused one can be deleted.
 */
class CustomerTagCatalog
{
    public const MAX_LENGTH = 40;

    private ?bool $ready = null;

    /** Whether the catalog exists in this database (migration `create_customer_tag_definitions`): until it is applied the tags behave as before. */
    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('customer_tag_definitions') && Schema::hasColumn('customer_tags', 'tag_id');
    }

    /** Unicode NFC, trimmed, single spaces. */
    public static function clean(string $name): string
    {
        $composed = class_exists(Normalizer::class) ? (Normalizer::normalize($name, Normalizer::FORM_C) ?: $name) : $name;

        return trim((string) preg_replace('/\s+/u', ' ', $composed));
    }

    /** What two spellings of the same tag have in common — and how a tag is kept: lower case, as customer tags always were. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(self::clean($name));
    }

    /** @return Collection<int, CustomerTagDefinition> the active tags, by name */
    public function options(Workspace $workspace): Collection
    {
        return CustomerTagDefinition::where('workspace_id', $workspace->id)->where('is_active', true)->orderBy('normalized_name')->get();
    }

    /**
     * The tags of the administration tab (active and inactive) with how many customers use each, optionally filtered by text (any capitalization).
     *
     * @return Collection<int, CustomerTagDefinition>
     */
    public function administration(Workspace $workspace, string $search = ''): Collection
    {
        $needle = self::normalize($search);

        return CustomerTagDefinition::where('workspace_id', $workspace->id)->withCount('assignments')
            ->when($needle !== '', fn ($q) => $q->where('normalized_name', 'like', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%'))
            ->orderByDesc('is_active')->orderBy('normalized_name')->limit(500)->get();
    }

    /**
     * Groups of tags that differ only by accents («vía» / «via»): possible duplicates for a person to review. Never merged by Ava.
     *
     * @return list<list<string>> names, per group of two or more
     */
    public function possibleDuplicates(Workspace $workspace): array
    {
        return CustomerTagDefinition::where('workspace_id', $workspace->id)->orderBy('normalized_name')->get()
            ->groupBy(fn (CustomerTagDefinition $tag) => Str::ascii($tag->normalized_name))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group) => $group->pluck('name')->all())->values()->all();
    }

    /** The existing tag with that name (any spelling), or null. */
    public function find(Workspace $workspace, string $name): ?CustomerTagDefinition
    {
        $normalized = self::normalize($name);

        return $normalized === '' ? null : CustomerTagDefinition::where('workspace_id', $workspace->id)->where('normalized_name', $normalized)->first();
    }

    /** The tag with that name, created when none exists. The boolean says whether it was created now. @return array{0: CustomerTagDefinition, 1: bool} */
    public function ensure(Workspace $workspace, string $name): array
    {
        $clean = self::clean($name);

        if ($clean === '' || mb_strlen($clean) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre de la etiqueta (hasta '.self::MAX_LENGTH.' caracteres).']);
        }

        if ($existing = $this->find($workspace, $clean)) {
            return [$existing, false];
        }

        try {
            return [CustomerTagDefinition::create(['workspace_id' => $workspace->id, 'name' => self::normalize($clean), 'normalized_name' => self::normalize($clean), 'is_active' => true]), true];
        } catch (UniqueConstraintViolationException) {
            // Somebody created the same tag at the same time: that one is the tag.
            return [$this->find($workspace, $clean), false];
        }
    }

    /**
     * The tags a customer is saved with, created when new. A tag that is deactivated cannot be given to a customer that does not already have it.
     *
     * @param  list<string>  $names
     * @param  list<int>  $alreadyAssigned  definition ids the customer has now
     * @return array{0: Collection<int, CustomerTagDefinition>, 1: list<CustomerTagDefinition>} the tags, and the ones created now
     */
    public function resolve(Workspace $workspace, array $names, array $alreadyAssigned = []): array
    {
        $tags = collect();
        $created = [];

        foreach ($names as $name) {
            try {
                [$tag, $new] = $this->ensure($workspace, $name);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['tags' => 'Cada etiqueta debe tener entre 1 y '.self::MAX_LENGTH.' caracteres.']);
            }

            if (! $tag->is_active && ! in_array($tag->id, $alreadyAssigned, true)) {
                throw ValidationException::withMessages(['tags' => "La etiqueta «{$tag->name}» está desactivada: actívala en la pestaña Etiquetas para asignarla."]);
            }

            if ($new) {
                $created[] = $tag;
            }

            $tags[$tag->id] = $tag;
        }

        return [$tags->values(), $created];
    }

    /**
     * Renames a tag. The same name as ANOTHER tag of the Workspace (in any capitalization, spacing or Unicode form) is refused. The copy of the name that the
     * assignments keep follows in the same transaction; they are linked by id, so no customer loses the tag.
     *
     * @throws ValidationException
     */
    public function rename(CustomerTagDefinition $tag, string $name): CustomerTagDefinition
    {
        $clean = self::clean($name);

        if ($clean === '' || mb_strlen($clean) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre de la etiqueta (hasta '.self::MAX_LENGTH.' caracteres).']);
        }

        $taken = fn () => CustomerTagDefinition::where('workspace_id', $tag->workspace_id)->where('normalized_name', self::normalize($clean))->whereKeyNot($tag->id)->exists();

        if ($taken()) {
            throw ValidationException::withMessages(['name' => 'Ya existe una etiqueta con ese nombre.']);
        }

        try {
            DB::transaction(function () use ($tag, $clean) {
                $tag->update(['name' => self::normalize($clean), 'normalized_name' => self::normalize($clean)]);
                CustomerTag::where('workspace_id', $tag->workspace_id)->where('tag_id', $tag->id)->update(['tag' => $tag->name]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Ya existe una etiqueta con ese nombre.']);
        }

        return $tag;
    }

    /** Why a tag cannot be deleted, or null when it can (no customer has it and no campaign filter names it). */
    public function deletionBlock(CustomerTagDefinition $tag): ?string
    {
        if (CustomerTag::where('workspace_id', $tag->workspace_id)->where('tag_id', $tag->id)->exists()) {
            return 'La etiqueta está asignada a clientes: desactívala en lugar de eliminarla.';
        }

        $named = Campaign::where('workspace_id', $tag->workspace_id)->whereNotNull('filters')->get()
            ->contains(fn (Campaign $campaign) => self::normalize((string) ($campaign->filters['tag'] ?? '')) === $tag->normalized_name);

        return $named ? 'Una campaña usa esta etiqueta como filtro: desactívala en lugar de eliminarla.' : null;
    }

    /**
     * Deletes an unused tag. The check and the delete run in one transaction and the database refuses (RESTRICT) a tag that is assigned, so a customer saved at the
     * same moment cannot be left pointing to nothing.
     *
     * @throws ValidationException
     */
    public function delete(CustomerTagDefinition $tag): void
    {
        try {
            DB::transaction(function () use ($tag) {
                if ($reason = $this->deletionBlock($tag)) {
                    throw ValidationException::withMessages(['tag' => $reason]);
                }

                $tag->delete();
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['tag' => 'La etiqueta se acaba de asignar a un cliente: desactívala en lugar de eliminarla.']);
        }
    }
}
