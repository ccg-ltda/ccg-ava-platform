<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\WorkspaceSetting;
use App\Privacy\Mask;

/**
 * An audit event as the page and the PDF show it: Spanish labels, and the date and time in the viewer's Workspace
 * regional settings (the stored moment keeps full precision).
 */
class AuditPresenter
{
    /**
     * @param  ?int  $ownWorkspaceId  the Workspace of the viewer: the IP and the email of whoever acted in ANOTHER Workspace are masked
     *                                (null = no masking, e.g. a test or a single-Workspace caller)
     */
    public function __construct(private readonly WorkspaceSetting $settings, private readonly ?int $ownWorkspaceId = null) {}

    /** @return array<string, mixed> */
    public function present(AuditLog $log): array
    {
        $foreign = $this->ownWorkspaceId !== null && $log->workspace_id !== $this->ownWorkspaceId;
        // Rows written before records of contacts were identified by "#id" may still hold a name or number: they are never shown.
        $label = AuditLogger::safeLabel($log->resource_type, $log->resource_id, (string) $log->resource_label);
        $description = $label === $log->resource_label ? $log->description : mb_substr(config("audit.actions.{$log->action}.verb").' '.config("audit.resources.{$log->resource_type}.noun").' '.$label, 0, 500);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'actionLabel' => config("audit.actions.{$log->action}.label", $log->action),
            'description' => $description,
            'module' => config("audit.resources.{$log->resource_type}.label", $log->resource_type),
            'moduleKey' => $log->resource_type,
            'record' => $label,
            'recordId' => $log->resource_id,
            'user' => ['name' => $log->user_name, 'email' => $foreign ? Mask::email($log->user_email) : $log->user_email],
            'actor' => $log->actor,
            'outcome' => $log->outcome,
            'workspace' => ['name' => $log->workspace_name, 'code' => $log->workspace_code],
            'date' => $this->settings->formatDate($log->created_at),
            'time' => $this->settings->formatPreciseTime($log->created_at),
            'ip' => $foreign ? null : $log->ip_address,
            'changes' => $log->changes,
        ];
    }
}
