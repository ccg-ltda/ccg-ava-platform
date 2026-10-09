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
        // Workspace (member, or superuser). Same error for both failures to avoid leaking info.
        // Deactivated accounts fail with the same generic error.
        $credentials = $this->only('email', 'password') + ['is_active' => true] + [
            fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('is_superuser', true)
                ->orWhereHas('workspaces', fn (Builder $w) => $w->whereKey($workspace?->getKey()))),
        ];

        if (! $workspace || ! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

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
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
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
