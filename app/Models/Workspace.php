<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Services\ModuleAccess;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'plan_id', 'code', 'name', 'is_active'])]
class Workspace extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Every new client Workspace gets an explicit plan (the default one unless a plan is given), whichever flow creates it.
     * The column stays nullable and a Workspace without a plan still resolves to the default plan (ModuleAccess), so
     * older rows keep working; the administrative Workspace takes no plan.
     */
    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            if ($workspace->plan_id === null && ! $workspace->isAdministrative()) {
                $workspace->plan_id = app(ModuleAccess::class)->defaultPlan()?->id;
            }
        });
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** The administrative Workspace of Ava Platform (see config/workspace.php `admin_code`). */
    public function isAdministrative(): bool
    {
        return $this->code === static::normalizeCode((string) config('workspace.admin_code'));
    }

    /** Workspaces whose name, code or Organization contains the text (case-insensitive, wildcards are literal). */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        // "!" is the escape character so user input cannot act as a LIKE wildcard.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("lower(workspaces.name) like ? escape '!'", [$like])
            ->orWhereRaw("lower(workspaces.code) like ? escape '!'", [$like])
            ->orWhereHas('organization', fn (Builder $o) => $o->whereRaw("lower(organizations.name) like ? escape '!'", [$like])));
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = static::normalizeCode($value);
    }

    public function auditResource(): string
    {
        return 'workspace';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function auditFields(): array
    {
        return ['code' => 'Código', 'name' => 'Nombre', 'is_active' => 'Activo'];
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this;
    }

    public function auditSnapshot(Closure $get): array
    {
        return $this->auditBaseSnapshot($get) + [
            'Organización' => Organization::whereKey($get('organization_id'))->value('name'),
            'Plan' => Plan::whereKey($get('plan_id'))->value('name'),
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The commercial plan (null = the default plan, see ModuleAccess). */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function chatbots(): HasMany
    {
        return $this->hasMany(Chatbot::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function professionals(): HasMany
    {
        return $this->hasMany(Professional::class);
    }

    public function bookableResources(): HasMany
    {
        return $this->hasMany(BookableResource::class);
    }

    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function bookingCustomers(): HasMany
    {
        return $this->hasMany(BookingCustomer::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function bookingSetting(): HasOne
    {
        return $this->hasOne(BookingSetting::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(WorkspaceSetting::class);
    }

    /** The saved preferences, or the defaults of config/workspace.php for a Workspace that never saved them. */
    public function settingsOrDefault(): WorkspaceSetting
    {
        if (! $this->relationLoaded('settings') || $this->getRelation('settings') === null) {
            $this->setRelation('settings', $this->settings()->first() ?? new WorkspaceSetting(WorkspaceSetting::defaults()));
        }

        return $this->getRelation('settings');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * Workspaces that are enabled and whose Organization is enabled.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('workspaces.is_active', true)
            ->whereHas('organization', fn (Builder $q) => $q->where('is_active', true));
    }
}
