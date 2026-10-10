<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Criteria a user saved to reuse a module's search. Always read and written through
 * `App\Services\SavedFilters`, which starts from the user AND the Workspace of the session, so nobody sees or touches
 * the filters of another user or Workspace.
 */
#[Fillable(['workspace_id', 'user_id', 'scope', 'name', 'criteria'])]
class SavedFilter extends Model
{
    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
