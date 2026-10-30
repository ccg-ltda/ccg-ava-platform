<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Reminder;
use App\Reminders\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Agent API of Recordatorios (`/api/agent/reminders`, token of ONE chatbot): lets the assistant schedule or cancel a follow-up for the person it is talking to. The Workspace comes
 * from the token (never from the request) and the customer must be the one LINKED to the conversation's contact (an identity of that channel, or the verified WhatsApp number): the
 * assistant can not target an arbitrary customer of the Workspace. It goes through the SAME ReminderService as the screen: same channel checks, same consent, same limits. It is
 * idempotent by the required `Idempotency-Key` (a retry of the workflow returns the same reminder) and can only cancel reminders that came from the API.
 */
class AgentReminderController extends Controller
{
    public function __construct(private readonly ReminderService $reminders) {}

    public function store(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $key = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key'));
        $request->merge(['idempotency_key' => $key]);

        $data = $request->validate([
            'contact_channel' => ['required', 'string', 'max:20'],
            'contact_id' => ['required', 'string', 'max:191'],
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'name' => ['required', 'string', 'max:'.config('reminders.limits.name_max')],
            'purpose' => ['nullable', 'string', 'max:'.config('reminders.limits.purpose_max')],
            'channel' => ['required', 'string', Rule::in(array_keys(config('reminders.channels')))],
            'subject' => ['nullable', 'string', 'max:'.config('reminders.limits.subject_max')],
            'body' => ['required', 'string', 'max:'.config('reminders.limits.body_max')],
            'template_name' => ['nullable', 'string', 'max:120'],
            'template_language' => ['nullable', 'string', 'max:12'],
            'template_params' => ['nullable', 'array', 'max:10'],
            'template_params.*' => ['string', 'max:40'],
            'send_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            'related_type' => ['nullable', 'string', Rule::in(array_keys(config('reminders.related_types')))],
            'related_id' => ['nullable', 'required_with:related_type', 'integer', 'min:1'],
        ]);

        $customer = $this->customerOf($workspace, $data['contact_channel'], $data['contact_id']);

        if (! $customer) {
            return response()->json(['message' => 'No hay un cliente vinculado a ese contacto.', 'code' => 'customer_not_found'], 404);
        }

        // The instant is converted to the Workspace's own zone before it reaches the service (which stores UTC and shows the Workspace's local time).
        $zone = $workspace->settingsOrDefault()->timezone ?: 'UTC';
        $data['send_at'] = Carbon::parse($data['send_at'])->setTimezone($zone)->format('Y-m-d\TH:i');
        $data['timezone'] = $zone;

        $result = $this->reminders->create($workspace, ['customer_id' => $customer->id] + $data, null, 'api');

        return response()->json(['id' => $result['reminder']->id, 'status' => $result['reminder']->status, 'send_at' => $result['reminder']->send_at->utc()->format('Y-m-d\TH:i:s\Z'), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function cancel(Request $request, int $reminder): JsonResponse
    {
        $found = $request->attributes->get('workspace')->reminders()->with('workspace')->where('source', 'api')->findOrFail($reminder);
        $cancelled = $this->reminders->cancel($found, 'api');

        return response()->json(['id' => $cancelled->id, 'status' => $cancelled->status]);
    }

    /** The customer of THIS Workspace that the conversation's contact is linked to (never a customer the contact is not). */
    private function customerOf($workspace, string $channel, string $contactId): ?Customer
    {
        return $workspace->customers()->whereNull('anonymized_at')->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereHas('identities', fn ($identity) => $identity->where('channel', $channel)->where('external_id', $contactId))
                ->when($channel === 'whatsapp' && ctype_digit($contactId), fn ($q) => $q->orWhere('phone_normalized', $contactId)->orWhere('phone_e164', '+'.$contactId)))
            ->first();
    }
}
