<?php

namespace App\Booking;

use App\Customers\QuickCustomer;
use App\Models\Customer;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * How the STAFF's agenda uses Clientes, the central record of people: it can search a card and tie the appointment to it, or save the person as a new card (through the same
 * `CustomerRegistry` rules as the Clientes screen). Both are optional and only for a Workspace whose plan includes Clientes and a role that may use them: Agenda keeps working
 * exactly as before without them. The public booking page and Ava Chat never use this (a visitor cannot pick a card, and Ava creates no card by itself).
 */
class AgendaClients
{
    public function __construct(private readonly QuickCustomer $clients, private readonly ModuleAccess $modules) {}

    /** Whether the Workspace's plan includes Clientes: then people are managed ONLY there and Agenda keeps no listing of its own (a Workspace without the module still sees who booked). */
    public function central(Workspace $workspace): bool
    {
        return $this->modules->allows($workspace, 'customers');
    }

    /** What the dialog may offer: searching Clientes needs the module and `view-customers`; saving a new card also needs `create-customers`. @param list<string> $permissions @return array{enabled: bool, canCreate: bool} */
    public function capabilities(Workspace $workspace, ?array $permissions): array
    {
        // `null` = the assistant (Ava Chat), whose token is already bound to the Workspace and which has no role: it may link or save a card whenever the plan has Clientes.
        if ($permissions === null) {
            $on = $this->modules->allows($workspace, 'customers') && Schema::hasColumn('booking_customers', 'customer_id');

            return ['enabled' => $on, 'canCreate' => $on];
        }

        // The link column comes with a migration: until it is applied the dialog offers nothing it could not save.
        $enabled = $this->modules->allows($workspace, 'customers') && in_array('view-customers', $permissions, true) && Schema::hasColumn('booking_customers', 'customer_id');

        return ['enabled' => $enabled, 'canCreate' => $enabled && in_array('create-customers', $permissions, true)] + ($enabled ? ['consentChannels' => config('customers.consent_channels')] : []);
    }

    /** @param list<string> $permissions @return list<array{id: int, name: string, phone: ?string, email: ?string}> */
    public function search(Workspace $workspace, array $permissions, string $term): array
    {
        abort_unless($this->capabilities($workspace, $permissions)['enabled'], 403);

        return $this->clients->find($workspace, $term)->map(fn (Customer $card) => ['id' => $card->id, 'name' => $card->fullName(), 'phone' => $card->phone, 'email' => $card->email, 'consent' => $card->marketing_consent, 'consentLabel' => config('customers.consents')[$card->marketing_consent] ?? null])->all();
    }

    /**
     * The card an appointment is tied to: the one the staff picked (it must belong to THIS Workspace and be active) or a new one they asked to save; null when they did neither.
     *
     * @param  list<string>|null  $permissions  null = the assistant (see `capabilities`); it can never record consent
     * @param  array<string, mixed>  $typed  what the dialog sent as `customer` (name, phone, email)
     * @param  array<string, mixed>  $consent  `consent_granted`, `consent_channel`, `consent_note`: only used when a NEW card is saved; booking never implies consent
     *
     * @throws ValidationException
     */
    public function resolve(Workspace $workspace, ?array $permissions, ?int $pickedId, bool $save, array $typed, array $consent = []): ?Customer
    {
        if ($pickedId === null && ! $save) {
            return null;
        }

        $can = $this->capabilities($workspace, $permissions);

        if (! $can['enabled'] || ($pickedId === null && ! $can['canCreate'])) {
            throw ValidationException::withMessages(['customer.name' => 'No tienes acceso a Clientes para vincular o crear esta ficha.']);
        }

        if ($pickedId !== null) {
            $card = $workspace->customers()->whereNull('anonymized_at')->where('is_active', true)->find($pickedId);

            return $card ?? throw ValidationException::withMessages(['customer.name' => 'Ese cliente no existe en este Workspace o está desactivado. Búscalo de nuevo.']);
        }

        $consent = $permissions === null ? [] : $consent; // an assistant never records consent: only a person does, explicitly
        $name = trim((string) ($typed['name'] ?? ''));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);

        try {
            return $this->clients->create($workspace, ['first_name' => $first, 'last_name' => $last, 'phone' => $typed['phone'] ?? null, 'email' => $typed['email'] ?? null] + array_filter(['consent_granted' => $consent['consent_granted'] ?? null, 'consent_channel' => $consent['consent_channel'] ?? null, 'consent_note' => $consent['consent_note'] ?? null], fn ($value) => $value !== null), 'booking');
        } catch (ValidationException $e) {
            // The card's own messages (a duplicate names the existing client) under the fields of the appointment dialog.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [str_starts_with($key, 'consent_') ? $key : 'customer.'.($key === 'first_name' ? 'name' : $key) => $messages])->all());
        }
    }
}
