<?php

namespace App\Privacy;

use App\Audit\AuditLogger;
use App\Models\SupportAccess as Grant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Temporary, justified access of a platform administrator to PRIVATE data of another Workspace (config/privacy.php). The only place
 * that decides it. Nothing here is implied by being a superuser or by working in the administrative Workspace: it takes the
 * `access-private-data` permission of the role AND an active grant (one Workspace, one kind of data, a reason, a short life).
 * Grants and reads are audited IN THE AUDITED WORKSPACE (so its own administrators can see them) and never store the private content.
 */
class SupportAccess
{
    public const PERMISSION = 'access-private-data';

    public function __construct(private readonly AuditLogger $audit) {}

    /** Whether this request's user may open a grant: working inside the administrative Workspace with the permission. */
    public function canRequest(Request $request): bool
    {
        return (bool) $request->user()
            && (bool) $request->attributes->get('workspace')?->isAdministrative()
            && in_array(self::PERMISSION, $request->attributes->get('workspace_permissions', []), true);
    }

    /** @return array<string, string> kind of data => label */
    public function types(): array
    {
        return config('privacy.support_access.types');
    }

    /** The user's live grant on that Workspace and kind of data, or null. */
    public function current(User $user, Workspace $target, string $type): ?Grant
    {
        return Grant::active()->where('user_id', $user->id)->where('workspace_id', $target->id)->where('data_type', $type)->latest('id')->first();
    }

    /** Opens a grant. Refused for anyone who cannot request it, for the own Workspace (it needs none) and for a short reason. */
    public function grant(Request $request, Workspace $target, string $type, string $reason): Grant
    {
        abort_unless($this->canRequest($request) && array_key_exists($type, $this->types()), 403);
        abort_if($target->is($request->attributes->get('workspace')), 404);

        $reason = trim($reason);

        if (mb_strlen($reason) < config('privacy.support_access.reason_min')) {
            throw ValidationException::withMessages(['reason' => 'Explica el motivo con al menos '.config('privacy.support_access.reason_min').' caracteres.']);
        }

        $grant = Grant::create([
            'workspace_id' => $target->id,
            'user_id' => $request->user()->id,
            'data_type' => $type,
            'reason' => mb_substr($reason, 0, 500),
            'expires_at' => now()->addMinutes(config('privacy.support_access.minutes')),
        ]);

        $this->audit->record('created', 'support_access', $grant->id, $this->types()[$type], [
            AuditLogger::change('Tipo de datos', null, $this->types()[$type]),
            AuditLogger::change('Motivo', null, $grant->reason),
            AuditLogger::change('Vence', null, $grant->expires_at->toDateTimeString()),
        ], $target);

        return $grant;
    }

    /** Ends the user's own grant before it expires. */
    public function revoke(Request $request, Grant $grant): void
    {
        abort_unless($grant->user_id === $request->user()->id, 404);

        if ($grant->revoked_at === null && $grant->expires_at->isFuture()) {
            $grant->update(['revoked_at' => now()]);
            $this->audit->record('updated', 'support_access', $grant->id, $this->types()[$grant->data_type] ?? $grant->data_type, [AuditLogger::change('Estado', 'Vigente', 'Revocado')], $grant->workspace);
        }
    }

    /** Records that private data of `$target` was read under `$grant`: who/when/what kind/why, never the content. */
    public function recordRead(Grant $grant, string $resource, ?int $resourceId, string $label): void
    {
        // A page that refreshes itself must not flood the audit log: one record per grant and record every few minutes.
        if (! Cache::add("support-read:{$grant->id}:{$resource}:{$resourceId}", 1, 300)) {
            return;
        }

        $this->audit->record('accessed', $resource, $resourceId, $label, [AuditLogger::change('Motivo', null, $grant->reason)], $grant->workspace);
    }
}
