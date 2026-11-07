<?php

namespace App\Payments\Gateways;

/** Something Ava can collect money for (an order today). The amount and currency are ALWAYS read from here, never from the browser. */
interface Payable
{
    /** Key in config('payments.payables'). */
    public function paymentType(): string;

    public function paymentId(): int;

    public function paymentWorkspaceId(): int;

    /** The module key a Workspace needs for this payable to exist at all (`orders`). */
    public function paymentModule(): string;

    /** Minor units (cents). */
    public function paymentAmountMinor(): int;

    public function paymentCurrency(): string;

    /** Short, non-sensitive text the customer sees and the provider receives. */
    public function paymentDescription(): string;

    /** Whether it may still be paid (a cancelled order may not). */
    public function paymentOpen(): bool;

    /** @return array{name: string, email: ?string, phone: ?string} */
    public function paymentCustomer(): array;
}
