<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file attached to a message. Created only by `App\Services\MessageAttachments`; read only after checking its conversation and Workspace. */
class MessageAttachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['storage_path'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
