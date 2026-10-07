<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Models\Integration;

/**
 * The web widget of ONE Workspace: the n8n webhook that answers the visitors' messages, an optional shared secret
 * sent to it (`secrets`, encrypted, write-only) and the sites allowed to embed the widget. Ava forwards each message to
 * the webhook and returns its reply; it does not call a model itself.
 */
class WebType implements ChannelIntegrationType
{
    public const SECRET_HEADER = 'X-Ava-Secret';

    private const ORIGIN = '/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/';

    public function key(): string
    {
        return 'web';
    }

    public function label(): string
    {
        return 'Widget web';
    }

    public function fields(): array
    {
        return [
            ['name' => 'webhook_url', 'label' => 'URL del webhook de n8n', 'kind' => 'text', 'required' => true, 'placeholder' => 'https://n8n.ejemplo.com/webhook/ava-web', 'hint' => 'Webhook de producción del workflow que responde a los visitantes.'],
            ['name' => 'webhook_secret', 'label' => 'Secreto compartido (opcional)', 'kind' => 'secret', 'required' => false, 'hint' => 'Se envía en el header '.self::SECRET_HEADER.'; valídalo en el Webhook de n8n con una credencial Header Auth.'],
            ['name' => 'allowed_origins', 'label' => 'Sitios permitidos (opcional)', 'kind' => 'textarea', 'required' => false, 'placeholder' => "https://www.ejemplo.com\nhttps://tienda.ejemplo.com", 'hint' => 'Un origen por línea. Vacío = cualquier sitio puede mostrar el widget.'],
        ];
    }

    public function notice(): string
    {
        return 'Ava reenvía cada mensaje del widget a este webhook y devuelve su respuesta {"reply": "..."}. El workflow debe leer las instrucciones del chatbot desde Ava.';
    }

    public function rules(array $input, ?Integration $current): array
    {
        return [
            'webhook_url' => ['required', 'string', 'max:2048', 'url:http,https', fn ($attribute, $value, $fail) => $this->checkNoCredentials($value, $fail)],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'allowed_origins' => ['nullable', 'string', 'max:2000', fn ($attribute, $value, $fail) => $this->checkOrigins($value, $fail)],
        ];
    }

    public function build(array $input, ?Integration $current): array
    {
        $secret = filled($input['webhook_secret'] ?? null) ? trim($input['webhook_secret']) : ($current?->secrets['webhook_secret'] ?? null);

        return [
            'config' => [
                'webhook_url' => trim($input['webhook_url']),
                'allowed_origins' => $this->origins($input['allowed_origins'] ?? ''),
            ],
            'secrets' => array_filter(['webhook_secret' => $secret]),
        ];
    }

    public function form(Integration $integration): array
    {
        return [
            'webhook_url' => $integration->config['webhook_url'],
            'webhook_secret' => '',
            'webhook_secret_set' => filled($integration->secrets['webhook_secret'] ?? null),
            'allowed_origins' => implode("\n", $integration->config['allowed_origins'] ?? []),
        ];
    }

    public function summary(Integration $integration): array
    {
        $origins = $integration->config['allowed_origins'] ?? [];

        return ['rows' => [
            ['label' => 'Webhook', 'value' => parse_url($integration->config['webhook_url'], PHP_URL_HOST) ?: '—'],
            ['label' => 'Sitios permitidos', 'value' => $origins === [] ? 'Cualquiera' : (string) count($origins)],
        ]];
    }

    public function agentView(Integration $integration): array
    {
        return [];
    }

    /** Calling the webhook would run the workflow (and its model) for a message nobody wrote, so there is no test. */
    public function supportsTest(): bool
    {
        return false;
    }

    public function test(Integration $integration): TestResult
    {
        return TestResult::failure('Esta integración no admite prueba de conexión: probarla ejecutaría el workflow.');
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        return [
            'URL del webhook' => ($config['webhook_url'] ?? '') === '' ? null : $config['webhook_url'],
            'Sitios permitidos' => ($config['allowed_origins'] ?? []) === [] ? null : implode(', ', $config['allowed_origins']),
            'Secreto compartido' => Masked::of($secrets['webhook_secret'] ?? null),
        ];
    }

    /** @return list<string> */
    private function origins(?string $text): array
    {
        return collect(preg_split('/\R/', (string) $text))->map(fn ($line) => strtolower(rtrim(trim($line), '/')))->filter()->unique()->values()->all();
    }

    private function checkNoCredentials(string $url, callable $fail): void
    {
        $parts = parse_url($url);

        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('La URL no puede incluir usuario ni contraseña; usa el secreto compartido.');
        }
    }

    private function checkOrigins(?string $text, callable $fail): void
    {
        $origins = $this->origins($text);

        if (count($origins) > 20) {
            $fail('Indica como máximo 20 sitios.');
        }

        foreach ($origins as $origin) {
            if (! preg_match(self::ORIGIN, $origin)) {
                $fail("«{$origin}» no es un origen válido (por ejemplo https://www.ejemplo.com).");

                return;
            }
        }
    }
}
