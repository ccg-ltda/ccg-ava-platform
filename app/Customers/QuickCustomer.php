<?php

namespace App\Customers;

use App\Models\Customer;
use App\Models\Workspace;
use App\Rules\InternationalPhone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The ways OTHER modules (Agenda, Recordatorios) find and create a person of Clientes without keeping a second list: they search the central table and, when the person is
 * new, they create the SAME card through `CustomerRegistry` (so the duplicate rules, the audit and the normalized identifiers are the ones of the Clientes screen). Nothing here
 * writes a customer by itself: a duplicate is refused naming the existing card (never merged) and consent is `unknown` (which counts as NO) unless the person who is creating
 * the card records that the customer authorized messages and how.
 */
class QuickCustomer
{
    public function __construct(private readonly CustomerRegistry $registry, private readonly CustomerSearch $search) {}

    /**
     * Active cards of the Workspace that match a name, phone or e-mail, to pick one. Contact data is included because the person who picks is about to write to them.
     *
     * @return Collection<int, Customer>
     */
    public function find(Workspace $workspace, string $term, int $limit = 10): Collection
    {
        return $this->search->query($workspace, ['search' => $term, 'status' => 'active'])->whereNull('anonymized_at')->orderBy('first_name')->orderBy('last_name')->limit($limit)->get();
    }

    /**
     * A new card from the few fields a quick form asks: name, last name, phone and e-mail (at least one of the last two) and, optionally, that the customer authorized messages.
     *
     * @param  array<string, mixed>  $data  first_name, last_name?, phone?, email?, consent_granted?, consent_channel?, consent_note?
     *
     * @throws ValidationException
     */
    public function create(Workspace $workspace, array $data, string $source = 'manual'): Customer
    {
        $granted = filter_var($data['consent_granted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $clean = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32', new InternationalPhone],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            // Consent is explicit: when it is granted the CHANNEL it came through is required (the moment is stamped by the server); the note is optional detail.
            'consent_channel' => [$granted ? 'required' : 'nullable', Rule::in(array_keys(config('customers.consent_channels')))],
            'consent_note' => ['nullable', 'string', 'max:255'],
        ], [], ['first_name' => 'el nombre', 'last_name' => 'el apellido', 'phone' => 'el teléfono', 'email' => 'el correo', 'consent_note' => 'la constancia del consentimiento', 'consent_channel' => 'el canal del consentimiento'])
            ->after(function ($validator) use ($data) {
                if (Customer::normalizePhone($data['phone'] ?? null) === null && Customer::normalizeEmail($data['email'] ?? null) === null) {
                    $validator->errors()->add('phone', 'Indica al menos un teléfono o un correo.');
                }
            })->validate();

        $value = fn (string $key) => filled($clean[$key] ?? null) ? trim((string) $clean[$key]) : null;

        return $this->registry->save($workspace, [
            'first_name' => trim($clean['first_name']),
            'last_name' => $value('last_name'),
            'phone' => $value('phone'),
            'email' => $value('email'),
            'source' => $source,
            'notes' => null,
            'marketing_consent' => $granted ? Customer::CONSENT_GRANTED : 'unknown',
            'consent_note' => $granted ? $value('consent_note') : null,
            'consent_channel' => $granted ? $value('consent_channel') : null,
            'preferred_channel' => null,
        ], [], []);
    }
}
