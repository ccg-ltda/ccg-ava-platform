<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The n8n instance of ONE Workspace, as Ava reaches it: the address of the instance (`config`) and the API Key
 * (`secrets`, encrypted, write-only) Ava uses to authenticate against n8n's public API. This is the Ava -> n8n
 * direction only. What n8n uses to talk to Ava (the token of each chatbot) is separate and lives on the chatbot.
 * Saved data is never a connection: the test makes a real, read-only request to the n8n API.
 */
class N8nType implements IntegrationType
{
    /** Header n8n's public API reads the API Key from. */
    public const KEY_HEADER = 'X-N8N-API-KEY';

    /** A read-only endpoint of n8n's public API: it answers 200 only with a valid, active API Key. */
    private const PROBE = '/api/v1/workflows';

    public function __construct(private readonly SafeHttpTarget $target) {}

    public function key(): string
    {
        return 'n8n';
    }

    public function label(): string
    {
        return 'n8n';
    }

    public function rules(array $input, ?Integration $current): array
    {
        return [
            'base_url' => ['required', 'string', 'max:2048', 'url:http,https', fn ($attribute, $value, $fail) => is_string($value) && $this->checkAddress($value, $fail)],
            'api_key' => ['nullable', 'string', 'max:4096'],
        ];
    }

    public function build(array $input, ?Integration $current): array
    {
        $key = filled($input['api_key'] ?? null) ? trim($input['api_key']) : ($current?->secrets['api_key'] ?? null);

        if (! filled($key)) {
            throw ValidationException::withMessages(['api_key' => 'Pega la API Key de n8n.']);
        }

        return ['config' => ['base_url' => $this->origin($input['base_url'])], 'secrets' => ['api_key' => $key]];
    }

    public function form(Integration $integration): array
    {
        return [
            'base_url' => $integration->config['base_url'],
            'api_key' => '',
            'api_key_set' => filled($integration->secrets['api_key'] ?? null),
        ];
    }

    public function summary(Integration $integration): array
    {
        return ['rows' => [['label' => 'Instancia', 'value' => parse_url($integration->config['base_url'], PHP_URL_HOST) ?: $integration->config['base_url']]]];
    }

    public function supportsTest(): bool
    {
        return true;
    }

    /**
     * Asks the n8n API for one workflow with the stored API Key. Only fixed messages are reported: neither the API Key
     * nor n8n's own error text is echoed.
     */
    public function test(Integration $integration): TestResult
    {
        $url = rtrim($integration->config['base_url'], '/').self::PROBE;
        $timeout = (int) config('integrations.n8n.timeout');

        try {
            $pinned = $this->target->resolve($url);
        } catch (UnsafeTarget $exception) {
            return $this->failure($exception->getMessage());
        }

        $start = hrtime(true);

        try {
            $response = $this->target->client($pinned, $timeout, [self::KEY_HEADER => (string) ($integration->secrets['api_key'] ?? ''), 'Accept' => 'application/json'])
                ->get($url, ['limit' => 1]);
        } catch (ConnectionException $exception) {
            return $this->failure($this->connectionReason($exception, $timeout), null, $this->elapsed($start));
        } catch (Throwable) {
            return $this->failure('Ocurrió un error al comunicarse con la instancia.', null, $this->elapsed($start));
        }

        $status = $response->status();
        $ms = $this->elapsed($start);

        return match (true) {
            $status >= 200 && $status < 300 => is_array($response->json('data'))
                ? new TestResult(true, 'n8n conectado correctamente.', $status, $ms)
                : $this->failure('La dirección responde, pero no parece la API de n8n. Revisa que sea la dirección de tu instancia.', $status, $ms),
            in_array($status, [401, 403], true) => $this->failure('n8n rechazó la API Key. Revisa que la hayas copiado completa y que siga activa.', $status, $ms),
            $status === 404 => $this->failure('No encontramos la API de n8n en esa dirección. Revisa la URL y que la API esté habilitada en tu instancia.', $status, $ms),
            $status >= 300 && $status < 400 => $this->failure('La dirección redirige a otra parte. Usa la dirección final de tu instancia (con https si corresponde).', $status, $ms),
            $status === 429 => $this->failure('n8n está limitando las solicitudes. Inténtalo de nuevo en unos minutos.', $status, $ms),
            default => $this->failure("n8n respondió con un error (HTTP {$status}). Inténtalo de nuevo más tarde.", $status, $ms),
        };
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        return [
            'URL de n8n' => ($config['base_url'] ?? '') === '' ? null : $config['base_url'],
            'API Key de n8n' => Masked::of($secrets['api_key'] ?? null),
        ];
    }

    /** The address of the instance without any path, query or credentials: where the API lives. */
    private function origin(string $url): string
    {
        $parts = parse_url(trim($url));

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function checkAddress(string $url, callable $fail): void
    {
        $parts = parse_url(trim($url));

        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('La URL no puede incluir usuario ni contraseña. Usa solo la dirección de tu n8n.');
        } elseif (! isset($parts['host'])) {
            $fail('Escribe la dirección completa de tu n8n, por ejemplo https://miempresa.app.n8n.cloud');
        }
    }

    private function failure(string $reason, ?int $status = null, ?int $ms = null): TestResult
    {
        return TestResult::failure("No pudimos conectarnos con n8n. {$reason}", $status, $ms);
    }

    private function connectionReason(ConnectionException $exception, int $timeout): string
    {
        $text = strtolower($exception->getMessage());

        return match (true) {
            str_contains($text, 'timed out') || str_contains($text, 'timeout') => "La instancia no respondió en {$timeout} segundos.",
            str_contains($text, 'ssl') || str_contains($text, 'certificate') => 'El certificado de seguridad de la instancia no es válido.',
            str_contains($text, 'resolve host') => 'No encontramos esa dirección. Revisa que la URL esté bien escrita.',
            default => 'No se pudo establecer la conexión. Revisa la URL y que la instancia esté disponible.',
        };
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
