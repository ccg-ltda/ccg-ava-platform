<?php

namespace App\Http\Controllers;

use App\Customers\CustomerRegistry;
use App\Customers\CustomerSearch;
use App\Http\Requests\SaveCustomerRequest;
use App\Models\Appointment;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Workspace;
use App\Services\ListPagination;
use App\Services\ModuleAccess;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clientes: the customer records of the active Workspace (never deleted, only deactivated). Conversations and appointments are shown
 * from their own modules when the Workspace has them and the role may read them; they are never copied into the customer.
 */
class CustomerController extends Controller
{
    private const FILTERS = ['search', 'source', 'consent', 'tag', 'status'];

    public function __construct(private readonly CustomerRegistry $registry, private readonly CustomerSearch $search, private readonly ModuleAccess $modules) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $filters = collect(self::FILTERS)->mapWithKeys(fn (string $key) => [$key => trim((string) $request->query($key, ''))])->all();
        $page = ListPagination::paginate($this->search->query($workspace, $filters)->with(['tags:id,customer_id,tag', 'identities:id,customer_id,channel,external_id'])->orderBy('first_name')->orderBy('last_name')->orderBy('id'), $request, 'customers');

        return Inertia::render('Customers/Index', [
            'customers' => ['data' => $page->getCollection()->map(fn (Customer $customer) => $this->summary($customer))->values()->all(), 'meta' => ListPagination::meta($page)],
            'filters' => $filters + ['perPage' => ListPagination::size($request, 'customers')],
            'perPageOptions' => ListPagination::OPTIONS,
            'options' => $this->options($workspace),
            'total' => $workspace->customers()->count(),
        ]);
    }

    public function show(Request $request, int $customer): Response
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->customers()->with(['tags', 'identities'])->findOrFail($customer);

        return Inertia::render('Customers/Show', [
            'customer' => $this->summary($model) + ['consentAt' => $model->consent_at?->toIso8601String(), 'createdAt' => $model->created_at->toIso8601String()],
            'conversations' => $this->conversations($request, $workspace, $model),
            'appointments' => $this->appointments($request, $workspace, $model),
            'campaigns' => $this->campaigns($request, $workspace, $model),
            'options' => $this->options($workspace),
        ]);
    }

    public function store(SaveCustomerRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $customer = $this->registry->save($workspace, $request->customer(), $request->tags(), $request->identities());

        return redirect()->route('customers.show', $customer->id)->with('success', 'Cliente creado correctamente');
    }

    public function update(SaveCustomerRequest $request, int $customer): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->registry->save($workspace, $request->customer(), $request->tags(), $request->identities(), $workspace->customers()->findOrFail($customer));

        return back()->with('success', 'Cliente actualizado correctamente');
    }

    public function activate(Request $request, int $customer): RedirectResponse
    {
        $this->registry->setActive($request->attributes->get('workspace')->customers()->findOrFail($customer), true);

        return back()->with('success', 'Cliente activado correctamente');
    }

    public function deactivate(Request $request, int $customer): RedirectResponse
    {
        $this->registry->setActive($request->attributes->get('workspace')->customers()->findOrFail($customer), false);

        return back()->with('success', 'Cliente desactivado correctamente');
    }

    public function anonymize(Request $request, int $customer): RedirectResponse
    {
        $this->registry->anonymize($request->attributes->get('workspace')->customers()->findOrFail($customer));

        return back()->with('success', 'Datos personales eliminados. Las conversaciones y reservas de esta persona no se modifican.');
    }

    /** @return array<string, mixed> */
    private function summary(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'fullName' => $customer->fullName(),
            'phone' => $customer->phone,
            'email' => $customer->email,
            'source' => $customer->source,
            'consent' => $customer->marketing_consent,
            'preferredChannel' => $customer->preferred_channel,
            'isActive' => $customer->is_active,
            'anonymized' => $customer->isAnonymized(),
            'notes' => $customer->notes,
            'consentNote' => $customer->consent_note,
            'tags' => $customer->tags->pluck('tag')->sort()->values()->all(),
            'identities' => $customer->identities->map(fn ($i) => ['channel' => $i->channel, 'external_id' => $i->external_id])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function options(Workspace $workspace): array
    {
        return [
            'sources' => config('customers.sources'),
            'consents' => config('customers.consents'),
            'channels' => collect(config('customers.identity_channels'))->mapWithKeys(fn (string $channel) => [$channel => config("chatbots.channels.{$channel}.label", ucfirst($channel))])->all(),
            'tags' => $this->search->tags($workspace),
        ];
    }

    /** Only when the Workspace has Conversaciones and the role may read them; matched through the channel ids saved on the customer. @return ?list<array<string, mixed>> */
    private function conversations(Request $request, Workspace $workspace, Customer $customer): ?array
    {
        if (! $this->mayRead($request, 'conversations', 'view-conversations')) {
            return null;
        }

        if ($customer->identities->isEmpty()) {
            return [];
        }

        return $workspace->conversations()->where(function ($query) use ($customer) {
            foreach ($customer->identities as $identity) {
                $query->orWhere(fn ($q) => $q->where('channel', $identity->channel)->where('contact_id', $identity->external_id));
            }
        })->orderByDesc('last_message_at')->limit(20)->get()->map(fn (Conversation $conversation) => [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'handling' => $conversation->handling,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
        ])->all();
    }

    /** Only when the Workspace has Agenda y reservas and the role may read it; matched by the same normalized phone or email. @return ?list<array<string, mixed>> */
    private function appointments(Request $request, Workspace $workspace, Customer $customer): ?array
    {
        if (! $this->mayRead($request, 'bookings', 'view-bookings')) {
            return null;
        }

        // The same normalized phone or email as the person who booked, or a booking made from one of this customer's own conversations.
        // A booking stores the digits of the number it was made with. They match when they are the SAME digits as the customer's number: its
        // verified international digits when it has them (plus the same number written with the "00" call prefix, which is explicit), else
        // its historical digits. A local number without a country prefix never matches an international one: the country is unknown.
        $phones = $customer->phone_e164 ? [PhoneNumber::digits($customer->phone_e164), '00'.PhoneNumber::digits($customer->phone_e164)] : array_filter([$customer->phone_normalized]);
        $ids = $customer->phone_normalized === null && $customer->email_normalized === null ? collect() : $workspace->bookingCustomers()->where(function ($query) use ($customer, $phones) {
            $query->when($phones, fn ($q, $values) => $q->orWhereIn('phone', $values))
                ->when($customer->email_normalized, fn ($q, $email) => $q->orWhereRaw('lower(email) = ?', [$email]));
        })->pluck('id');
        $conversationIds = $this->conversationIds($workspace, $customer);

        return Appointment::where('workspace_id', $workspace->id)
            ->where(fn ($query) => $query->whereIn('customer_id', $ids)->orWhereIn('conversation_id', $conversationIds))
            ->orderByDesc('starts_at')->limit(20)->get()
            ->map(fn (Appointment $appointment) => ['id' => $appointment->id, 'service' => $appointment->service_name, 'location' => $appointment->location_name, 'price' => $appointment->price_amount === null ? null : (float) $appointment->price_amount, 'currency' => $appointment->price_currency, 'startsAt' => $appointment->starts_at->toIso8601String(), 'status' => $appointment->status])->all();
    }

    /** The campaigns that addressed this customer (only the fact and the outcome). Only with the module and its permission. @return ?list<array<string, mixed>> */
    private function campaigns(Request $request, Workspace $workspace, Customer $customer): ?array
    {
        if (! $this->mayRead($request, 'campaigns', 'view-campaigns')) {
            return null;
        }

        return CampaignRecipient::where('workspace_id', $workspace->id)->where('customer_id', $customer->id)->with('campaign:id,workspace_id,name,status')
            ->orderByDesc('id')->limit(20)->get()->map(fn (CampaignRecipient $recipient) => [
                'id' => $recipient->campaign_id,
                'name' => $recipient->campaign->name,
                'status' => $recipient->status,
                'reason' => $recipient->exclusion_reason,
            ])->all();
    }

    /** @return list<int> ids of the Workspace's conversations that belong to this customer through their saved channel ids */
    private function conversationIds(Workspace $workspace, Customer $customer): array
    {
        if ($customer->identities->isEmpty()) {
            return [];
        }

        return $workspace->conversations()->where(function ($query) use ($customer) {
            foreach ($customer->identities as $identity) {
                $query->orWhere(fn ($q) => $q->where('channel', $identity->channel)->where('contact_id', $identity->external_id));
            }
        })->pluck('id')->all();
    }

    private function mayRead(Request $request, string $module, string $permission): bool
    {
        return in_array($module, $this->modules->forRequest($request), true) && in_array($permission, $request->attributes->get('workspace_permissions', []), true);
    }
}
