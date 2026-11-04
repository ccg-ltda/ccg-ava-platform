<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who books: only what is needed to identify and contact them (name, phone, email). Not a CRM: the people of the Workspace live in Clientes (`customers`), and `customer_id` says
 * WHICH card this person is when staff picked or created it from the agenda (null for the rest: a visitor of the public page or Ava Chat is never matched by itself).
 */
#[Fillable(['name', 'phone', 'email', 'customer_id'])]
class BookingCustomer extends Model
{
    /** The Clientes card this person of Agenda is tied to, if any. */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'customer_id');
    }

    /** Digits only, so "+57 300 111" and "57300111" are the same person. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }
}
