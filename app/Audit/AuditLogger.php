<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * The single place that writes audit events. A person's event records who (the signed-in user, copied by name and
 * email), where (the Workspace of the request, or the one the change belongs to), what, on which record, the IP and the
 * relevant differences; it exists only for a signed-in web request, so seeders, fixtures and console commands never write
 * one by accident. An AUTOMATIC event (`system`: an assistant asking for a person, a failed execution, the platform
 * console) is written only by the code that means it, with the Workspace it belongs to and a fixed name for its source,
 * and has no user. Both never store browser data, bodies, credentials or message text.
 */
class AuditLogger
{
    public const USER = 'user';

    public const SYSTEM = 'system';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    /**
     * An event of the signed-in person. Nothing is recorded outside a signed-in web request, and an update without
     * relevant changes records nothing.
     *
     * @param  list<array{field: string, before: ?string, after: ?string}>  $changes
     */
    public function record(string $action, string $resource, ?int $resourceId, string $label, array $changes, ?Workspace $workspace = null, string $outcome = self::SUCCESS): ?AuditLog
    {
        $user = Auth::user();
        $request = request();

        if (! $user || ! $request->attributes->has('workspace') || ($action === 'updated' && $changes === [])) {
            return null;
        }

        $workspace ??= $request->attributes->get('workspace');

        return $this->write($workspace, self::USER, $user->id, $user->name, $user->email, $request->ip(), $action, $resource, $resourceId, $label, $changes, $outcome);
    }

    /**
     * An event of an automatic process of Ava, always about a Workspace given by the caller (never read from a request).
     * `$source` is a fixed name ("Asistente (automático)"), not provider text.
     *
     * @param  list<array{field: string, before: ?string, after: ?string}>  $changes
     */
    public function system(string $action, string $resource, ?int $resourceId, string $label, array $changes, Workspace $workspace, string $source, string $outcome = self::SUCCESS): AuditLog
    {
        return $this->write($workspace, self::SYSTEM, null, $source, '', null, $action, $resource, $resourceId, $label, $changes, $outcome);
    }

    /**
     * For an operation a person or a process can trigger (the same code path): a signed-in web request is the person's
     * event, anything else is an automatic one from `$source`. A request with no signed-in user (the public widget, the
     * assistant's API, a job) is never attributed to a person.
     *
     * @param  list<array{field: string, before: ?string, after: ?string}>  $changes
     */
    public function recordForActor(string $action, string $resource, ?int $resourceId, string $label, array $changes, Workspace $workspace, string $source, string $outcome = self::SUCCESS): ?AuditLog
    {
        return Auth::user() && request()->attributes->has('workspace')
            ? $this->record($action, $resource, $resourceId, $label, $changes, $workspace, $outcome)
            : $this->system($action, $resource, $resourceId, $label, $changes, $workspace, $source, $outcome);
    }

    /** One difference of a field. */
    public static function change(string $field, ?string $before, ?string $after): array
    {
        return ['field' => $field, 'before' => $before, 'after' => $after];
    }

    /** @param  list<array{field: string, before: ?string, after: ?string}>  $changes */
    private function write(Workspace $workspace, string $actor, ?int $userId, string $name, string $email, ?string $ip, string $action, string $resource, ?int $resourceId, string $label, array $changes, string $outcome): AuditLog
    {
        return AuditLog::create([
            'workspace_id' => $workspace->id,
            'workspace_code' => $workspace->code,
            'workspace_name' => $workspace->name,
            'user_id' => $userId,
            'user_name' => $name,
            'user_email' => $email,
            'actor' => $actor,
            'outcome' => $outcome,
            'action' => $action,
            'resource_type' => $resource,
            'resource_id' => $resourceId,
            'resource_label' => mb_substr($label, 0, 255),
            'description' => mb_substr(config("audit.actions.{$action}.verb").' '.config("audit.resources.{$resource}.noun").' '.$label, 0, 500),
            'changes' => $changes,
            'ip_address' => $ip,
        ]);
    }
}
