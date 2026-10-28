<?php

namespace App\Payments;

use App\Models\PaymentEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * The persisted payments, one row per provider transaction (its latest event). Everything comes from `payment_events`: the status is what the provider's API
 * said when Ava asked (`status`), never the unverified event, and nothing is invented to fill the list. A payment is not an invoice: the invoice column
 * only ever says "pendiente de facturación" for a confirmed payment, because no invoicing provider (SIGO) is connected.
 */
class PaymentHistory
{
    public const PER_PAGE = 15;

    public function page(Request $request): LengthAwarePaginator
    {
        $latest = PaymentEvent::query()->selectRaw('max(id)')->groupBy('provider', 'transaction_id');
        $query = PaymentEvent::query()->with('signupRequest.plan:id,name', 'signupRequest.workspace:id,code,name')->whereIn('id', $latest)->orderByDesc('id');

        if (in_array($request->query('environment'), ['sandbox', 'production'], true)) {
            $query->where('environment', $request->query('environment'));
        }

        if (in_array($request->query('status'), ['APPROVED', 'DECLINED', 'VOIDED', 'ERROR', 'PENDING'], true)) {
            $query->where('status', $request->query('status'));
        }

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim(mb_substr($q, 0, 80))).'%';
            $query->where(fn ($w) => $w->where('reference', 'like', $term)->orWhere('transaction_id', 'like', $term));
        }

        return $query->paginate(self::PER_PAGE)->withQueryString()->through(fn (PaymentEvent $e) => $this->row($e));
    }

    public function detail(int $id): ?array
    {
        $event = PaymentEvent::with('signupRequest.plan:id,name', 'signupRequest.workspace:id,code,name')->find($id);

        if (! $event) {
            return null;
        }

        return $this->row($event) + [
            'events' => PaymentEvent::where('provider', $event->provider)->where('transaction_id', $event->transaction_id)->orderBy('id')
                ->get(['id', 'created_at', 'event_status', 'status', 'outcome'])->map(fn ($e) => [
                    'at' => $e->created_at?->toIso8601String(), 'eventStatus' => $e->event_status, 'verifiedStatus' => $e->status, 'outcome' => $e->outcome,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(PaymentEvent $e): array
    {
        $signup = $e->signupRequest;

        return [
            'id' => $e->id, 'date' => $e->created_at?->toIso8601String(), 'reference' => $e->reference, 'transactionId' => $e->transaction_id,
            'contract' => $signup ? ($signup->plan?->name ?? 'Plan') : null, 'workspace' => $signup?->workspace ? $signup->workspace->name.' ('.$signup->workspace->code.')' : null,
            'amountCents' => $e->amount_cents, 'currency' => $e->currency, 'status' => $e->status, 'eventStatus' => $e->event_status,
            'environment' => $e->environment, 'confirmedAt' => $e->outcome === 'confirmed' ? $signup?->paid_at?->toIso8601String() : null,
            'outcome' => $e->outcome, 'invoice' => $e->outcome === 'confirmed' ? 'pending' : null,
        ];
    }
}
