<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One customer of a prepared campaign and what happened to them. Written only by App\Campaigns\CampaignManager. */
#[Fillable(['workspace_id', 'customer_id', 'status', 'exclusion_reason', 'error', 'processed_at'])]
class CampaignRecipient extends Model
{
    public const PENDING = 'pending';

    public const EXCLUDED = 'excluded';

    public const SIMULATED = 'simulated';

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
