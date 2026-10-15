<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message addressed to customers of a Workspace. Status: draft -> prepared -> simulated | cancelled (config/campaigns.php).
 * It changes status only through App\Campaigns\CampaignManager. There is no `sent` status: nothing is ever really sent yet.
 */
#[Fillable(['message_template_id', 'name', 'body', 'channel', 'audience', 'filters', 'customer_ids'])]
class Campaign extends Model
{
    use Audited;

    public const DRAFT = 'draft';

    public const PREPARED = 'prepared';

    public const SIMULATED = 'simulated';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['filters' => 'array', 'customer_ids' => 'array', 'prepared_at' => 'datetime', 'simulated_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function auditResource(): string
    {
        return 'campaign';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['name' => 'Nombre', 'channel' => 'Canal', 'audience' => 'Destinatarios', 'status' => 'Estado'];
    }

    public function auditMasked(): array
    {
        return ['body' => 'Mensaje'];
    }
}
