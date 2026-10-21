<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The contract of a client Workspace with a plan. The plan decides WHICH modules; the subscription decides WHETHER the Workspace may use them now.
 * A Workspace without a subscription row is a legacy one and is governed by its plan alone (ModuleAccess). Never deleted: ending access
 * changes the status, the Workspace keeps all its data.
 */
class Subscription extends Model
{
    public const PENDING = 'pending';

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

    protected $fillable = ['workspace_id', 'plan_id', 'status', 'payment_status', 'payment_reference', 'activates_workspace', 'activated_at', 'grace_ends_at', 'ends_at'];

    protected function casts(): array
    {
        return ['activates_workspace' => 'boolean', 'activated_at' => 'datetime', 'grace_ends_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * Whether the subscription withdraws access. A first-time `pending` subscription is a REQUEST: it neither grants the chosen plan nor takes away what
     * the Workspace already has (a new customer's Workspace stays deactivated, `workspaces.is_active`, until its activation). A pending one that follows a
     * subscription that already ended (activated before) withdraws access, so registering again never reopens what ended without a payment.
     */
    public function withdrawsAccess(): bool
    {
        return in_array($this->status, self::WITHDRAWN, true) || ($this->status === self::PENDING && $this->activated_at !== null);
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
