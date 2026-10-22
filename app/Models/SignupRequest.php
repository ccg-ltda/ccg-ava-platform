<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pending contract of a company that is signing up. It holds what the wizard collected and a snapshot of the price chosen; the Organization,
 * Workspace, administrator and subscription are created only after a verified payment (SignupService). The password is stored hashed.
 */
class SignupRequest extends Model
{
    public const DRAFT = 'draft';

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const QUOTE_REQUESTED = 'quote_requested';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Statuses in which the request still reserves its Workspace code and its tax id. */
    public const OPEN = [self::DRAFT, self::AWAITING_PAYMENT, self::QUOTE_REQUESTED];

    protected $fillable = [
        'public_id', 'email', 'email_verified_at', 'admin_name', 'password', 'company_name', 'tax_id', 'phone', 'workspace_code', 'workspace_name',
        'solution_key', 'plan_id', 'price_cents', 'currency', 'billing_period', 'status', 'accepted_documents', 'modules_snapshot', 'accepted_at',
        'payment_reference', 'paid_at', 'organization_id', 'workspace_id', 'user_id', 'expires_at', 'ip_address', 'limits_snapshot',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime', 'accepted_at' => 'datetime', 'paid_at' => 'datetime', 'expires_at' => 'datetime',
            'accepted_documents' => 'array', 'modules_snapshot' => 'array', 'limits_snapshot' => 'array', 'price_cents' => 'integer', 'password' => 'hashed',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true) && $this->expires_at->isFuture();
    }
}
