<?php

namespace App\Customers;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one way to filter the customers of a Workspace (the Clientes list and the audience of a campaign share it, so both mean the same
 * by "source" or "tag"). The query always starts from `$workspace->customers()`.
 */
class CustomerSearch
{
    /** The filters a campaign can use (the list adds text search, consent and status). */
    public const AUDIENCE_FILTERS = ['source', 'tag', 'identity_channel', 'created_from', 'created_to'];

    /** @param  array<string, mixed>  $filters */
    public function query(Workspace $workspace, array $filters): Builder
    {
        $query = $workspace->customers()->getQuery();

        if (filled($filters['search'] ?? null)) {
            $term = mb_strtolower(trim((string) $filters['search']));
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $digits = preg_replace('/\D+/', '', $term);

            $query->where(function (Builder $q) use ($like, $digits) {
                $q->whereRaw("lower(first_name || ' ' || coalesce(last_name, '')) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(coalesce(email_normalized, '')) like ? escape '!'", [$like])
                    ->orWhereHas('identities', fn (Builder $i) => $i->whereRaw('lower(external_id) like ? escape \'!\'', [$like]));

                if ($digits !== '') {
                    $q->orWhere('phone_normalized', 'like', "%{$digits}%");
                }
            });
        }

        $query->when(filled($filters['source'] ?? null), fn (Builder $q) => $q->where('source', $filters['source']))
            ->when(filled($filters['consent'] ?? null), fn (Builder $q) => $q->where('marketing_consent', $filters['consent']))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when(filled($filters['tag'] ?? null), fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->where('tag', $filters['tag'])))
            ->when(filled($filters['identity_channel'] ?? null), fn (Builder $q) => $q->whereHas('identities', fn (Builder $i) => $i->where('channel', $filters['identity_channel'])))
            ->when(filled($filters['created_from'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['created_from']))
            ->when(filled($filters['created_to'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['created_to']));

        return $query;
    }

    /** Tags in use in the Workspace, for the filter lists. @return list<string> */
    public function tags(Workspace $workspace): array
    {
        return $workspace->customers()->join('customer_tags', 'customer_tags.customer_id', '=', 'customers.id')
            ->distinct()->orderBy('customer_tags.tag')->pluck('customer_tags.tag')->all();
    }
}
