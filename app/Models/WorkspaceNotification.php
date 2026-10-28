<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A persisted notification of ONE Workspace. Who reads it and what they have read is decided by App\Services\Notifications, never by the client. */
class WorkspaceNotification extends Model
{
    protected $guarded = [];
}
