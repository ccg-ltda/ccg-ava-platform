<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which locations (sedes) of a Workspace a member may work with. ONE place decides it and every controller asks it, so no route is left with its own rule.
 *
 * Meaning of the membership (`workspace_user.location_scope`):
 *  - `all` (the default, and what every existing member has): every location of the Workspace, sujeto a role and module as always.
 *  - `selected`: ONLY the locations listed in `workspace_user_locations`. An EMPTY list is NOT "all": it means no location at all (the member is configured but
 *    still authorized for none), so a mistake can only take access away, never widen it.
 * Never restricted: the internal administration (the administrative Workspace and platform superusers). On a database without the migration nothing is
 * restricted (the behavior before this feature) and the administration says so.
 *
 * A restricted member never works with data that belongs to no location (an order or an appointment without a sede, a product offered nowhere): that data is
 * not theirs, so it is neither listed nor writable for them. Read, write and every id the browser sends go through the same check.
 */
class LocationScope
{
    public const ALL = 'all';

    public const SELECTED = 'selected';

    /** @var array<string, ?list<int>> */
    private array $memo = [];

    /** Whether the database has what the scope needs (migration `limit_memberships_to_selected_locations`). */
    public function ready(): bool
    {
        return Schema::hasColumn('workspace_user', 'location_scope') && Schema::hasTable('workspace_user_locations');
    }

    /** @return ?list<int> null = every location; a list = only those ids ([] = none) */
    public function allowedIds(Workspace $workspace, ?User $user): ?array
    {
        if ($user === null || $workspace->isAdministrative() || $user->isPlatformSuperuser() || ! $this->ready()) {
            return null;
        }

        $key = $workspace->id.':'.$user->id;

        if (! array_key_exists($key, $this->memo)) {
            $scope = DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->value('location_scope');

            $this->memo[$key] = $scope === self::SELECTED
                ? DB::table('workspace_user_locations')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->pluck('location_id')->map(fn ($id) => (int) $id)->all()
                : null;
        }

        return $this->memo[$key];
    }

    public function restricted(Workspace $workspace, ?User $user): bool
    {
        return $this->allowedIds($workspace, $user) !== null;
    }

    /** Whether the member may work with data of this location. `null` (data of no location) is allowed only to an unrestricted member. */
    public function allows(Workspace $workspace, ?User $user, ?int $locationId): bool
    {
        $allowed = $this->allowedIds($workspace, $user);

        return $allowed === null || ($locationId !== null && in_array($locationId, $allowed, true));
    }

    /** 403 when the member may not work with this location (the same answer for a real location and for one that does not exist, so ids are not probed). */
    public function assertAllows(Workspace $workspace, ?User $user, ?int $locationId): void
    {
        abort_unless($this->allows($workspace, $user, $locationId), 403, 'No tienes acceso a esa sede.');
    }

    /**
     * The ids the member may use out of a list. A restricted member keeps only their own.
     *
     * @param  iterable<int>  $ids
     * @return list<int>
     */
    public function only(Workspace $workspace, ?User $user, iterable $ids): array
    {
        $allowed = $this->allowedIds($workspace, $user);
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();

        return ($allowed === null ? $ids : $ids->filter(fn (int $id) => in_array($id, $allowed, true)))->values()->all();
    }

    /**
     * Whether the member may CHANGE something that is offered in these locations. A restricted member may only when it is offered somewhere and EVERYWHERE it is
     * offered is theirs (changing it would change other locations too); an unrestricted one always may.
     *
     * @param  list<int>  $offeredIn  the locations the thing is offered in (empty = offered in every location, which a restricted member can not change)
     */
    public function mayChange(Workspace $workspace, ?User $user, array $offeredIn): bool
    {
        $allowed = $this->allowedIds($workspace, $user);

        return $allowed === null || ($offeredIn !== [] && array_diff($offeredIn, $allowed) === []);
    }

    /** Whether the member may SEE something offered in these locations: any of them is theirs. Offered nowhere = offered everywhere only for the unrestricted. */
    public function maySee(Workspace $workspace, ?User $user, array $offeredIn): bool
    {
        $allowed = $this->allowedIds($workspace, $user);

        return $allowed === null || array_intersect($offeredIn, $allowed) !== [];
    }

    /** Replaces the scope of a member (all, or exactly these locations of THIS Workspace). Returns the locations as they were stored. @param  list<int>  $locationIds @return list<int> */
    public function save(Workspace $workspace, User $member, string $scope, array $locationIds): array
    {
        $ids = $scope === self::SELECTED
            ? DB::table('locations')->where('workspace_id', $workspace->id)->whereIn('id', $locationIds)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        DB::transaction(function () use ($workspace, $member, $scope, $ids) {
            DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $member->id)->update(['location_scope' => $scope]);
            DB::table('workspace_user_locations')->where('workspace_id', $workspace->id)->where('user_id', $member->id)->delete();

            foreach ($ids as $id) {
                DB::table('workspace_user_locations')->insert(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'location_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        unset($this->memo[$workspace->id.':'.$member->id]);

        return $ids;
    }
}
