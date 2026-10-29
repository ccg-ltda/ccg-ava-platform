<?php

namespace App\Payments;

use App\Models\PaymentProviderSetting;
use App\Observability\ApiCallRecorder;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks the stored Wompi credentials of ONE environment against the OFFICIAL API: `GET {api}/merchants/{public key}` (documented by Wompi, public, no
 * secret travels). It proves that the public key exists in that environment and that the merchant answers; it can NOT prove the integrity or events
 * secrets (Wompi exposes no endpoint for them), so the result message says exactly what was checked. Nothing is charged or created. The outcome is stored on
 * the row (status, moment and a fixed message, never provider text) and the call is recorded in the API history.
 */
class WompiConnection
{
    public function __construct(private readonly ApiCallRecorder $recorder) {}

    /** @return array{ok: bool, message: string} */
    public function validate(PaymentProviderSetting $row): array
    {
        $prefix = $row->environment === 'production' ? 'pub_prod_' : 'pub_test_';

        if (blank($row->public_key) || ! str_starts_with($row->public_key, $prefix) || blank($row->events_secret) || blank($row->integrity_secret)) {
            return $this->store($row, false, 'Configuración incompleta: faltan la llave pública, el secreto de eventos o el de integridad.');
        }

        $base = rtrim((string) config('wompi.api_urls.'.$row->environment), '/');
        $url = $base.'/merchants/'.rawurlencode($row->public_key);

        try {
            $response = $this->recorder->call('wompi', 'validate_merchant', 'GET', $url, fn () => Http::timeout((int) config('wompi.timeout_seconds'))->acceptJson()->get($url), ['Accept' => 'application/json'], null, ['resource_type' => 'payment_settings', 'resource_id' => $row->id], false); // metadata only: the merchant answer carries business data
        } catch (Throwable $e) {
            return $this->store($row, false, str_contains($e->getMessage(), 'cURL error 28') ? 'Wompi no respondió a tiempo.' : 'No se pudo conectar con Wompi.');
        }

        $merchant = $response->json('data');

        if ($response->successful() && is_array($merchant) && isset($merchant['id'])) {
            return $this->store($row, true, 'Wompi reconoció la llave pública en '.($row->environment === 'production' ? 'Producción' : 'Sandbox').'. Los secretos de eventos e integridad no se pueden comprobar con la API: se verifican con el primer evento y el primer pago de prueba.');
        }

        return $this->store($row, false, match (true) {
            in_array($response->status(), [401, 403, 404, 422], true) => 'Wompi no reconoce esa llave pública en este entorno.',
            default => 'Wompi respondió HTTP '.$response->status().'.',
        });
    }

    /** @return array{ok: bool, message: string} */
    private function store(PaymentProviderSetting $row, bool $ok, string $message): array
    {
        $row->forceFill(['validation_status' => $ok ? 'ok' : 'failed', 'validated_at' => now(), 'validation_message' => mb_substr($message, 0, 255)])->save();

        return ['ok' => $ok, 'message' => $message];
    }
}
