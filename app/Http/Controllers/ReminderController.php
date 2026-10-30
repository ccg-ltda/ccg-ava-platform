<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveReminderRequest;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\ReminderAttempt;
use App\Reminders\ReminderChannels;
use App\Reminders\ReminderService;
use App\Services\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recordatorios: the commercial follow-ups of the ACTIVE Workspace (taken from the request context, never from the client). Reading is `view-reminders`; creating, editing and
 * cancelling each have their own permission. Every query starts from the Workspace, so a reminder or a customer of another one is a 404 / not found. The lifecycle of sending
 * belongs to ReminderDispatcher; this controller only schedules, edits and cancels.
 */
class ReminderController extends Controller
{
    public function __construct(private readonly ReminderService $reminders, private readonly ReminderChannels $channels) {}

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
            'variables' => collect(config('campaigns.variables'))->map(fn (array $variable, string $key) => ['key' => $key, 'label' => $variable['label']])->values()->all(),
            'limits' => ['name' => config('reminders.limits.name_max'), 'purpose' => config('reminders.limits.purpose_max'), 'subject' => config('reminders.limits.subject_max'), 'body' => config('reminders.limits.body_max'), 'minLeadMinutes' => config('reminders.limits.min_lead_minutes')],
            'timezone' => $zone,
        ]);
    }

    public function store(SaveReminderRequest $request): RedirectResponse
    {
        $result = $this->reminders->create($request->attributes->get('workspace'), $request->validated(), $request->user());

        return back()->with('success', $result['replayed'] ? 'Ese recordatorio ya estaba programado.' : 'Recordatorio programado.');
    }

    public function update(SaveReminderRequest $request, int $reminder): RedirectResponse
    {
        $this->reminders->update($this->find($request, $reminder), $request->validated());

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

        return response()->json(['attempts' => ReminderAttempt::query()->where('reminder_id', $found->id)->where('workspace_id', $found->workspace_id)->orderByDesc('id')->limit(50)->get()->map(fn (ReminderAttempt $attempt) => [
            'id' => $attempt->id, 'attempt' => $attempt->attempt, 'outcome' => $attempt->outcome, 'reason' => $attempt->reason ? ($reasons[$attempt->reason] ?? $attempt->reason) : null,
            'at' => $settings->formatDate($attempt->created_at).' '.$settings->formatTime($attempt->created_at),
        ])->all()]);
    }

    /** Customers of THIS Workspace to pick a recipient: what the picker needs and nothing else (no phone, no e-mail, no notes). */
    public function customers(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $term = trim((string) $request->query('q', ''));
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        $found = $workspace->customers()->whereNull('anonymized_at')->where('is_active', true)
            ->when($term !== '', fn ($q) => $q->whereRaw("lower(first_name || ' ' || coalesce(last_name, '')) like ? escape '!'", [$like]))
            ->orderBy('first_name')->limit(20)->get();

        return response()->json(['customers' => $found->map(fn (Customer $customer) => [
            'id' => $customer->id, 'name' => $customer->fullName(), 'hasEmail' => filled($customer->email), 'hasPhone' => filled($customer->phone_e164) || filled($customer->phone_normalized),
            'consent' => $customer->marketing_consent === Customer::CONSENT_GRANTED,
        ])->all()]);
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
            'canEdit' => $reminder->isPending(),
        ];
    }
}
