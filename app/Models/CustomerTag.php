<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A free label on a customer (unique per customer). */
#[Fillable(['workspace_id', 'tag'])]
class CustomerTag extends Model
{
    public $timestamps = false;
}
