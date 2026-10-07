<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Generic HTTP/REST integration: base URL + endpoint + method, one authentication scheme, custom headers, query
 * parameters and a JSON body. It knows nothing about any provider. Credentials (the auth secret and every header /
 * query value flagged as secret) live only in `secrets` (encrypted); `config` never holds them.
 */
class HttpApiType implements IntegrationType
{
    public function __construct(private readonly SafeHttpTarget $target) {}

    public function key(): string
    {
        return 'http';
    }

    public function label(): string
    {
        return 'API HTTP / REST';
    }

    public function rules(array $input, ?Integration $current): array
    {
        $http = config('integrations.http');
        $authType = $input['auth_type'] ?? 'none';

        return [
            'base_url' => ['required', 'string', 'max:2048', fn ($attribute, $value, $fail) => $this->checkBaseUrl($value, $fail)],
            'endpoint' => ['nullable', 'string', 'max:1024', 'regex:/^[^\s]*$/'],
            'method' => ['required', Rule::in($http['methods'])],
            'timeout' => ['required', 'integer', 'between:1,'.$http['max_timeout']],
            'auth_type' => ['required', Rule::in(array_keys($http['auth_types']))],
            'auth_name' => [Rule::requiredIf($authType === 'api_key'), 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'auth_location' => [Rule::requiredIf($authType === 'api_key'), 'nullable', Rule::in(array_keys($http['api_key_locations']))],
            'auth_username' => [Rule::requiredIf($authType === 'basic'), 'nullable', 'string', 'max:255'],
            'auth_secret' => ['nullable', 'string', 'max:4096'],
            'headers' => ['nullable', 'array', 'max:'.$http['max_headers'], fn ($attribute, $value, $fail) => $this->checkUniqueNames($value, 'headers', $fail, caseInsensitive: true)],
            'headers.*.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/', fn ($attribute, $value, $fail) => $this->checkReservedHeader($value, $fail)],
            'headers.*.value' => ['nullable', 'string', 'max:2048'],
            'headers.*.secret' => ['nullable', 'boolean'],
            'query' => ['nullable', 'array', 'max:'.$http['max_query'], fn ($attribute, $value, $fail) => $this->checkUniqueNames($value, 'query', $fail, caseInsensitive: false)],
            'query.*.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\[\]-]+$/'],
            'query.*.value' => ['nullable', 'string', 'max:2048'],
            'query.*.secret' => ['nullable', 'boolean'],
            'body' => ['nullable', 'string', 'max:'.$http['max_body_bytes'], fn ($attribute, $value, $fail) => $this->checkJson($value, $fail)],
        ];
    }

    public function build(array $input, ?Integration $current): array
    {
        $oldConfig = $current?->config ?? [];
        $oldSecrets = $current?->secrets ?? [];
        $authType = $input['auth_type'];

        $secrets = ['auth' => null, 'headers' => [], 'query' => []];

        if ($authType !== 'none') {
            if (filled($input['auth_secret'] ?? null)) {
                $secrets['auth'] = $input['auth_secret'];
            } elseif (($oldConfig['auth']['type'] ?? null) === $authType && filled($oldSecrets['auth'] ?? null)) {
                $secrets['auth'] = $oldSecrets['auth']; // left blank while editing: keep the stored credential
            } else {
                throw ValidationException::withMessages(['auth_secret' => 'Escribe la credencial de la integración.']);
            }
        }

        $method = $input['method'];
        $endpoint = trim((string) ($input['endpoint'] ?? ''));

        $config = [
            'base_url' => rtrim(trim($input['base_url']), '/'),
            'endpoint' => $endpoint === '' ? '' : '/'.ltrim($endpoint, '/'),
            'method' => $method,
            'timeout' => (int) $input['timeout'],
            'auth' => [
                'type' => $authType,
                'name' => $authType === 'api_key' ? $input['auth_name'] : null,
                'location' => $authType === 'api_key' ? $input['auth_location'] : null,
                'username' => $authType === 'basic' ? $input['auth_username'] : null,
            ],
            'headers' => $this->pairs($input['headers'] ?? [], 'headers', $oldSecrets['headers'] ?? [], true, $secrets['headers']),
            'query' => $this->pairs($input['query'] ?? [], 'query', $oldSecrets['query'] ?? [], false, $secrets['query']),
            // A GET carries no body; the others may.
            'body' => $method === 'GET' || ! filled($input['body'] ?? null) ? null : $input['body'],
        ];

        return ['config' => $config, 'secrets' => $secrets];
    }

    public function form(Integration $integration): array
    {
        $config = $integration->config;
        $secrets = $integration->secrets ?? [];
        $row = fn (array $item, string $group, bool $lower) => [
            'name' => $item['name'],
            'value' => $item['secret'] ? '' : (string) $item['value'],
            'secret' => (bool) $item['secret'],
            'secret_set' => $item['secret'] && filled($secrets[$group][$lower ? strtolower($item['name']) : $item['name']] ?? null),
        ];

        return [
            'base_url' => $config['base_url'],
            'endpoint' => $config['endpoint'],
            'method' => $config['method'],
            'timeout' => $config['timeout'],
            'auth_type' => $config['auth']['type'],
            'auth_name' => $config['auth']['name'] ?? '',
            'auth_location' => $config['auth']['location'] ?? 'header',
            'auth_username' => $config['auth']['username'] ?? '',
            'auth_secret' => '',
            'auth_secret_set' => filled($secrets['auth'] ?? null),
            'headers' => array_map(fn ($item) => $row($item, 'headers', true), $config['headers']),
            'query' => array_map(fn ($item) => $row($item, 'query', false), $config['query']),
            'body' => $config['body'] ?? '',
        ];
    }

    public function summary(Integration $integration): array
    {
        $config = $integration->config;
        $parts = parse_url($config['base_url']);

        return [
            'method' => $config['method'],
            'host' => ($parts['host'] ?? $config['base_url']).(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'endpoint' => $config['endpoint'],
            'auth' => config("integrations.http.auth_types.{$config['auth']['type']}"),
        ];
    }

    public function supportsTest(): bool
    {
        return true;
    }

    /**
     * The HTTP settings are audited field by field. Credentials, secret header/query values and the body (free text
     * that may hold a credential) are masked: their change is recorded, their content never.
     */
    public function auditValues(array $config, ?array $secrets): array
    {
        $show = fn (mixed $value) => $value === null || $value === '' ? null : (string) $value;
        $auth = $config['auth'] ?? [];
        $hasSecrets = filled(array_filter($secrets['headers'] ?? [])) || filled(array_filter($secrets['query'] ?? [])) || filled($secrets['auth'] ?? null);

        $values = [
            'URL base' => $show($config['base_url'] ?? null),
            'Endpoint' => $show($config['endpoint'] ?? null),
            'Método' => $show($config['method'] ?? null),
            'Tiempo de espera (s)' => $show($config['timeout'] ?? null),
            'Autenticación' => config('integrations.http.auth_types.'.($auth['type'] ?? 'none')),
            'Nombre de la credencial' => $show($auth['name'] ?? null),
            'Ubicación de la credencial' => $show($auth['location'] ?? null),
            'Usuario' => $show($auth['username'] ?? null),
            'Body' => Masked::of($config['body'] ?? null),
            'Credenciales' => Masked::of($hasSecrets ? $secrets : null),
        ];

        foreach (['headers' => 'Header', 'query' => 'Parámetro'] as $key => $noun) {
            foreach ($config[$key] ?? [] as $row) {
                $values["{$noun} {$row['name']}"] = ($row['secret'] ?? false) ? 'Secreto' : $show($row['value'] ?? null);
            }
        }

        return $values;
    }

    public function test(Integration $integration): TestResult
    {
        $config = $integration->config;
        $secrets = $integration->secrets ?? [];
        $timeout = (int) $config['timeout'];

        try {
            [$url, $query, $headers] = $this->request($config, $secrets);
            $pinned = $this->target->resolve($url);
        } catch (UnsafeTarget $exception) {
            return TestResult::failure($exception->getMessage());
        }

        $start = hrtime(true);

        try {
            $response = $this->target->client($pinned, $timeout, $headers)->send($config['method'], $url, array_filter([
                'query' => $query,
                'body' => $config['body'] ?? null,
            ], fn ($value) => $value !== [] && $value !== null));
        } catch (ConnectionException $exception) {
            return TestResult::failure($this->connectionMessage($exception, $timeout), null, $this->elapsed($start));
        } catch (Throwable) {
            // Never report or echo the raw error: it may contain the URL (with query credentials) or headers.
            return TestResult::failure('No se pudo completar la solicitud.', null, $this->elapsed($start));
        }

        return $this->classify($response->status(), $this->elapsed($start));
    }

    // --- request building -----------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>} url, query, headers
     */
    private function request(array $config, array $secrets): array
    {
        $url = $config['base_url'].$config['endpoint'];
        $query = [];
        $headers = [];

        // Parameters written in the endpoint itself are kept; the client's `query` option would replace them.
        $inline = parse_url($url, PHP_URL_QUERY);
        if (is_string($inline) && $inline !== '') {
            parse_str($inline, $query);
            $url = strstr($url, '?', true);
        }

        foreach ($config['query'] as $item) {
            $query[$item['name']] = $item['secret'] ? ($secrets['query'][$item['name']] ?? '') : (string) $item['value'];
        }

        foreach ($config['headers'] as $item) {
            $headers[$item['name']] = $item['secret'] ? ($secrets['headers'][strtolower($item['name'])] ?? '') : (string) $item['value'];
        }

        $auth = $config['auth'];
        if ($auth['type'] === 'api_key') {
            $auth['location'] === 'query'
                ? $query[$auth['name']] = (string) ($secrets['auth'] ?? '')
                : $headers[$auth['name']] = (string) ($secrets['auth'] ?? '');
        } elseif ($auth['type'] === 'bearer') {
            $headers['Authorization'] = 'Bearer '.($secrets['auth'] ?? '');
        } elseif ($auth['type'] === 'basic') {
            $headers['Authorization'] = 'Basic '.base64_encode($auth['username'].':'.($secrets['auth'] ?? ''));
        }

        if (($config['body'] ?? null) !== null && ! collect($headers)->keys()->contains(fn ($name) => strtolower($name) === 'content-type')) {
            $headers['Content-Type'] = 'application/json';
        }

        return [$url, $query, $headers];
    }

    // --- results --------------------------------------------------------------------------------------------

    private function classify(int $status, int $ms): TestResult
    {
        return match (true) {
            $status >= 200 && $status < 300 => new TestResult(true, "Conexión correcta: el servicio respondió HTTP {$status} en {$ms} ms.", $status, $ms),
            in_array($status, [401, 403], true) => TestResult::failure("El servicio rechazó las credenciales (HTTP {$status}).", $status, $ms),
            $status === 404 => TestResult::failure('El servicio respondió HTTP 404: revisa la URL base y el endpoint.', $status, $ms),
            $status >= 300 && $status < 400 => TestResult::failure("El servicio respondió con una redirección (HTTP {$status}); las redirecciones no se siguen.", $status, $ms),
            default => TestResult::failure("El servicio respondió HTTP {$status}.", $status, $ms),
        };
    }

    private function connectionMessage(ConnectionException $exception, int $timeout): string
    {
        $text = strtolower($exception->getMessage());

        return match (true) {
            str_contains($text, 'timed out') || str_contains($text, 'timeout') => "Tiempo de espera agotado ({$timeout} s).",
            str_contains($text, 'ssl') || str_contains($text, 'certificate') => 'Error de certificado SSL al conectar con el servicio.',
            str_contains($text, 'resolve host') => 'No se pudo resolver el host de la URL.',
            default => 'No se pudo conectar con el servicio.',
        };
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }

    // --- building helpers -----------------------------------------------------------------------------------

    /**
     * Header/query rows: the visible value goes to `config`; a row flagged secret goes to `$secretsOut` (blank = keep).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $oldSecrets
     * @param  array<string, string>  $secretsOut
     * @return list<array{name: string, value: string|null, secret: bool}>
     */
    private function pairs(array $rows, string $field, array $oldSecrets, bool $caseInsensitive, array &$secretsOut): array
    {
        $result = [];

        foreach (array_values($rows) as $index => $row) {
            $name = trim((string) $row['name']);
            $key = $caseInsensitive ? strtolower($name) : $name;
            $value = (string) ($row['value'] ?? '');

            if (! empty($row['secret'])) {
                if ($value !== '') {
                    $secretsOut[$key] = $value;
                } elseif (filled($oldSecrets[$key] ?? null)) {
                    $secretsOut[$key] = $oldSecrets[$key];
                } else {
                    throw ValidationException::withMessages(["{$field}.{$index}.value" => 'Escribe el valor del secreto.']);
                }

                $result[] = ['name' => $name, 'value' => null, 'secret' => true];
            } else {
                $result[] = ['name' => $name, 'value' => $value, 'secret' => false];
            }
        }

        return $result;
    }

    // --- validation helpers ---------------------------------------------------------------------------------

    private function checkBaseUrl(mixed $value, callable $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            $fail('La URL base debe empezar por http:// o https:// e incluir el host.');
        } elseif (isset($parts['user']) || isset($parts['pass'])) {
            $fail('La URL base no puede incluir usuario ni contraseña; usa la autenticación.');
        } elseif (isset($parts['query']) || isset($parts['fragment'])) {
            $fail('La URL base no puede incluir parámetros ni fragmento; usa los campos de endpoint y parámetros.');
        }
    }

    private function checkReservedHeader(mixed $value, callable $fail): void
    {
        if (is_string($value) && in_array(strtolower($value), config('integrations.http.reserved_headers'), true)) {
            $fail("El header {$value} lo gestiona el cliente HTTP y no se puede definir.");
        }
    }

    private function checkUniqueNames(mixed $rows, string $label, callable $fail, bool $caseInsensitive): void
    {
        $names = collect(is_array($rows) ? $rows : [])
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => $caseInsensitive ? strtolower((string) $name) : (string) $name);

        if ($names->count() !== $names->unique()->count()) {
            $fail($label === 'headers' ? 'Hay headers repetidos.' : 'Hay parámetros repetidos.');
        }
    }

    private function checkJson(mixed $value, callable $fail): void
    {
        if (is_string($value) && $value !== '') {
            json_decode($value);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $fail('El body debe ser un JSON válido.');
            }
        }
    }
}
