<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A one-time code sent to an email to prove it can receive mail. Only a keyed hash of the code is stored; see SignupVerifier. */
class SignupVerification extends Model
{
    protected $fillable = ['email', 'code_hash', 'attempts', 'sends', 'last_sent_at', 'expires_at', 'verified_at', 'consumed_at', 'ip_address'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime', 'expires_at' => 'datetime', 'verified_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
