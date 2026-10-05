<?php

namespace App\Http\Requests;

use App\Reports\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of Reportes (period and granularity). Authorization is the `workspace.permission:view-dashboard` route
 * middleware; the Workspace is never read from here, only from the session.
 */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(array_keys(config('reports.periods')))],
            'granularity' => ['nullable', Rule::in(array_keys(config('reports.granularities')))],
            // Only a superuser inside the administrative Workspace may use it (WorkspaceScope); the server checks.
            'workspace' => ['nullable', 'string', 'regex:/^(all|\d+)$/'],
        ];
    }

    public function attributes(): array
    {
        return ['period' => 'periodo', 'granularity' => 'granularidad'];
    }

    public function workspaceScope(): ?string
    {
        return $this->validated('workspace');
    }

    /** The period for the Workspace's timezone; an unsupported granularity falls back to the period's default. */
    public function period(string $timezone): ReportPeriod
    {
        return ReportPeriod::make($this->validated('period') ?? config('reports.default_period'), $this->validated('granularity'), $timezone);
    }
}
