<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * The single place that writes audit events. It records who (the signed-in user, copied by name and email), where
 * (the Workspace of the request, or the one the change belongs to), what, on which record, the IP and the relevant
 * differences. It never stores browser data. Nothing is recorded outside a signed-in web request (seeders, console
 * commands, fixtures), and an update without relevant changes records nothing.
 */
class AuditLogger
{
    /**
     * @param  list<array{field: string, before: ?string, after: ?string}>  $changes
     */
    public function record(string $action, string $resource, ?int $resourceId, string $label, array $changes, ?Workspace $workspace = null): ?AuditLog
    {
        $user = Auth::user();
        $request = request();

        if (! $user || ! $request->attributes->has('workspace') || ($action === 'updated' && $changes === [])) {
            return null;
        }

        $workspace ??= $request->attributes->get('workspace');

        return AuditLog::create([
            'workspace_id' => $workspace->id,
            'workspace_code' => $workspace->code,
            'workspace_name' => $workspace->name,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'action' => $action,
            'resource_type' => $resource,
            'resource_id' => $resourceId,
            'resource_label' => mb_substr($label, 0, 255),
            'description' => mb_substr(config("audit.actions.{$action}.verb").' '.config("audit.resources.{$resource}.noun").' '.$label, 0, 500),
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }

    /** One difference of a field. */
    public static function change(string $field, ?string $before, ?string $after): array
    {
        return ['field' => $field, 'before' => $before, 'after' => $after];
    }
}
