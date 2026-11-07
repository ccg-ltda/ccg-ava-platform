<?php

namespace App\Payments\Gateways;

use App\Models\Integration;
use App\Observability\ApiCallRecorder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the Wompi API with the credentials of ONE Workspace connection. Independent of the platform's own Wompi (App\Payments\Wompi*): other configuration, other credentials,
 * other history (the API call log names it `wompi_workspace` and carries the Workspace). Only two documented calls are made, both reads: the merchant by public key (to prove
 * the key exists in that environment) and a transaction by its id (the truth about a payment). Nothing is charged, created or voided here. Bodies are not stored.
 *
 * Documented by Wompi (docs.wompi.co): the two base URLs, `GET /transactions/{id}` ("verify the status of a transaction at any time using your public key") and the key
 * prefixes. NOT confirmed by the documentation Ava could read, hence not used: a query by reference, and the response format of a void.
 */
class WompiClient
{
    public function __construct(private readonly ApiCallRecorder $recorder) {}

    public function apiUrl(Integration $integration): string
    {
        $environment = ($integration->config['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';

        return rtrim((string) config("integrations.wompi.api_urls.{$environment}"), '/');
    }

    /** @return array{ok: bool, status: ?int, data: ?array<string, mixed>, error: ?string} */
    public function merchant(Integration $integration): array
    {
        return $this->get($integration, 'get_merchant', '/merchants/'.rawurlencode((string) ($integration->config['public_key'] ?? '')), false);
    }

    /** @return array{ok: bool, status: ?int, data: ?array<string, mixed>, error: ?string} */
    public function transaction(Integration $integration, string $id): array
    {
        return $this->get($integration, 'get_transaction', '/transactions/'.rawurlencode($id), true);
    }

    /** @return array{ok: bool, status: ?int, data: ?array<string, mixed>, error: ?string} `error`: timeout | connection | http | null */
    private function get(Integration $integration, string $operation, string $path, bool $withKey): array
    {
        $url = $this->apiUrl($integration).$path;
        $key = (string) ($integration->config['public_key'] ?? '');

        try {
            $response = $this->recorder->call(
                'wompi_workspace', $operation, 'GET', $url,
                fn () => Http::timeout((int) config('integrations.wompi.timeout'))->acceptJson()->when($withKey && $key !== '', fn ($request) => $request->withToken($key))->get($url),
                ['Accept' => 'application/json'], null, ['workspace_id' => $integration->workspace_id, 'resource_type' => 'integration', 'resource_id' => $integration->id], false,
            );
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'status' => null, 'data' => null, 'error' => str_contains($exception->getMessage(), 'cURL error 28') ? 'timeout' : 'connection'];
        } catch (Throwable) {
            return ['ok' => false, 'status' => null, 'data' => null, 'error' => 'connection'];
        }

        $data = $response->json('data');

        return ['ok' => $response->successful() && is_array($data), 'status' => $response->status(), 'data' => is_array($data) ? $data : null, 'error' => $response->successful() ? null : 'http'];
    }
}
