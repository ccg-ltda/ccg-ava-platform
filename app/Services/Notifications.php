<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The common notification system of every Workspace (one for clients and for Desarrollo_DEV). A notification belongs to ONE Workspace and is addressed to a user
 * or, when `user_id` is null, to every member whose role holds `permission` (null = every member). What each user has read is stored per user, so one agent reading
 * it never hides it from another. Visibility is computed on the server from the Workspace of the session and the user's permissions in it: an id sent by the
 * browser only selects among the notifications that are already visible, so a foreign or unauthorised id behaves exactly like one that does not exist.
 *
 * `dedupe_key` makes a business transition notify once: repeating the same key in the same Workspace is a no-op.
 */
class Notifications
{
    public const LIMIT = 20;

    private static bool $ready = false;

    /** Whether the migration that creates the tables ran. Only «yes» is remembered, so applying the migration is noticed without a restart. */
    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('workspace_notifications');
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    /** Creates the notification unless one with the same dedupe key already exists. Returns it, or null when it was a duplicate. */
    public function notify(Workspace $workspace, string $type, string $title, ?string $body = null, ?string $actionUrl = null, ?string $permission = null, ?User $user = null, ?string $resourceType = null, ?int $resourceId = null, ?string $dedupeKey = null): ?WorkspaceNotification
    {
        if (! self::ready()) {
            return null;
        }

        if ($dedupeKey !== null && WorkspaceNotification::where('workspace_id', $workspace->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return WorkspaceNotification::create([
                'workspace_id' => $workspace->id, 'user_id' => $user?->id, 'permission' => $permission, 'type' => substr($type, 0, 40),
                'title' => mb_substr($title, 0, 140), 'body' => $body !== null ? mb_substr($body, 0, 255) : null,
                'action_url' => $actionUrl !== null ? substr($actionUrl, 0, 255) : null,
                'resource_type' => $resourceType, 'resource_id' => $resourceId, 'dedupe_key' => $dedupeKey !== null ? substr($dedupeKey, 0, 120) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // lost a race with an identical event: still exactly one
        }
    }

    /** @param  list<string>  $permissions  the user's permissions in THIS Workspace */
    public function visible(Workspace $workspace, User $user, array $permissions): Builder
    {
        return WorkspaceNotification::query()->where('workspace_notifications.workspace_id', $workspace->id)
            ->where(fn ($q) => $q->whereNull('workspace_notifications.user_id')->orWhere('workspace_notifications.user_id', $user->id))
            ->where(fn ($q) => $q->whereNull('workspace_notifications.permission')->orWhereIn('workspace_notifications.permission', $permissions ?: ['__none__']));
    }

    private function unread(Builder $query, User $user): Builder
    {
        return $query->whereNotExists(fn ($r) => $r->select(DB::raw(1))->from('workspace_notification_reads as r')
            ->whereColumn('r.notification_id', 'workspace_notifications.id')->where('r.user_id', $user->id));
    }

    /** Only the unread count (one query): what every page load needs for the bell; the list is fetched when the bell is opened. @return array{unread: int, items: list<array<string, mixed>>} */
    public function badge(Request $request): array
    {
        $workspace = $request->attributes->get('workspace');
        $user = $request->user();

        if (! self::ready() || ! $user || ! $workspace instanceof Workspace) {
            return ['unread' => 0, 'items' => []];
        }

        return ['unread' => $this->unread($this->visible($workspace, $user, $request->attributes->get('workspace_permissions', [])), $user)->count(), 'items' => []];
    }

    /** @return array{unread: int, items: list<array<string, mixed>>} */
    public function summary(Request $request): array
    {
        $workspace = $request->attributes->get('workspace');
        $user = $request->user();

        if (! self::ready() || ! $user || ! $workspace instanceof Workspace) {
            return ['unread' => 0, 'items' => []];
        }

        $permissions = $request->attributes->get('workspace_permissions', []);
        $base = $this->visible($workspace, $user, $permissions);
        $reads = DB::table('workspace_notification_reads')->where('user_id', $user->id)->pluck('read_at', 'notification_id');

        $items = (clone $base)->orderByDesc('workspace_notifications.id')->limit(self::LIMIT)->get()->map(fn (WorkspaceNotification $n) => [
            'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'url' => $n->action_url,
            'read' => $reads->has($n->id), 'createdAt' => $n->created_at?->toIso8601String(),
        ])->all();

        return ['unread' => $this->unread(clone $base, $user)->count(), 'items' => $items];
    }

    /** Marks ONE visible notification as read for this user. False when it is not visible to them (it does not exist for them). */
    public function markRead(Request $request, int $id): bool
    {
        if (! self::ready()) {
            return false;
        }

        $workspace = $request->attributes->get('workspace');
        $user = $request->user();
        $found = $this->visible($workspace, $user, $request->attributes->get('workspace_permissions', []))->whereKey($id)->exists();

        if ($found) {
            DB::table('workspace_notification_reads')->insertOrIgnore(['notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()]);
        }

        return $found;
    }

    /** Marks every notification visible to this user in the active Workspace as read. Returns how many changed. */
    public function markAllRead(Request $request): int
    {
        if (! self::ready()) {
            return 0;
        }

        $workspace = $request->attributes->get('workspace');
        $user = $request->user();
        $ids = $this->unread($this->visible($workspace, $user, $request->attributes->get('workspace_permissions', [])), $user)->pluck('workspace_notifications.id');

        foreach ($ids->chunk(200) as $chunk) {
            DB::table('workspace_notification_reads')->insertOrIgnore($chunk->map(fn ($id) => ['notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()])->all());
        }

        return $ids->count();
    }
}
