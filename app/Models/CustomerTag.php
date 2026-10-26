<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A tag on a customer (unique per customer). `tag_id` is its definition in the Workspace catalog; `tag` is a copy of the definition's name. */
#[Fillable(['workspace_id', 'tag_id', 'tag'])]
class CustomerTag extends Model
{
    public $timestamps = false;
}
