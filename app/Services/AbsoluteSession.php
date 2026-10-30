<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;

/**
 * The ABSOLUTE lifetime of a signed-in session (S9, decision of RECOMENDACIONES.md §75): `auth.absolute_session_minutes` (12 hours) from the moment the person
 * authenticated, no matter how active the session is. SESSION_LIFETIME keeps governing INACTIVITY; this is an extra limit, never a replacement. Reaching it logs the
 * person out (the account, its roles and permissions are untouched): they simply sign in again.
 *
 * The start is stored in the session (`auth_started_at`), which survives the regenerations of a normal session. A "remember me" cookie could otherwise open a NEW session
 * and silently restart the clock, so the original start of a remembered login is also kept server side (cache, keyed by user and remember token) and a session
 * restored from the cookie inherits it; if that record is gone the person has to sign in again.
 */
class AbsoluteSession
{
    public const KEY = 'auth_started_at';

    public static function limitSeconds(): int
    {
        return max(60, (int) config('auth.absolute_session_minutes', 720)) * 60;
    }

    /** A fresh login: the clock starts now (and a remembered login also records it where the remember cookie can find it). */
    public function start(Session $session, Authenticatable $user, bool $remember): void
    {
        $now = now()->timestamp;
        $session->put(self::KEY, $now);

        if ($remember && ($key = $this->rememberKey($user))) {
            Cache::put($key, $now, self::limitSeconds() + 3600);
        }
    }

    /** The start a session restored from the remember cookie inherits, or null when it is unknown (so the person must sign in again). */
    public function inherited(Authenticatable $user): ?int
    {
        $key = $this->rememberKey($user);
        $start = $key ? Cache::get($key) : null;

        return is_numeric($start) ? (int) $start : null;
    }

    public function forget(Authenticatable $user): void
    {
        if ($key = $this->rememberKey($user)) {
            Cache::forget($key);
        }
    }

    public function expired(int $startedAt): bool
    {
        return now()->timestamp - $startedAt >= self::limitSeconds();
    }

    private function rememberKey(Authenticatable $user): ?string
    {
        $token = method_exists($user, 'getRememberToken') ? $user->getRememberToken() : null;

        return $token ? 'absolute-session:'.$user->getAuthIdentifier().':'.sha1((string) $token) : null;
    }
}
