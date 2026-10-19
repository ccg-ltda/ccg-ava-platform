<?php

namespace App\Http\Requests\Concerns;

use App\Rules\InternationalPhone;
use App\Services\AgentConversationContext;
use Carbon\CarbonImmutable;

/**
 * The shared pieces of every request that books or moves an appointment (admin page, public page, Ava Chat): the same
 * validation of who books and when, so the three entry points can never disagree. Only the Workspace rules about WHICH contact
 * data are mandatory live in AppointmentService (they depend on who is asking).
 */
trait BookingInput
{
    /** An id of Ava (a number) or of an external agenda (letters, digits, dash, underscore): never an array or free text. */
    public const ID = 'regex:/^[A-Za-z0-9_-]{1,64}$/';

    /** @return array<string, array<int, mixed>> */
    protected function customerRules(): array
    {
        return [
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'min:2', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:32', new InternationalPhone(null, $this->verifiedContactDigits())],
            'customer.email' => ['nullable', 'email:rfc', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function slotRules(): array
    {
        return [
            'professional_id' => ['nullable', self::ID],
            // The exact format of the starts Ava offers (UTC ISO 8601): free text such as "tomorrow" is not a start.
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sp'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('starts_at'), 'UTC')->utc();
    }

    /** Only for the automation of a chatbot (agent API): see AgentConversationContext::verifiedPhoneDigits. */
    private function verifiedContactDigits(): ?string
    {
        return app(AgentConversationContext::class)->verifiedPhoneDigits($this);
    }

    /**
     * D-TEL: on WhatsApp the phone of the person who writes is the id of the conversation, which Ava already holds and the workflow never
     * receives. When the workflow sends none, that verified number is the booking's phone (nothing is guessed: a phone the workflow does
     * send is kept as it is, and any other channel, web included, must supply a phone or an email).
     *
     * @return array{name: string, phone: ?string, email: ?string}
     */
    public function customer(): array
    {
        $customer = $this->validated('customer');
        $verified = $this->verifiedContactDigits();

        if (! filled($customer['phone'] ?? null) && $verified !== null) {
            $customer['phone'] = $verified;
        }

        if (filled($customer['phone'] ?? null) && trim($customer['phone']) === $verified) {
            $customer['phone'] = '+'.trim($customer['phone']);
        }

        return ['name' => trim($customer['name']), 'phone' => filled($customer['phone'] ?? null) ? trim($customer['phone']) : null, 'email' => filled($customer['email'] ?? null) ? trim($customer['email']) : null];
    }
}
