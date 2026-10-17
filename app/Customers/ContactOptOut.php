<?php

namespace App\Customers;

use App\Audit\AuditLogger;
use App\Models\Customer;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Support\Str;
use Normalizer;

/**
 * A contact asks to stop receiving messages by writing one word (STOP, BAJA...). Ava records it where consent lives: the customer the contact is
 * linked to becomes `revoked`, which every campaign already respects. It is deliberately narrow:
 *  - only an EXACT keyword counts (a sentence that contains it changes nothing) and only for a text message of the contact;
 *  - it needs Clientes in the plan and a customer linked to that contact (by channel identity or, on WhatsApp, by the verified number); if there is
 *    none, nothing is created (a contact with no record cannot be written to by a campaign anyway);
 *  - it only ever revokes: it never grants, never re-activates, never touches an anonymized record;
 *  - it is audited as an automatic event of the contact, without the customer's name or number, and it sends nothing back.
 */
class ContactOptOut
{
    public function __construct(private readonly AuditLogger $audit, private readonly ModuleAccess $modules) {}

    public function isKeyword(?string $text): bool
    {
        // Compatibility normalization first, so a full-width "ＳＴＯＰ" is the same word; then case, accents, punctuation, emoji and invisible characters.
        $text = class_exists(Normalizer::class) ? (Normalizer::normalize((string) $text, Normalizer::FORM_KC) ?: (string) $text) : (string) $text;
        $word = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::ascii(mb_strtolower($text))));

        return $word !== '' && in_array($word, array_map(fn (string $keyword) => Str::ascii(mb_strtolower($keyword)), (array) config('customers.opt_out_keywords')), true);
    }

    /** @return int how many customers were revoked */
    public function apply(Workspace $workspace, string $channel, string $contactId, ?string $text): int
    {
        if (! $this->isKeyword($text) || ! $this->modules->allows($workspace, 'customers')) {
            return 0;
        }

        $customers = $workspace->customers()->whereNull('anonymized_at')->where('marketing_consent', '!=', Customer::CONSENT_REVOKED)
            ->where(fn ($query) => $query
                ->whereHas('identities', fn ($identity) => $identity->where('channel', $channel)->where('external_id', $contactId))
                // On WhatsApp the id of the conversation IS the number, issued by Meta: it identifies the customer even when only the number was typed.
                ->when($channel === 'whatsapp' && ctype_digit($contactId), fn ($q) => $q->orWhere('phone_normalized', $contactId)->orWhere('phone_e164', '+'.$contactId)))
            ->get();

        foreach ($customers as $customer) {
            $before = $customer->marketing_consent;
            $customer->forceFill(['marketing_consent' => Customer::CONSENT_REVOKED, 'consent_note' => "Baja pedida por el contacto por mensaje ({$channel})"])->save();
            $this->audit->system('updated', 'customer', $customer->id, $customer->auditLabel(), [AuditLogger::change('Consentimiento', $before, Customer::CONSENT_REVOKED)], $workspace, 'Contacto (baja por mensaje)');
        }

        return $customers->count();
    }
}
