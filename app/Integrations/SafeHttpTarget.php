<?php

namespace App\Integrations;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Guards server-side requests to URLs typed by users (SSRF). Only http(s), no credentials in the URL, and the host
 * must resolve to public addresses (unless `integrations.allow_private_hosts` is on). The validated address is returned
 * so the request can be pinned to it and a later DNS change cannot redirect it to an internal service.
 */
class SafeHttpTarget
{
    /** Responses announcing more than this are dropped: a test or a relay only needs a small answer. */
    private const MAX_RESPONSE_BYTES = 1_048_576;

    private const SPECIAL_USE = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '64:ff9b::/96', '2001:db8::/32'];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /** @param  (callable(string): list<string>)|null  $resolver  host -> IPv4 addresses (injected in tests) */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = Closure::fromCallable($resolver ?? fn (string $host): array => gethostbynamel($host) ?: []);
    }

    /**
     * @return array{scheme: string, host: string, port: int, ip: string}
     *
     * @throws UnsafeTarget
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeTarget('La URL no es válida.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeTarget('Solo se admiten URLs http o https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeTarget('La URL no puede incluir usuario ni contraseña; usa la autenticación de la integración.');
        }

        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($ips === []) {
            throw new UnsafeTarget('No se pudo resolver el host de la URL.');
        }

        if (! config('integrations.allow_private_hosts')) {
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || $this->isSpecialUse($ip)) {
                    throw new UnsafeTarget('Por seguridad no se permite conectar con direcciones internas o reservadas.');
                }
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * A client for a target already validated by `resolve()`: pinned to its address, no redirects and no huge responses.
     *
     * @param  array{scheme: string, host: string, port: int, ip: string}  $pinned
     * @param  array<string, string>  $headers
     */
    public function client(array $pinned, int $timeout, array $headers = []): PendingRequest
    {
        // `stream` is deliberately not used: it switches Guzzle to the PHP stream handler, which ignores CURLOPT_RESOLVE.
        $options = [
            'on_headers' => function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Response too large.');
                }
            },
        ];

        // Pin the connection to the address that was validated, so DNS cannot send it somewhere internal later.
        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$pinned['host']}:{$pinned['port']}:{$pinned['ip']}"]];
        }

        return Http::timeout($timeout)
            ->connectTimeout(min($timeout, 10))
            ->withoutRedirecting()
            ->withOptions($options)
            ->withHeaders($headers);
    }

    /** Special-use ranges PHP's reserved filter lets through (shared address space, documentation, benchmarking, NAT64). */
    private function isSpecialUse(string $ip): bool
    {
        $packed = inet_pton($ip);

        foreach (self::SPECIAL_USE as $range) {
            [$base, $bits] = explode('/', $range);
            $baseBin = inet_pton($base);

            if (strlen($baseBin) !== strlen($packed)) {
                continue;
            }

            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;

            if (substr($packed, 0, $bytes) !== substr($baseBin, 0, $bytes)) {
                continue;
            }

            if ($rest === 0 || (ord($packed[$bytes]) >> (8 - $rest)) === (ord($baseBin[$bytes]) >> (8 - $rest))) {
                return true;
            }
        }

        return false;
    }
}
