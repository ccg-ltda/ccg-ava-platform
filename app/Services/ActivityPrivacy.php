<?php

namespace App\Services;

use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a Workspace keeps the request and response BODIES of its technical activity (see the migration `add_technical_activity_privacy`). Credentials are never stored
 * in either case; this only decides if the bodies, headers and query of a call are kept next to its metadata. A Workspace without the setting (or an environment
 * that did not apply the migration yet) keeps them, which is the behavior before this setting existed.
 */
class ActivityPrivacy
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('workspace_settings', 'activity_store_bodies');
    }

    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    public function storesBodies(Workspace|int $workspace): bool
    {
        if (! self::ready()) {
            return true;
        }

        $value = DB::table('workspace_settings')->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)->value('activity_store_bodies');

        return $value === null ? true : (bool) $value;
    }

    public function save(Workspace $workspace, bool $store): void
    {
        abort_unless(self::ready(), 409, 'La actualización de la base de datos que guarda esta configuración todavía no se ha aplicado.');

        $workspace->settings()->firstOrCreate([], WorkspaceSetting::defaults());
        DB::table('workspace_settings')->where('workspace_id', $workspace->id)->update(['activity_store_bodies' => $store, 'updated_at' => now()]);
    }
}
