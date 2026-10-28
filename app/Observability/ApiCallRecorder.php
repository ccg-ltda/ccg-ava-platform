<?php

namespace App\Observability;

use App\Models\ApiCallLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs an outbound HTTP call and stores a REDACTED record of it (also timeouts and connection failures, which have no HTTP status). Recording never changes the
 * result of the call and never throws: if the log cannot be written the call behaves as if it were not logged. Bodies are stored only when `$withBodies`
 * (decided per integration: Wompi keeps them redacted; n8n keeps none because its payloads carry customer conversations).
 */
class ApiCallRecorder
{
    public const RETENTION_DAYS = 30;

    public function __construct(private readonly ApiRedactor $redactor) {}

    /**
     * @param  callable(): Response  $send  performs the request and returns the response (may throw)
     * @param  array<string, mixed>  $requestHeaders  headers sent (redacted before storing)
     * @param  array{workspace_id?: ?int, resource_type?: ?string, resource_id?: ?int, correlation_id?: ?string}  $context
     *
     * @throws Throwable the original failure is rethrown after being recorded
     */
    public function call(string $integration, string $operation, string $method, string $url, callable $send, array $requestHeaders = [], ?string $requestBody = null, array $context = [], bool $withBodies = true): Response
    {
        $started = microtime(true);
        $correlation = $context['correlation_id'] ?? (string) Str::uuid();

        try {
            $response = $send();
        } catch (Throwable $e) {
            $timeout = str_contains($e->getMessage(), 'cURL error 28');
            $this->store($integration, $operation, $method, $url, null, $timeout ? 'timeout' : 'connection_error', $started, $correlation, $requestHeaders, $requestBody, null, null, $context, $withBodies, $e);

            throw $e;
        }

        $this->store($integration, $operation, $method, $url, $response->status(), $response->successful() ? 'success' : 'http_error', $started, $correlation, $requestHeaders, $requestBody, $response->headers(), $response->body(), $context, $withBodies, null);

        return $response;
    }

    private function store(string $integration, string $operation, string $method, string $url, ?int $status, string $outcome, float $started, string $correlation, array $reqHeaders, ?string $reqBody, ?array $resHeaders, ?string $resBody, array $context, bool $withBodies, ?Throwable $error): void
    {
        try {
            if (! self::ready()) {
                return;
            }

            [$requestText, $cut1] = $withBodies ? $this->redactor->body($reqBody) : [null, false];
            [$responseText, $cut2] = $withBodies ? $this->redactor->body($resBody) : [null, false];
            $target = $this->redactor->url($url);

            ApiCallLog::create([
                'workspace_id' => $context['workspace_id'] ?? null, 'integration' => substr($integration, 0, 32), 'operation' => substr($operation, 0, 64),
                'method' => strtoupper($method), 'host' => $target['host'], 'path' => $target['path'], 'status_code' => $status, 'outcome' => $outcome,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'correlation_id' => substr($correlation, 0, 64),
                'error_class' => $error ? substr($error::class, 0, 120) : null, 'error_message' => $error ? $this->redactor->message($error->getMessage()) : null,
                'request_headers' => json_encode($this->redactor->headers($reqHeaders)), 'request_body' => $requestText,
                'response_headers' => $resHeaders ? json_encode($this->redactor->headers($resHeaders)) : null, 'response_body' => $responseText,
                'truncated' => $cut1 || $cut2, 'resource_type' => $context['resource_type'] ?? null, 'resource_id' => $context['resource_id'] ?? null,
            ]);
        } catch (Throwable) {
            // Logging must never break the call it describes.
        }
    }

    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('api_call_logs');
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    /** Deletes records older than the retention window and returns how many. */
    public function prune(): int
    {
        return self::ready() ? ApiCallLog::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete() : 0;
    }
}
