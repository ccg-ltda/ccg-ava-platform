<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'remember_token'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /** Mirrors the column default so models built in memory are active too. */
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::creating(function ($user) {
            if (is_null($user->email_verified_at)) {
                $user->email_verified_at = now();
            }
            if (is_null($user->remember_token)) {
                $user->remember_token = Str::random(10);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superuser' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * Membership is the authorization source. Superusers may enter any Workspace.
     */
    public function canAccessWorkspace(Workspace $workspace): bool
    {
        return $this->is_superuser
            || $this->workspaces()->whereKey($workspace->getKey())->exists();
    }

    /**
     * Role of the user inside the given Workspace (null when none).
     * A superuser without an explicit membership acts as admin.
     */
    public function roleInWorkspace(Workspace $workspace): ?string
    {
        $role = $this->workspaces()->whereKey($workspace->getKey())->first()?->pivot->role;

        return $role ?? ($this->is_superuser ? 'admin' : null);
    }
}
