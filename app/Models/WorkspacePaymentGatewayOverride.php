<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An administrative exception of one Workspace over its plan: `grant` adds a gateway the plan lacks, `revoke` removes one it includes. Only platform administration writes it. */
#[Fillable(['workspace_id', 'gateway', 'mode', 'reason', 'created_by'])]
class WorkspacePaymentGatewayOverride extends Model
{
    public const GRANT = 'grant';

    public const REVOKE = 'revoke';

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
