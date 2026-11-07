<?php

namespace App\Payments\Gateways;

/** What a provider's VERIFIED answer says. `Unknown` is "could not be asked or could not be understood": it never closes a transaction. */
enum GatewayOutcome: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Pending = 'pending';
    /** The provider says the payment was returned (voided) outside Ava, in its own portal. */
    case Refunded = 'refunded';
    case Unknown = 'unknown';
}
