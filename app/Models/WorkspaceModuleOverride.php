<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An authorized exception of one Workspace over its plan: `grant` adds a module the plan lacks, `revoke` removes one it includes. */
class WorkspaceModuleOverride extends Model
{
    public const GRANT = 'grant';

    public const REVOKE = 'revoke';

    protected $fillable = ['workspace_id', 'module_id', 'mode', 'reason', 'created_by'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
