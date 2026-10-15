<?php

namespace App\Http\Requests\Concerns;

use App\Rules\InternationalPhone;
use App\Support\PhoneNumber;
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

    /**
     * Only for the automation of a chatbot (agent API): the id of a WhatsApp conversation is a full international number that Meta
     * writes without the "+", so a phone equal to THAT conversation's id is international even without it. Anyone else (staff, the
     * public page) must type the "+": nothing here infers a country.
     */
    private function verifiedContactDigits(): ?string
    {
        if (! $this->attributes->has('chatbot')) {
            return null;
        }

        $execution = $this->attributes->get('execution');
        $channel = $execution ? $execution->conversation->channel : $this->input('channel');
        $contact = $execution ? $execution->conversation->contact_id : $this->input('contact_id');

        return $channel === 'whatsapp' && is_string($contact) && PhoneNumber::e164('+'.$contact) !== null ? $contact : null;
    }

    /** @return array{name: string, phone: ?string, email: ?string} */
    public function customer(): array
    {
        $customer = $this->validated('customer');

        if (filled($customer['phone'] ?? null) && trim($customer['phone']) === $this->verifiedContactDigits()) {
            $customer['phone'] = '+'.trim($customer['phone']);
        }

        return ['name' => trim($customer['name']), 'phone' => filled($customer['phone'] ?? null) ? trim($customer['phone']) : null, 'email' => filled($customer['email'] ?? null) ? trim($customer['email']) : null];
    }
}
