<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccess;
use App\Services\ProfilePhoto;
use App\Services\SupportChannel;
use App\Services\WorkspaceSettingsService;
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
                    // The own photo (a path of this application, versioned), or null: the top bar falls back to the initials.
                    'photoUrl' => app(ProfilePhoto::class)->url($request->user()),
                ]) : null,
            ],
            // The visible brand (config/branding.php): ONE source for every title, header and email; the legal name and internal identifiers are not part of it.
            'brand' => fn () => (string) config('branding.name'),
            'brandShort' => fn () => (string) config('branding.short'),
            // One-shot messages the UI shows as toasts.
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
            ],
            // Modules the plan of the active Workspace includes (navigation only; every route is also enforced by the server),
            // and what this user administers of the platform (superuser inside the administrative Workspace with the permission).
            'modules' => fn () => $request->attributes->has('workspace') ? app(ModuleAccess::class)->forRequest($request) : [],
            // CCG's public support chat: the public key of the web channel of the administrative Workspace, only while that channel is valid and live
            // (SupportChannel). Null = nothing is offered. The key is public by design; the destination is always resolved by the server from it.
            'supportChat' => fn () => ($key = app(SupportChannel::class)->publicKey()) ? ['key' => $key, 'script' => url('/widget/ava-widget.js')] : null,
            'platform' => fn () => EnsurePlatformAdministration::allows($request),
            'platformAccess' => fn () => [
                'plans' => EnsurePlatformAdministration::allows($request, EnsurePlatformAdministration::PLANS),
                'modules' => EnsurePlatformAdministration::allows($request, EnsurePlatformAdministration::MODULES),
            ],
            'workspace' => fn () => ($workspace = $request->attributes->get('workspace')) ? [
                'code' => $workspace->code,
                'name' => $workspace->name,
                'organization' => $workspace->organization->name,
                'settings' => app(WorkspaceSettingsService::class)->shared($workspace),
            ] : null,
        ];
    }
}
