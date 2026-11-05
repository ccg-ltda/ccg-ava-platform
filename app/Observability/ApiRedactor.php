<?php

namespace App\Observability;

/**
 * The single redaction policy for stored API calls. It runs BEFORE anything is persisted, on headers, nested bodies, JSON, forms and query strings, and it is
 * deliberately blunt: a key whose name suggests a secret is replaced whatever its value, and strings that look like provider keys, bearer tokens or card
 * numbers are masked wherever they appear.
 *
 * `forActivity()` returns the stricter variant used by the technical activity of the assistants: besides the credentials it also masks the e-mails and phone numbers
 * found in what it stores (the technical log needs the shape of a call, not the identity of the customer; the conversation keeps the real message).
 */
class ApiRedactor
{
    private bool $maskPersonal = false;

    public const MASK = '[REDACTED]';

    public const MAX_BYTES = 4096;

    /** Cap of the bodies of a technical-activity step, where the full request and answer are meant to be read. */
    public const STEP_BYTES = 65536;

    // `card` and `session` only as a whole word of the key (`card`, `card_number`, `session_id`, `sessionId`), so `discard_reason`, `postcard` or `sessions_count` are not masked by accident.
    private const SENSITIVE_KEY = '/(authorization|cookie|token|secret|password|passwd|pwd|passphrase|api[-_]?key|apikey|access[-_]?key|private|signature|checksum|credential|bearer|(^|[-_.])card($|[-_.])|cardnumber|cardholder|cvv|cvc|(^|[-_.])session($|[-_.])|sessionid|jwt|otp|x-ava|consumer[-_]?(key|secret)|(^|[-_])(auth|key|pin)($|[-_])|[-_]key$)/i';

    /**
     * A credential written as `name=value` or `"name":"value"` INSIDE a string (a query string in a URL, a form, a JSON the string carries, a log line): the value is masked when the name
     * suggests a secret, as the keys of structured data already are. The name may carry a prefix or suffix (`aws_secret_access_key`, `client_secret_id`).
     */
    private const SENSITIVE_PAIR = '/(["\']?[A-Za-z0-9_.-]*(?:password|passwd|pwd|passphrase|secret|token|api[-_]?key|apikey|access[-_]?key|private[-_]?key|credential|authorization|signature|cookie)[A-Za-z0-9_.-]*["\']?\s*[:=]\s*["\']?)([^\s"\'&,;}\])]+)/i';

    /** `user:password@host` in a URL (a connection string, a webhook with basic auth): the credentials go, the host stays readable. */
    private const URL_USERINFO = '#(?<=://)[^\s/:@]+:[^\s/@]+(?=@)#';

    /** A private key block, whole or cut by the size cap. */
    private const PRIVATE_KEY_BLOCK = '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s';

    /** Keys whose value is a person's contact data: masked in the activity variant whatever the value looks like. */
    private const PERSONAL_PHONE_KEY = '/(phone|tel[eé]fono|celular|mobile|whatsapp|msisdn|contact[-_]?id)/i';

    private const PERSONAL_EMAIL_KEY = '/(e[-_]?mail|correo)/i';

    private const EMAIL_VALUE = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/';

    private const PHONE_VALUE = '/\+\d{8,15}\b|\b57\d{10}\b/';

    private const SENSITIVE_VALUE = '/\b(?:pub|prv)_(?:test|prod)_[A-Za-z0-9]+|\b(?:test|prod)_(?:events|integrity)_[A-Za-z0-9]+|\bBearer\s+[A-Za-z0-9._~+\/=-]+|\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}|\b(?:ck|cs)_[A-Fa-f0-9]{32,}|\bAKIA[0-9A-Z]{16}\b|\bsk-[A-Za-z0-9_-]{16,}|\bgh[pousr]_[A-Za-z0-9]{20,}|\bxox[abprs]-[A-Za-z0-9-]{10,}|\bBasic\s+[A-Za-z0-9+\/=]{8,}|\bav[ax]_[A-Za-z0-9]{16,}|\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{8,}|\bwhsec_[A-Za-z0-9]{8,}|\bAIza[0-9A-Za-z_-]{30,}|\bSG\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}|\b(?:SK|AC)[0-9a-f]{32}\b|hooks\.slack\.com\/services\/[A-Za-z0-9\/]+|\b(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{40,}\b/';

    /** 13 to 19 digits, with spaces or dashes: masked only when they pass the Luhn check of a real card number (an id of Meta, an order number or a phone id of 15 digits is not a card). */
    private const CARD_VALUE = '/\b(?:\d[ -]?){12,18}\d\b/';

    /** The stricter variant of the technical activity (credentials plus e-mails and phone numbers). */
    public function forActivity(): static
    {
        $copy = clone $this;
        $copy->maskPersonal = true;

        return $copy;
    }

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

        if ($this->maskPersonal && $key !== null && is_scalar($value) && $value !== '') {
            if (preg_match(self::PERSONAL_PHONE_KEY, $key)) {
                return $this->phone((string) $value);
            }

            if (preg_match(self::PERSONAL_EMAIL_KEY, $key)) {
                return $this->email((string) $value);
            }
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
    public function body(?string $raw, int $max = self::MAX_BYTES): array
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

        return $this->cap((string) $text, $max);
    }

    /** @return array{0: string, 1: bool} */
    public function cap(string $text, int $max = self::MAX_BYTES): array
    {
        if (strlen($text) <= $max) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, $max).'… [truncado, '.strlen($text).' bytes]', true];
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
        $value = (string) preg_replace(self::PRIVATE_KEY_BLOCK, self::MASK, $value);
        $value = (string) preg_replace(self::URL_USERINFO, self::MASK, $value);
        $value = (string) preg_replace(self::SENSITIVE_VALUE, self::MASK, $value);
        // After the known formats, so «Authorization: Bearer x» is masked as a whole token first; a value already masked is left alone.
        $value = (string) preg_replace_callback(self::SENSITIVE_PAIR, fn (array $m) => str_starts_with($m[2], '[REDACTED') ? $m[0] : $m[1].self::MASK, $value);
        $value = (string) preg_replace_callback(self::CARD_VALUE, fn (array $m) => $this->luhn($m[0]) ? self::MASK : $m[0], $value);

        if ($this->maskPersonal) {
            $value = (string) preg_replace_callback(self::EMAIL_VALUE, fn (array $m) => $this->email($m[0]), $value);
            $value = (string) preg_replace_callback(self::PHONE_VALUE, fn (array $m) => $this->phone($m[0]), $value);
        }

        return $value;
    }

    /** Luhn checksum of a run of digits (spaces and dashes ignored): true for a number that can be a real card. */
    private function luhn(string $digits): bool
    {
        $digits = preg_replace('/\D/', '', $digits) ?? '';
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;
                $digit -= $digit > 9 ? 9 : 0;
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /** `ana@example.com` becomes `a***@e***.com`: enough to tell two customers apart while debugging, not enough to contact one. */
    public function email(string $value): string
    {
        if (! str_contains($value, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $value, 2);
        $tld = str_contains($domain, '.') ? substr($domain, (int) strrpos($domain, '.')) : '';

        return mb_substr($local, 0, 1).'***@'.mb_substr($domain, 0, 1).'***'.$tld;
    }

    /** Only the last four digits stay. */
    public function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $digits === '' ? '***' : '••••'.substr($digits, -4);
    }
}
