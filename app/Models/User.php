<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Notifications\ResetPasswordNotification;
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
    use Audited, HasFactory, HasRoles, Notifiable;

    /** Mirrors the column default so models built in memory are active too. */
    protected $attributes = ['is_active' => true];

    /** The Workspace this user is acting in during the current request (set by EnsureWorkspaceContext; never stored). */
    private ?Workspace $actingWorkspace = null;

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

    public function sendPasswordResetNotification($token): void
    {
        $this->notify((new ResetPasswordNotification($token))->forUser($this->id));
    }

    public function auditResource(): string
    {
        return 'user';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['name' => 'Nombre', 'email' => 'Correo', 'is_active' => 'Activo', 'is_superuser' => 'Superusuario'];
    }

    public function auditMasked(): array
    {
        return ['password' => 'Contraseña'];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function actingIn(Workspace $workspace): static
    {
        $this->actingWorkspace = $workspace;

        return $this;
    }

    /**
     * Whether this user is a superuser acting inside the given Workspace with platform privileges: only inside the administrative
     * Workspace (Desarrollo_DEV). Being superuser lets the account ENTER any Workspace, but inside a client Workspace it carries no
     * platform privilege (role catalog, Workspaces, Organizations, cross-Workspace administration). Single definition of that rule.
     */
    public function isSuperuserIn(Workspace $workspace): bool
    {
        return $this->is_superuser && $workspace->isAdministrative();
    }

    /** The same rule for the Workspace of the current request (false before EnsureWorkspaceContext has bound one). */
    public function isPlatformSuperuser(): bool
    {
        return $this->actingWorkspace !== null && $this->isSuperuserIn($this->actingWorkspace);
    }

    /**
     * Membership is the authorization source. A superuser may enter the administrative Workspace without one; a client Workspace
     * only through an explicit membership (audited in that Workspace), so being a superuser never opens a client's private data.
     */
    public function canAccessWorkspace(Workspace $workspace): bool
    {
        return $this->isSuperuserIn($workspace)
            || $this->workspaces()->whereKey($workspace->getKey())->exists();
    }

    /**
     * Role of the user inside the given Workspace (null when none).
     * A superuser without an explicit membership acts as admin in the administrative Workspace only.
     */
    public function roleInWorkspace(Workspace $workspace): ?string
    {
        $role = $this->workspaces()->whereKey($workspace->getKey())->first()?->pivot->role;

        return $role ?? ($this->isSuperuserIn($workspace) ? 'admin' : null);
    }
}
