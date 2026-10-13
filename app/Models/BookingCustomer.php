<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Who books: only what is needed to identify and contact them (name, phone, email). Not a CRM. */
#[Fillable(['name', 'phone', 'email'])]
class BookingCustomer extends Model
{
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
