<?php

namespace App\Payments;

use App\Models\PaymentEvent;
use App\Models\SignupRequest;
use App\Observability\ApiCallRecorder;
use App\Services\OperationalAlert;
use App\Services\SignupService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Handles a Wompi `transaction.updated` event. Order matters: (1) authenticity (checksum with the events secret, environment), (2) the event is recorded,
 * (3) the transaction is read again from Wompi's API, because the event is only a hint and the API answer is the truth (status, amount, currency and
 * reference), (4) only an APPROVED transaction goes to SignupService::confirmPayment, which compares it with the price snapshot and is idempotent.
 * Events out of order are harmless: every event triggers a fresh read, so an old PENDING can never undo an APPROVED.
 */
class WompiEvents
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    /** Wompi could not be asked or provisioning failed: the answer is not 200, so Wompi retries. */
    public const RETRY = 'retry';

    public function __construct(private readonly WompiConfig $config, private readonly SignupService $signup, private readonly OperationalAlert $alerts) {}

    public function verify(array $payload, ?string $headerChecksum = null): bool
    {
        $checksum = $payload['signature']['checksum'] ?? null;
        $properties = $payload['signature']['properties'] ?? null;
        $timestamp = $payload['timestamp'] ?? null;

        if (! is_string($checksum) || ! is_array($properties) || $properties === [] || ! is_scalar($timestamp) || blank($this->config->eventsSecret())) {
            return false;
        }

        $concatenated = '';

        foreach ($properties as $path) {
            $value = is_string($path) ? data_get($payload['data'] ?? [], $path) : null;

            if (! is_scalar($value)) {
                return false;
            }

            $concatenated .= is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $expected = hash('sha256', $concatenated.$timestamp.$this->config->eventsSecret());

        return hash_equals($expected, strtolower($checksum)) && ($headerChecksum === null || hash_equals($expected, strtolower($headerChecksum)));
    }

    /** @return self::OK|self::INVALID|self::RETRY */
    public function handle(array $payload, ?string $headerChecksum = null): string
    {
        if (! $this->verify($payload, $headerChecksum)) {
            $this->alerts->raise('wompi_event_invalid_signature');

            return self::INVALID;
        }

        // A sandbox event never reaches a production setup, nor the other way round.
        if (($payload['environment'] ?? null) !== $this->config->eventEnvironment()) {
            $this->alerts->raise('wompi_event_wrong_environment');

            return self::OK;
        }

        if (($payload['event'] ?? null) !== 'transaction.updated') {
            return self::OK;
        }

        $transaction = $payload['data']['transaction'] ?? [];
        $id = (string) ($transaction['id'] ?? '');

        if ($id === '' || ! is_string($transaction['reference'] ?? null)) {
            return self::INVALID;
        }

        $request = SignupRequest::where('public_id', $transaction['reference'])->first();
        $event = PaymentEvent::create([
            'provider' => 'wompi', 'environment' => $this->config->environment(), 'transaction_id' => substr($id, 0, 80), 'reference' => substr($transaction['reference'], 0, 80),
            'event_status' => substr((string) ($transaction['status'] ?? ''), 0, 20), 'event_timestamp' => (int) ($payload['timestamp'] ?? 0), 'signup_request_id' => $request?->id,
        ]);

        if (! $request) {
            $event->update(['outcome' => 'unknown_reference']);
            $this->alerts->raise('signup_payment_unknown_reference', ['transaction' => $id]);

            return self::OK;
        }

        $verified = $this->fetch($id);

        if ($verified === null) {
            $event->update(['outcome' => 'lookup_failed']);

            return self::RETRY;
        }

        $event->update(['status' => substr($verified['status'], 0, 20), 'amount_cents' => $verified['amount_in_cents'], 'currency' => substr($verified['currency'], 0, 3)]);

        if ($verified['reference'] !== $request->public_id) {
            $event->update(['outcome' => 'reference_mismatch']);
            $this->alerts->raise('signup_payment_mismatch', ['signup' => $request->id, 'transaction' => $id]);

            return self::OK;
        }

        if ($verified['status'] !== 'APPROVED') {
            // PENDING is still in flight; DECLINED, VOIDED and ERROR buy nothing. The customer can pay again with the same contract.
            $event->update(['outcome' => 'not_approved']);

            return self::OK;
        }

        try {
            $this->signup->confirmPayment(new PaymentConfirmation($request->public_id, $id, $verified['amount_in_cents'], $verified['currency']));
        } catch (ValidationException) {
            // Amount or currency mismatch, or a contract that is not waiting for a payment: SignupService alerted; retrying can not fix it.
            $event->update(['outcome' => 'rejected']);

            return self::OK;
        } catch (Throwable) {
            $event->update(['outcome' => 'provisioning_failed']);

            return self::RETRY; // the payment is recorded; the repeated event completes the provisioning
        }

        $event->update(['outcome' => 'confirmed']);

        return self::OK;
    }

    /** @return array{status: string, amount_in_cents: int, currency: string, reference: ?string}|null null when Wompi can not be asked */
    private function fetch(string $id): ?array
    {
        try {
            $url = $this->config->apiUrl().'/transactions/'.rawurlencode($id);
            $response = app(ApiCallRecorder::class)->call('wompi', 'get_transaction', 'GET', $url, fn () => Http::timeout((int) config('wompi.timeout_seconds'))->acceptJson()->get($url), ['Accept' => 'application/json'], null, ['resource_type' => 'transaction']);
        } catch (ConnectionException) {
            return null;
        }

        $data = $response->successful() ? $response->json('data') : null;

        if (! is_array($data) || ! isset($data['status'], $data['amount_in_cents'], $data['currency']) || (string) ($data['id'] ?? '') !== $id) {
            return null;
        }

        return ['status' => (string) $data['status'], 'amount_in_cents' => (int) $data['amount_in_cents'], 'currency' => (string) $data['currency'], 'reference' => isset($data['reference']) ? (string) $data['reference'] : null];
    }
}
