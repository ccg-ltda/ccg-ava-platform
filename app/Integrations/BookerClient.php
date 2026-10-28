<?php

namespace App\Integrations;

use App\Booking\ProviderException;
use App\Models\Integration;
use App\Observability\ApiCallRecorder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The HTTP side of the Booker connector for ONE integration (one Workspace). What it knows about Booker comes from Booker's
 * open-source Ruby client (`HireFrederick/booker_ruby`), not from Booker's own documentation portal (it requires a signed-in
 * developer account) and NOT from a live Sandbox: see `docs` in pendientes.md for which calls are only "documented by that client".
 *
 *  - Token: `POST {base}/v5/auth/connect/token` (form: grant_type=client_credentials, client_id, client_secret, scope) with the
 *    `Ocp-Apim-Subscription-Key` header. Cached until it expires.
 *  - Customer API: `{base}/v4.1/customer/...` with `Authorization: Bearer` and the subscription key.
 *
 * Every failure is reduced to a ProviderException with a fixed code: neither Booker's text nor a credential is kept. Reads
 * are tried once more after a timeout, a 429 or a 5xx; writes are never repeated (they could happen twice).
 */
class BookerClient
{
    public function __construct(private readonly Integration $integration, private readonly SafeHttpTarget $target) {}

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query], retry: true);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body = [], bool $retry = false): array
    {
        return $this->send('POST', $path, ['json' => $body], $retry);
    }

    /**
     * A call of the Merchant API (`config('integrations.booker.merchant.paths')`): a POST that also carries the access token in the body as
     * `access_token`, next to the Bearer header (the support answer lists the token among the data a call needs; which of the two Booker reads is
     * confirmed in Staging). The token is injected at send time, so a renewed token is used on the retry. Reads may be repeated, a write never.
     *
     * @return array<string, mixed>
     */
    public function merchant(string $pathKey, array $body = [], bool $retry = false): array
    {
        return $this->send('POST', (string) config("integrations.booker.merchant.paths.{$pathKey}"), ['json' => $body, 'merchant' => true], $retry);
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $body = []): array
    {
        return $this->send('PUT', $path, ['json' => $body], retry: false);
    }

    /** Asks for an access token with the stored credentials (never cached): used to verify them. @throws ProviderException */
    public function authenticate(): string
    {
        $url = $this->baseUrl().'/v5/auth/connect/token';
        $secrets = $this->integration->secrets ?? [];

        $response = $this->request('POST', $url, ['form_params' => [
            'grant_type' => 'client_credentials',
            'client_id' => (string) ($secrets['client_id'] ?? ''),
            'client_secret' => (string) ($secrets['client_secret'] ?? ''),
            'scope' => (string) config('integrations.booker.scope', 'public'),
        ]], authorized: false);

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw ProviderException::auth();
        }

        Cache::put($this->cacheKey(), $token, max(60, ((int) $response->json('expires_in') ?: 300) - 60));

        return $token;
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('integrations.booker.urls.'.($this->integration->config['environment'] ?? 'staging')), '/');
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $options, bool $retry, bool $refreshed = false): array
    {
        $url = $this->baseUrl().$path;

        try {
            return $this->request($method, $url, $options, authorized: true)->json() ?? [];
        } catch (ProviderException $exception) {
            // An expired or revoked token is renewed once; a transient failure of a read is tried once more.
            if ($exception->codeName === ProviderException::AUTH && ! $refreshed) {
                Cache::forget($this->cacheKey());

                return $this->send($method, $path, $options, $retry, refreshed: true);
            }

            if ($retry && $exception->retryable) {
                usleep(300_000);

                return $this->request($method, $url, $options, authorized: true)->json() ?? [];
            }

            throw $exception;
        }
    }

    /** @throws ProviderException */
    private function request(string $method, string $url, array $options, bool $authorized): Response
    {
        try {
            $pinned = $this->target->resolve($url);
        } catch (UnsafeTarget) {
            throw ProviderException::unavailable();
        }

        $headers = ['Accept' => 'application/json', 'Ocp-Apim-Subscription-Key' => (string) ($this->integration->secrets['subscription_key'] ?? '')];

        if ($authorized) {
            $headers['Authorization'] = 'Bearer '.$this->token();
        }

        $timeout = (int) config('integrations.booker.timeout');

        try {
            $client = $this->target->client($pinned, $timeout, $headers);
            $merchantToken = isset($options['merchant']) && ! isset($options['form_params']) && $method !== 'GET' && $method !== 'PUT' ? $this->token() : null;
            // Recorded without bodies or request headers (they carry the subscription key, the token and customer data): method, host, path without query, status, duration, outcome.
            $response = app(ApiCallRecorder::class)->call('booker', 'request', $method, strtok($url, '?') ?: $url, fn () => match (true) {
                isset($options['form_params']) => $client->asForm()->post($url, $options['form_params']),
                $method === 'GET' => $client->get($url, $options['query'] ?? []),
                $method === 'PUT' => $client->asJson()->put($url, $options['json'] ?? []),
                default => $client->asJson()->post($url, ($options['json'] ?? []) + ($merchantToken !== null ? ['access_token' => $merchantToken] : [])),
            }, [], null, ['workspace_id' => $this->integration->workspace_id], false);
        } catch (ConnectionException $exception) {
            throw str_contains(strtolower($exception->getMessage()), 'time') ? ProviderException::timeout() : ProviderException::unavailable();
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ProviderException::unknown();
        }

        return $this->check($response, $authorized, isset($options['merchant']));
    }

    /** @throws ProviderException */
    private function check(Response $response, bool $authorized, bool $merchant = false): Response
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            // Booker's older APIs answer 200 with an `ErrorCode` in the body when the operation was refused. The code is a refusal, never a success, and its
            // text is not kept. (Assumption to confirm in Staging: it only applies to the Merchant API calls.)
            if ($merchant && is_numeric($response->json('ErrorCode')) && (int) $response->json('ErrorCode') !== 0) {
                throw ProviderException::invalid();
            }

            return $response;
        }

        // The token endpoint answers 400 with `invalid_client` for wrong credentials; an API call answers 401/403.
        $error = strtolower((string) ($response->json('error') ?? ''));

        throw match (true) {
            in_array($status, [401, 403], true), $error === 'invalid_client', $error === 'invalid_grant', (! $authorized && in_array($status, [400, 404], true)) => ProviderException::auth(),
            $status === 429 => ProviderException::rateLimited(),
            in_array($status, [502, 503, 504], true) || $status >= 500 => ProviderException::unavailable(),
            $status === 409 => ProviderException::conflict(),
            in_array($status, [400, 404, 422], true) => ProviderException::invalid(),
            default => ProviderException::unknown(),
        };
    }

    private function token(): string
    {
        return Cache::get($this->cacheKey()) ?? $this->authenticate();
    }

    private function cacheKey(): string
    {
        return 'booker:token:'.$this->integration->getKey().':'.substr(sha1((string) ($this->integration->secrets['client_id'] ?? '')), 0, 12);
    }
}
