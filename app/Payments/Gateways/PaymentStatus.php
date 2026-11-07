<?php

namespace App\Payments\Gateways;

/**
 * The state of a payment transaction. The ONLY allowed moves are the ones listed here; PaymentService refuses anything else and records every move.
 *  - An approved payment never goes back to pending or declined: it can only be refunded.
 *  - A failed query is `verification_required`, never a rejection.
 *  - A closed transaction (declined / cancelled / expired) can be re-opened to `approved` ONLY by a verified answer of the provider (the customer paid after Ava gave up).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Approved = 'approved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case VerificationRequired = 'verification_required';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Approved, self::Declined, self::Cancelled, self::Expired, self::VerificationRequired],
            self::Processing => [self::Approved, self::Declined, self::Cancelled, self::Expired, self::VerificationRequired],
            self::VerificationRequired => [self::Processing, self::Approved, self::Declined, self::Cancelled, self::Expired],
            self::Declined, self::Cancelled, self::Expired => [self::Approved],
            self::Approved => [self::RefundPending, self::Refunded],
            self::RefundPending => [self::Refunded, self::Approved],
            self::Refunded => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Still waiting for an answer: holds the payable (`active_key`) and is looked at by the reconciliation. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::VerificationRequired], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Processing => 'En proceso',
            self::Approved => 'Aprobado',
            self::Declined => 'Rechazado',
            self::Cancelled => 'Cancelado',
            self::Expired => 'Expirado',
            self::RefundPending => 'Reembolso en curso',
            self::Refunded => 'Reembolsado',
            self::VerificationRequired => 'Pendiente de verificación',
        };
    }
}
