<?php

namespace App\Booking;

use Carbon\CarbonImmutable;

/** What is needed to book: the service, an optional professional, the start (UTC) and who books. Already validated. */
final class BookingRequest
{
    /** @param  array{name: string, phone: ?string, email: ?string}  $customer */
    public function __construct(
        public readonly int|string $serviceId,
        public readonly int|string|null $professionalId,
        public readonly CarbonImmutable $startsAt,
        public readonly array $customer,
        public readonly ?string $notes = null,
        public readonly string $channel = 'admin',
        public readonly ?int $conversationId = null,
    ) {}
}
