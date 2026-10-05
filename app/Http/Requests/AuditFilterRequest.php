<?php

namespace App\Http\Requests;

use App\Services\ListPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of Auditoría (the page and the PDF share them). Authorization is the `workspace.permission:manage-settings`
 * route middleware. The Workspace parameter is only honored for a superuser working inside the administrative Workspace
 * (WorkspaceScope); anyone else who sends it is refused, and everyone else is limited to the session's Workspace.
 */
class AuditFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user' => ['nullable', 'integer'],
            'workspace' => ['nullable', 'string', 'regex:/^(all|\d+)$/'],
            'resource' => ['nullable', Rule::in(array_keys(config('audit.resources')))],
            'action' => ['nullable', Rule::in(array_keys(config('audit.actions')))],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['from' => 'fecha inicial', 'to' => 'fecha final', 'resource' => 'módulo', 'action' => 'acción', 'user' => 'usuario'];
    }

    /** @return array{search: string, from: ?string, to: ?string, user: ?int, workspace: ?string, resource: ?string, action: ?string} */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'search' => trim((string) ($data['search'] ?? '')),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'user' => isset($data['user']) ? (int) $data['user'] : null,
            'workspace' => $data['workspace'] ?? null,
            'resource' => $data['resource'] ?? null,
            'action' => $data['action'] ?? null,
        ];
    }

    public function perPage(): int
    {
        return ListPagination::size($this, 'users');
    }
}
