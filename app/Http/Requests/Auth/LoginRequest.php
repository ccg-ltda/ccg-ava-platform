<?php

namespace App\Http\Requests\Auth;

use App\Models\Workspace;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $workspace = Workspace::available()->find($this->session()->get('pre_login_workspace_id'));

        // The user is only logged in if credentials match AND the user may enter the selected
        // Workspace (member, or superuser entering the administrative Workspace). Same error for both failures to avoid leaking info.
        // Deactivated accounts fail with the same generic error.
        $credentials = $this->only('email', 'password') + ['is_active' => true] + [
            fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->when($workspace?->isAdministrative(), fn (Builder $admin) => $admin->where('is_superuser', true))
                ->orWhereHas('workspaces', fn (Builder $w) => $w->whereKey($workspace?->getKey()))),
        ];

        if (! $workspace || ! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), (int) config('auth.login_throttle.account_decay_seconds'));
            RateLimiter::hit($this->ipThrottleKey(), (int) config('auth.login_throttle.ip_decay_seconds'));

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $account = RateLimiter::tooManyAttempts($this->throttleKey(), (int) config('auth.login_throttle.account_max_attempts'));
        $ip = RateLimiter::tooManyAttempts($this->ipThrottleKey(), (int) config('auth.login_throttle.ip_max_attempts'));

        if (! $account && ! $ip) {
            return;
        }

        event(new Lockout($this));

        $seconds = max($account ? RateLimiter::availableIn($this->throttleKey()) : 0, $ip ? RateLimiter::availableIn($this->ipThrottleKey()) : 0);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /** The failures of ALL accounts from one address (a successful login never clears it). */
    public function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        // Deliberately independent of the selected Workspace: changing it must not reset the limit.
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
