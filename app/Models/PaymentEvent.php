<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment-provider event received and what was done with it (history and diagnosis). Identifiers and statuses only: never card data or secrets. */
class PaymentEvent extends Model
{
    protected $guarded = [];

    public function signupRequest(): BelongsTo
    {
        return $this->belongsTo(SignupRequest::class);
    }
}
