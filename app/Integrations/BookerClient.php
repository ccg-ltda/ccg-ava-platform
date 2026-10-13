<?php

namespace App\Integrations;

use App\Booking\ProviderException;
use App\Models\Integration;
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
    private const SCOPE = 'public';

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
            'scope' => self::SCOPE,
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
            $response = match (true) {
                isset($options['form_params']) => $client->asForm()->post($url, $options['form_params']),
                $method === 'GET' => $client->get($url, $options['query'] ?? []),
                $method === 'PUT' => $client->asJson()->put($url, $options['json'] ?? []),
                default => $client->asJson()->post($url, $options['json'] ?? []),
            };
        } catch (ConnectionException $exception) {
            throw str_contains(strtolower($exception->getMessage()), 'time') ? ProviderException::timeout() : ProviderException::unavailable();
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ProviderException::unknown();
        }

        return $this->check($response, $authorized);
    }

    /** @throws ProviderException */
    private function check(Response $response, bool $authorized): Response
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
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
