<?php

namespace App\Http\Controllers;

use App\Customers\QuickCustomer;
use App\Http\Requests\SaveReminderRequest;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\ReminderAttempt;
use App\Reminders\ReminderChannels;
use App\Reminders\ReminderRulePresenter;
use App\Reminders\ReminderService;
use App\Reminders\ReminderVariables;
use App\Services\ListPagination;
use App\Services\ModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recordatorios: the commercial follow-ups of the ACTIVE Workspace (taken from the request context, never from the client). Reading is `view-reminders`; creating, editing and
 * cancelling each have their own permission. Every query starts from the Workspace, so a reminder or a customer of another one is a 404 / not found. The lifecycle of sending
 * belongs to ReminderDispatcher; this controller only schedules, edits and cancels.
 */
class ReminderController extends Controller
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly ReminderChannels $channels,
        private readonly ReminderRulePresenter $rulePresenter,
        private readonly ReminderVariables $variables,
        private readonly ModuleAccess $modules,
        private readonly QuickCustomer $customerPicker,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(array_keys(config('reminders.statuses')))],
            'channel' => ['nullable', 'string', Rule::in(array_keys(config('reminders.channels')))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $settings = $workspace->settingsOrDefault();
        $zone = $settings->timezone ?: 'UTC';
        $rules = $this->modules->allows($workspace, 'bookings');
        $like = filled($filters['q'] ?? null) ? '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['q']))).'%' : null;

        $query = $workspace->reminders()->with('customer:id,first_name,last_name')
            ->when($like, fn ($q) => $q->whereRaw("lower(name) like ? escape '!'", [$like]))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('send_at', '>=', Carbon::parse($from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('send_at', '<=', Carbon::parse($to, $zone)->endOfDay()->utc()))
            ->orderByRaw("case when status = 'scheduled' then 0 else 1 end")->orderByRaw("case when status = 'scheduled' then send_at end asc")->orderByDesc('send_at');

        $page = ListPagination::paginate($query, $request, 'reminders');

        return Inertia::render('Reminders/Index', [
            'reminders' => [
                'data' => $page->getCollection()->map(fn (Reminder $reminder) => $this->present($reminder, $settings, $zone))->values()->all(),
                'meta' => ListPagination::meta($page),
            ],
            'perPageOptions' => ListPagination::OPTIONS,
            'filters' => $filters,
            'channels' => $this->channels->offered($workspace),
            'statuses' => config('reminders.statuses'),
            'reasons' => config('reminders.reasons'),
            'relatedTypes' => config('reminders.related_types'),
            // A manual reminder is not tied to an appointment: only the customer's data can be filled (the rules' editor also gets the appointment's, in `rules.variables`).
            'variables' => $this->variables->offered(false),
            // The result of the last manual send to several customers (who was scheduled and who was left out, and why), shown once.
            'lastBatch' => $request->session()->get('reminder_batch'),
            'consentChannels' => config('customers.consent_channels'),
            'limits' => ['name' => config('reminders.limits.name_max'), 'purpose' => config('reminders.limits.purpose_max'), 'subject' => config('reminders.limits.subject_max'), 'body' => config('reminders.limits.body_max'), 'minLeadMinutes' => config('reminders.limits.min_lead_minutes'), 'maxRecipients' => config('reminders.limits.max_recipients')],
            'timezone' => $zone,
            // The automatic rules need Agenda too (the services done are its appointments): without it the tab does not exist, and the routes refuse as well.
            'rules' => $rules ? $this->rulePresenter->list($workspace, $request) + ['services' => $this->rulePresenter->services($workspace), 'delayUnits' => config('reminders.rules.delay_units'), 'defaultSendTime' => config('reminders.rules.default_send_time'), 'graceHours' => config('reminders.rules.grace_hours'), 'variables' => $this->variables->offered(true)] : null,
        ]);
    }

    public function store(SaveReminderRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        // One or several customers: each gets their OWN reminder; the ones who cannot receive it are reported with the reason and the rest go ahead.
        if ($request->filled('customer_ids')) {
            $result = $this->reminders->createMany($workspace, $request->validated(), $request->user());
            $request->session()->flash('reminder_batch', ['created' => count($result['created']), 'skipped' => $result['skipped']]);

            return back()->with(count($result['created']) > 0 ? 'success' : 'error', match (true) {
                count($result['created']) === 0 => 'No se programó ningún recordatorio: ninguno de los destinatarios puede recibirlo.',
                $result['skipped'] === [] => count($result['created']) === 1 ? 'Recordatorio programado.' : count($result['created']).' recordatorios programados, uno por cliente.',
                default => count($result['created']).' recordatorio(s) programado(s); '.count($result['skipped']).' destinatario(s) omitido(s), revisa el motivo.',
            });
        }

        $result = $this->reminders->create($workspace, $request->validated(), $request->user());

        return back()->with('success', $result['replayed'] ? 'Ese recordatorio ya estaba programado.' : 'Recordatorio programado.');
    }

    public function update(SaveReminderRequest $request, int $reminder): RedirectResponse
    {
        $found = $this->find($request, $reminder);

        // A reminder an automatic rule scheduled follows its rule: edit the rule instead (otherwise the next refresh would undo the change).
        if ($found->reminder_rule_id !== null) {
            throw ValidationException::withMessages(['name' => 'Este recordatorio lo programa una regla automática: edita la regla.']);
        }

        $this->reminders->update($found, $request->validated());

        return back()->with('success', 'Recordatorio actualizado.');
    }

    public function cancel(Request $request, int $reminder): RedirectResponse
    {
        $this->reminders->cancel($this->find($request, $reminder));

        return back()->with('success', 'Recordatorio cancelado.');
    }

    /** The tries of one reminder (read only, fixed reasons). */
    public function attempts(Request $request, int $reminder): JsonResponse
    {
        $found = $this->find($request, $reminder);
        $settings = $found->workspace->settingsOrDefault();
        $reasons = config('reminders.reasons');

        $page = ListPagination::paginate(ReminderAttempt::query()->where('reminder_id', $found->id)->where('workspace_id', $found->workspace_id)->orderByDesc('id'), $request, 'attempts');

        return response()->json(['attempts' => $page->getCollection()->map(fn (ReminderAttempt $attempt) => [
            'id' => $attempt->id, 'attempt' => $attempt->attempt, 'outcome' => $attempt->outcome, 'reason' => $attempt->reason ? ($reasons[$attempt->reason] ?? $attempt->reason) : null, 'detail' => $attempt->detail,
            'at' => $settings->formatDate($attempt->created_at).' '.$settings->formatTime($attempt->created_at),
        ])->values()->all(), 'meta' => ListPagination::meta($page)]);
    }

    /**
     * Customers of THIS Workspace to pick as recipients, by name, last name, phone or e-mail (the central Clientes table, never a copy). The person who schedules is about to write to
     * them, so their contact data is included for the preview; the consent flag says whether they can be written to at all.
     */
    public function customers(Request $request): JsonResponse
    {
        $found = $this->customerPicker->find($request->attributes->get('workspace'), trim((string) $request->query('q', '')), 20);

        return response()->json(['customers' => $found->map(fn (Customer $customer) => $this->presentCustomer($customer))->all()])->header('Cache-Control', 'no-store');
    }

    /** A new customer from the manual form: the SAME card as in Clientes (same duplicate rules and audit), without leaving the form. Needs `create-customers` as well. */
    public function storeCustomer(Request $request): JsonResponse
    {
        $card = $this->customerPicker->create($request->attributes->get('workspace'), $request->only(['first_name', 'last_name', 'phone', 'email', 'consent_granted', 'consent_channel', 'consent_note']));

        return response()->json(['customer' => $this->presentCustomer($card)], 201);
    }

    /** @return array<string, mixed> */
    private function presentCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id, 'name' => $customer->fullName(), 'firstName' => $customer->first_name, 'lastName' => $customer->last_name, 'phone' => $customer->phone, 'email' => $customer->email,
            'hasEmail' => filled($customer->email), 'hasPhone' => filled($customer->phone_e164) || filled($customer->phone_normalized), 'consent' => $customer->marketing_consent === Customer::CONSENT_GRANTED,
        ];
    }

    private function find(Request $request, int $id): Reminder
    {
        return $request->attributes->get('workspace')->reminders()->with('workspace')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(Reminder $reminder, $settings, string $zone): array
    {
        return [
            'id' => $reminder->id,
            'name' => $reminder->name,
            'purpose' => $reminder->purpose,
            'customer' => ['id' => $reminder->customer_id, 'name' => $reminder->customer?->fullName() ?? '—'],
            'channel' => $reminder->channel,
            'status' => $reminder->status,
            'sendAt' => $settings->formatDate($reminder->send_at).' '.$settings->formatTime($reminder->send_at),
            'sendAtLocal' => $reminder->send_at->copy()->setTimezone($zone)->format('Y-m-d\TH:i'),
            'sentAt' => $reminder->sent_at ? $settings->formatDate($reminder->sent_at).' '.$settings->formatTime($reminder->sent_at) : null,
            'attempts' => $reminder->attempts,
            'reason' => $reminder->reason,
            'source' => $reminder->source,
            'relatedType' => $reminder->related_type,
            'relatedId' => $reminder->related_id,
            'subject' => $reminder->subject,
            'body' => $reminder->body,
            'templateName' => $reminder->template_name,
            'templateLanguage' => $reminder->template_language,
            'templateParams' => $reminder->template_params ?? [],
            // A reminder a rule scheduled follows its rule: it can be cancelled, not edited by hand.
            'canEdit' => $reminder->isPending() && $reminder->reminder_rule_id === null,
            'canCancel' => $reminder->isPending(),
            'ruleId' => $reminder->reminder_rule_id,
        ];
    }
}
