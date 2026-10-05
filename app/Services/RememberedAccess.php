<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * What "Recordar sesión" remembers at login: the last email and the last Workspace. Never the password.
 *
 * Both live in Laravel's encrypted, HttpOnly, SameSite cookies (no table). They are only a convenience: the
 * Workspace is validated again by the server every time it is recalled (it must exist, be enabled and belong to
 * an enabled Organization), and the user's membership is still checked by the login and by EnsureWorkspaceContext.
 */
class RememberedAccess
{
    public const EMAIL_COOKIE = 'ava_remembered_email';

    public const WORKSPACE_COOKIE = 'ava_remembered_workspace';

    public function remember(string $email, int $workspaceId): void
    {
        $minutes = (int) config('auth.remembered_access_minutes');

        Cookie::queue(Cookie::make(self::EMAIL_COOKIE, Str::lower($email), $minutes));
        Cookie::queue(Cookie::make(self::WORKSPACE_COOKIE, (string) $workspaceId, $minutes));
    }

    /** Called when the user logs in without ticking "Recordar sesión". */
    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::EMAIL_COOKIE));
        $this->forgetWorkspace();
    }

    public function forgetWorkspace(): void
    {
        Cookie::queue(Cookie::forget(self::WORKSPACE_COOKIE));
    }

    public function email(Request $request): ?string
    {
        $email = $request->cookie(self::EMAIL_COOKIE);

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** The remembered Workspace if it is still usable; otherwise the memory is dropped. */
    public function workspace(Request $request): ?Workspace
    {
        $id = $request->cookie(self::WORKSPACE_COOKIE);

        if ($id === null) {
            return null;
        }

        $workspace = ctype_digit((string) $id) ? Workspace::available()->find((int) $id) : null;

        if (! $workspace) {
            $this->forgetWorkspace();
        }

        return $workspace;
    }
}
