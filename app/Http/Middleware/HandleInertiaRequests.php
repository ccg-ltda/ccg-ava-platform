<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Role;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Roles/permissions are those of the user's role inside the active Workspace.
            'auth' => [
                'user' => fn () => $request->user() ? array_merge($request->user()->toArray(), [
                    'permissions' => $request->attributes->get('workspace_permissions', []),
                    'roles' => ($role = $request->attributes->get('workspace_role')) ? [$role] : [],
                ]) : null,
            ],
            'flash' => fn () => ['success' => $request->session()->get('success')],
            'workspace' => fn () => ($workspace = $request->attributes->get('workspace')) ? [
                'code' => $workspace->code,
                'name' => $workspace->name,
                'organization' => $workspace->organization->name,
            ] : null,
        ];
    }
}
