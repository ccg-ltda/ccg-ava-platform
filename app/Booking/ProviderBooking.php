<?php

namespace App\Booking;

use Carbon\CarbonImmutable;

/** An appointment as an agenda reports it. `status` is `confirmed` or `requested` (still to be confirmed in that agenda), or `cancelled`. */
final class ProviderBooking
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $status,
        public readonly ?CarbonImmutable $startsAt = null,
        public readonly ?CarbonImmutable $endsAt = null,
    ) {}
}
