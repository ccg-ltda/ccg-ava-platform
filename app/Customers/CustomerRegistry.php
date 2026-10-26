<?php

namespace App\Customers;

use App\Audit\AuditLogger;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Workspace;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes a customer with its identities and tags. Duplicates are detected by NORMALIZED identifiers (phone digits,
 * lower-case email, channel id) inside the Workspace and are REFUSED, never merged: when the data points to one customer a person is
 * told which; when it points to two different ones (ambiguity) the save is refused too. Merging records is a human decision.
 */
class CustomerRegistry
{
    public function __construct(private readonly AuditLogger $audit, private readonly CustomerTagCatalog $tags) {}

    /**
     * @param  array<string, mixed>  $data  columns of the customer
     * @param  list<string>  $tags
     * @param  list<array{channel: string, external_id: string}>  $identities
     */
    public function save(Workspace $workspace, array $data, array $tags, array $identities, ?Customer $customer = null): Customer
    {
        if ($customer) {
            $this->assertNotAnonymized($customer);
        }

        $phone = Customer::normalizePhone($data['phone'] ?? null);
        $email = Customer::normalizeEmail($data['email'] ?? null);
        $identities = $this->normalizedIdentities($identities);

        $this->assertNoDuplicate($workspace, $phone, PhoneNumber::e164($data['phone'] ?? null), $email, $identities, $customer);

        try {
            return $this->persist($workspace, $data, $tags, $identities, $customer);
        } catch (UniqueConstraintViolationException) {
            // Another request saved the same identifier between the check above and the write: the database is the last barrier.
            throw ValidationException::withMessages(['phone' => 'Otro usuario guardó un cliente con estos datos al mismo tiempo. Recarga y revisa.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $tags
     * @param  list<array{channel: string, external_id: string}>  $identities
     */
    private function persist(Workspace $workspace, array $data, array $tags, array $identities, ?Customer $customer): Customer
    {
        return DB::transaction(function () use ($workspace, $data, $tags, $identities, $customer) {
            $before = $customer ? $this->describe($customer) : null;

            $customer ??= new Customer(['workspace_id' => $workspace->id]);
            $customer->workspace_id = $workspace->id;
            $customer->fill($data)->save();

            $customer->identities()->delete();
            $customer->identities()->createMany(array_map(fn (array $identity) => $identity + ['workspace_id' => $workspace->id], $identities));
            $this->syncTags($workspace, $customer, $tags);

            $after = $this->describe($customer->refresh());

            if ($before !== null && $before !== $after) {
                $changes = [];
                foreach ($after as $field => $value) {
                    if ($before[$field] !== $value) {
                        $changes[] = AuditLogger::change($field, $before[$field], $value);
                    }
                }
                $this->audit->record('updated', 'customer', $customer->id, $customer->fullName(), $changes, $workspace);
            }

            return $customer;
        });
    }

    /**
     * Gives the customer exactly these tags, taken from the Workspace catalog (a new name is added to it, once, whatever its spelling). A tag the customer already
     * has stays even if it was deactivated; a deactivated tag cannot be given to someone else. Without the catalog tables the tags are plain text, as before.
     *
     * @param  list<string>  $names
     */
    private function syncTags(Workspace $workspace, Customer $customer, array $names): void
    {
        $names = collect($names)->map(fn (string $name) => CustomerTagCatalog::clean($name))->filter()->unique(fn (string $name) => CustomerTagCatalog::normalize($name))->values()->all();

        if (! $this->tags->ready()) {
            $customer->tags()->delete();
            $customer->tags()->createMany(array_map(fn (string $tag) => ['workspace_id' => $workspace->id, 'tag' => mb_strtolower($tag)], $names));

            return;
        }

        [$definitions, $created] = $this->tags->resolve($workspace, $names, $customer->tags()->whereNotNull('tag_id')->pluck('tag_id')->all());

        $customer->tags()->delete();
        $customer->tags()->createMany($definitions->map(fn ($tag) => ['workspace_id' => $workspace->id, 'tag_id' => $tag->id, 'tag' => $tag->name])->all());

        foreach ($created as $tag) {
            $this->audit->record('created', 'customer_tag', $tag->id, $tag->name, [AuditLogger::change('Nombre', null, $tag->name)], $workspace);
        }
    }

    public function setActive(Customer $customer, bool $active): void
    {
        $this->assertNotAnonymized($customer);
        $customer->update(['is_active' => $active]);
    }

    /**
     * Removes the personal data of ONE customer on request (name, contact data, notes, channel ids, tags): the record stays as an inactive
     * "Cliente anonimizado" with consent revoked, so what refers to it keeps its integrity and it can never be addressed again. It does NOT
     * touch the conversations, bookings or messages of that person (they belong to their own modules and policies). Irreversible.
     */
    public function anonymize(Customer $customer): void
    {
        $this->assertNotAnonymized($customer);

        DB::transaction(function () use ($customer) {
            $customer->identities()->delete();
            $customer->tags()->delete();
            $customer->forceFill([
                'first_name' => 'Cliente anonimizado', 'last_name' => null, 'phone' => null, 'email' => null, 'notes' => null,
                'consent_note' => null, 'preferred_channel' => null, 'marketing_consent' => Customer::CONSENT_REVOKED,
                'is_active' => false, 'anonymized_at' => now(),
            ])->save();

            $this->audit->record('anonymized', 'customer', $customer->id, $customer->fullName(), [AuditLogger::change('Datos personales', 'Presentes', 'Eliminados')], $customer->workspace);
        });
    }

    private function assertNotAnonymized(Customer $customer): void
    {
        if ($customer->isAnonymized()) {
            throw ValidationException::withMessages(['customer' => 'Este cliente fue anonimizado y ya no se puede modificar.']);
        }
    }

    /** What lives outside the customer row and is therefore not seen by the model audit (identities and tags). @return array<string, ?string> */
    private function describe(Customer $customer): array
    {
        return [
            'Canales e identificadores' => $customer->identities()->orderBy('channel')->orderBy('external_id')->get()->map(fn ($i) => "{$i->channel}:{$i->external_id}")->implode(', ') ?: null,
            'Etiquetas' => $customer->tags()->orderBy('tag')->pluck('tag')->implode(', ') ?: null,
        ];
    }

    /**
     * @param  list<array{channel: string, external_id: string}>  $identities
     * @return list<array{channel: string, external_id: string}>
     */
    private function normalizedIdentities(array $identities): array
    {
        $seen = [];

        foreach ($identities as $index => $identity) {
            $id = CustomerIdentity::normalize($identity['channel'], $identity['external_id']);

            if ($id === '') {
                throw ValidationException::withMessages(["identities.{$index}.external_id" => 'El identificador no es válido para ese canal.']);
            }

            $seen["{$identity['channel']}|{$id}"] = ['channel' => $identity['channel'], 'external_id' => $id];
        }

        return array_values($seen);
    }

    /** @param  list<array{channel: string, external_id: string}>  $identities */
    private function assertNoDuplicate(Workspace $workspace, ?string $phone, ?string $e164, ?string $email, array $identities, ?Customer $self): void
    {
        $others = fn () => $workspace->customers()->when($self, fn ($query) => $query->whereKeyNot($self->id));
        $byPhone = $phone ? $others()->where(fn ($query) => $query->where('phone_normalized', $phone)->when($e164, fn ($q) => $q->orWhere('phone_e164', $e164)))->first() : null;
        $byEmail = $email ? $others()->where('email_normalized', $email)->first() : null;

        if ($byPhone && $byEmail && $byPhone->id !== $byEmail->id) {
            throw ValidationException::withMessages([
                'phone' => "El teléfono ya pertenece a «{$byPhone->fullName()}» y el correo a «{$byEmail->fullName()}». Ava no combina clientes por su cuenta: revisa cuál es el correcto.",
                'email' => 'Los datos coinciden con dos clientes distintos.',
            ]);
        }

        if ($byPhone) {
            throw ValidationException::withMessages(['phone' => "Ya existe un cliente con este teléfono: «{$byPhone->fullName()}». Ábrelo para editarlo; Ava no combina clientes automáticamente."]);
        }

        if ($byEmail) {
            throw ValidationException::withMessages(['email' => "Ya existe un cliente con este correo: «{$byEmail->fullName()}». Ábrelo para editarlo; Ava no combina clientes automáticamente."]);
        }

        foreach ($identities as $index => $identity) {
            $owner = CustomerIdentity::where('workspace_id', $workspace->id)->where('channel', $identity['channel'])->where('external_id', $identity['external_id'])
                ->when($self, fn ($query) => $query->where('customer_id', '!=', $self->id))->with('customer')->first();

            if ($owner) {
                throw ValidationException::withMessages(["identities.{$index}.external_id" => "Ese identificador ya pertenece a «{$owner->customer->fullName()}»."]);
            }
        }
    }
}
