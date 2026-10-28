<?php

namespace App\Observability;

/**
 * The single redaction policy for stored API calls. It runs BEFORE anything is persisted, on headers, nested bodies, JSON, forms and query strings, and it is
 * deliberately blunt: a key whose name suggests a secret is replaced whatever its value, and strings that look like provider keys, bearer tokens or card
 * numbers are masked wherever they appear.
 */
class ApiRedactor
{
    public const MASK = '[REDACTED]';

    public const MAX_BYTES = 4096;

    private const SENSITIVE_KEY = '/(authorization|cookie|token|secret|password|passwd|pwd|api[-_]?key|apikey|private|signature|checksum|credential|bearer|card|cvv|cvc|session|x-ava)/i';

    private const SENSITIVE_VALUE = '/\b(?:pub|prv)_(?:test|prod)_[A-Za-z0-9]+|\b(?:test|prod)_(?:events|integrity)_[A-Za-z0-9]+|\bBearer\s+[A-Za-z0-9._~+\/=-]+|\b(?:\d[ -]?){13,19}\b/';

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            $out[(string) $name] = preg_match(self::SENSITIVE_KEY, (string) $name) ? self::MASK : $this->scrub($value);
        }

        return $out;
    }

    /** Redacts any structure (nested arrays included). */
    public function data(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEY, $key)) {
            return self::MASK;
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = $this->data($v, is_string($k) ? $k : null);
            }

            return $out;
        }

        return is_string($value) ? $this->scrub($value) : $value;
    }

    /**
     * A raw body (JSON, form or text): parsed and redacted when structured, scrubbed otherwise; capped at MAX_BYTES.
     *
     * @return array{0: ?string, 1: bool} [text, truncated]
     */
    public function body(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, false];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $text = json_encode($this->data($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            parse_str($raw, $form);
            $text = ($form !== [] && str_contains($raw, '=') && ! str_contains($raw, "\n"))
                ? json_encode($this->data($form), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $this->scrub($raw);
        }

        return $this->cap((string) $text);
    }

    /** @return array{0: string, 1: bool} */
    public function cap(string $text): array
    {
        if (strlen($text) <= self::MAX_BYTES) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, self::MAX_BYTES).'… [truncado, '.strlen($text).' bytes]', true];
    }

    /**
     * Host and path; query values with sensitive names are masked.
     *
     * @return array{host: string, path: string}
     */
    public function url(string $url): array
    {
        $parts = parse_url($url) ?: [];
        parse_str($parts['query'] ?? '', $query);
        $query = $this->data($query);

        return [
            'host' => substr((string) ($parts['host'] ?? ''), 0, 120),
            'path' => substr($this->scrub(($parts['path'] ?? '/').($query ? '?'.urldecode(http_build_query($query)) : '')), 0, 255),
        ];
    }

    public function message(string $message): string
    {
        return substr($this->scrub($message), 0, 255);
    }

    private function scrub(string $value): string
    {
        return (string) preg_replace(self::SENSITIVE_VALUE, self::MASK, $value);
    }
}
