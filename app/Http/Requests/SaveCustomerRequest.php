<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Rules\InternationalPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update of a customer of the ACTIVE Workspace. Required: first name and at least ONE way to identify or contact them
 * (phone, email or a channel id): a record nobody can reach or recognise is useless. Everything else is optional.
 * Authorization is the `workspace.permission:create-customers` / `edit-customers` route middleware.
 */
class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // Editing: the phone the customer already has may come back unchanged (a historical number is not rewritten by editing the notes).
        $stored = $this->route('customer') ? $this->attributes->get('workspace')?->customers()->whereKey($this->route('customer'))->value('phone') : null;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32', new InternationalPhone($stored)],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'source' => ['required', Rule::in(array_keys(config('customers.sources')))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'marketing_consent' => ['required', Rule::in(array_keys(config('customers.consents')))],
            'consent_note' => ['nullable', 'string', 'max:255'],
            'preferred_channel' => ['nullable', Rule::in(config('customers.identity_channels'))],
            'tags' => ['nullable', 'array', 'max:'.config('customers.max_tags')],
            'tags.*' => ['string', 'max:40'],
            'identities' => ['nullable', 'array', 'max:10'],
            'identities.*.channel' => ['required', Rule::in(config('customers.identity_channels'))],
            'identities.*.external_id' => ['required', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $phone = Customer::normalizePhone($this->input('phone'));

            if (! $validator->errors()->any() && $phone === null && blank($this->input('email')) && $this->identities() === []) {
                $validator->errors()->add('phone', 'Indica al menos un teléfono, un correo o un identificador de canal.');
            }

            if (! $validator->errors()->any() && $this->input('marketing_consent') === 'granted' && blank($this->input('consent_note'))) {
                $validator->errors()->add('consent_note', 'Indica cómo o dónde se obtuvo el consentimiento.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['first_name' => 'el nombre', 'last_name' => 'los apellidos', 'phone' => 'el teléfono', 'email' => 'el correo', 'notes' => 'las notas', 'consent_note' => 'la constancia del consentimiento'];
    }

    /** @return array<string, mixed> the columns of the customer */
    public function customer(): array
    {
        $data = $this->validated();
        $clean = fn (string $key) => filled($data[$key] ?? null) ? trim($data[$key]) : null;

        return [
            'first_name' => trim($data['first_name']),
            'last_name' => $clean('last_name'),
            'phone' => $clean('phone'),
            'email' => $clean('email'),
            'source' => $data['source'],
            'notes' => $clean('notes'),
            'marketing_consent' => $data['marketing_consent'],
            'consent_note' => $clean('consent_note'),
            'preferred_channel' => $data['preferred_channel'] ?? null,
        ];
    }

    /** @return list<string> */
    public function tags(): array
    {
        return collect($this->validated('tags') ?? [])->map(fn (string $tag) => mb_strtolower(trim($tag)))->filter()->unique()->values()->all();
    }

    /** @return list<array{channel: string, external_id: string}> */
    public function identities(): array
    {
        return collect($this->input('identities', []))->filter(fn ($i) => is_array($i) && filled($i['channel'] ?? null) && filled($i['external_id'] ?? null))
            ->map(fn (array $i) => ['channel' => (string) $i['channel'], 'external_id' => (string) $i['external_id']])->values()->all();
    }
}
