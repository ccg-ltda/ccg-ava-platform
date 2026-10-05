<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\WorkspaceSetting;

/**
 * An audit event as the page and the PDF show it: Spanish labels, and the date and time in the viewer's Workspace
 * regional settings (the stored moment keeps full precision).
 */
class AuditPresenter
{
    public function __construct(private readonly WorkspaceSetting $settings) {}

    /** @return array<string, mixed> */
    public function present(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'actionLabel' => config("audit.actions.{$log->action}.label", $log->action),
            'description' => $log->description,
            'module' => config("audit.resources.{$log->resource_type}.label", $log->resource_type),
            'moduleKey' => $log->resource_type,
            'record' => $log->resource_label,
            'recordId' => $log->resource_id,
            'user' => ['name' => $log->user_name, 'email' => $log->user_email],
            'workspace' => ['name' => $log->workspace_name, 'code' => $log->workspace_code],
            'date' => $this->settings->formatDate($log->created_at),
            'time' => $this->settings->formatPreciseTime($log->created_at),
            'ip' => $log->ip_address,
            'changes' => $log->changes,
        ];
    }
}
