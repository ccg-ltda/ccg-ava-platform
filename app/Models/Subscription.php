<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * The contract of a client Workspace with a plan. The plan decides WHICH modules; the subscription decides WHETHER the Workspace may use them now.
 * A Workspace without a subscription row is a legacy one and is governed by its plan alone (ModuleAccess). Never deleted: ending access
 * changes the status, the Workspace keeps all its data.
 */
class Subscription extends Model
{
    public const PENDING = 'pending';

    /** A free trial granted by hand to this one Workspace (trial_* columns). It grants access like `active`; its end is a decision, not an automatic cut. */
    public const TRIALING = 'trialing';

    public const ACTIVE = 'active';

    public const GRACE = 'grace';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Statuses that withdraw access to every module (the data stays). */
    public const WITHDRAWN = [self::SUSPENDED, self::CANCELLED, self::EXPIRED];

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_CONFIRMED = 'confirmed';

    public const PAYMENT_FAILED = 'failed';

    protected $fillable = ['workspace_id', 'plan_id', 'status', 'payment_status', 'payment_reference', 'activates_workspace', 'activated_at', 'grace_ends_at', 'ends_at', 'terms', 'terms_source', 'terms_captured_at', 'trial_starts_at', 'trial_ends_at', 'trial_reason', 'trial_granted_by'];

    protected function casts(): array
    {
        return [
            'activates_workspace' => 'boolean', 'activated_at' => 'datetime', 'grace_ends_at' => 'datetime', 'ends_at' => 'datetime',
            'terms' => 'array', 'terms_captured_at' => 'datetime', 'trial_starts_at' => 'datetime', 'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Whether the subscription withdraws access. A first-time `pending` subscription is a REQUEST: it neither grants the chosen plan nor takes away what
     * the Workspace already has (a new customer's Workspace stays deactivated, `workspaces.is_active`, until its activation). A pending one that follows a
     * subscription that already ended (activated before) withdraws access, so registering again never reopens what ended without a payment.
     */
    public function withdrawsAccess(): bool
    {
        return in_array($this->status, self::WITHDRAWN, true) || ($this->status === self::PENDING && $this->activated_at !== null) || ($this->status === self::TRIALING && $this->trialExpired());
    }

    /** Whether the database has the columns of the frozen conditions and the trials (migration `freeze_contract_terms_and_trials_on_subscriptions`). */
    public static function termsReady(): bool
    {
        return Schema::hasColumn('subscriptions', 'terms') && Schema::hasColumn('subscriptions', 'trial_ends_at');
    }

    /** The conditions accepted at contracting are on file. Null terms = historical and incomplete: nothing is invented for them. */
    public function hasFrozenTerms(): bool
    {
        return is_array($this->terms) && $this->terms !== [];
    }

    /**
     * A trial whose end date passed, whether the scheduled command already moved it to `expired` or not. The access is withdrawn by `withdrawsAccess` the moment
     * the date passes (the server decides on every request, not the command's schedule); the command only makes the new state explicit and audited.
     */
    public function trialExpired(): bool
    {
        return in_array($this->status, [self::TRIALING, self::EXPIRED], true) && $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
