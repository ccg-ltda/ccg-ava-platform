<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Models\Integration;
use App\Payments\Gateways\PaymentGatewayType;
use App\Payments\Gateways\WompiClient;
use App\Payments\Gateways\WompiEventToken;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The Wompi (Colombia) commercial account of ONE Workspace. NOT the platform's own Wompi (`payment_provider_settings`, which charges the public sign-up): other table, other
 * credentials, other events address, other history. The public key is `config` (it is public by design); the integrity and events secrets are `secrets` (encrypted, write-only).
 * Ava asks for no private key: it does not use one (refunds by API are not implemented).
 *
 * Formats come from Wompi's documentation (docs.wompi.co): public keys `pub_test_` / `pub_prod_`, secrets `test_integrity_` / `prod_integrity_` and `test_events_` / `prod_events_`,
 * sandbox and production never mixed. The connection test is REAL (it asks Wompi for the merchant of that public key in that environment) and is the only way a configuration
 * becomes «validada»; it cannot prove the two secrets, which Wompi offers no endpoint to check: they are proven by the first sandbox payment and its signed event.
 */
class WompiType implements PaymentGatewayType
{
    private const SECRETS = ['integrity_secret', 'events_secret'];

    public function __construct(private readonly WompiClient $client) {}

    public function key(): string
    {
        return 'wompi';
    }

    public function label(): string
    {
        return 'Wompi';
    }

    public function description(): string
    {
        return 'Pagos en Colombia (COP) con el Web Checkout de Wompi. El cliente paga en el sitio seguro de Wompi: Ava no ve ni guarda datos de tarjeta. Cuenta propia de este Workspace.';
    }

    public function notice(): string
    {
        return 'Son las llaves de TU comercio en Wompi (Panel de Wompi > Desarrolladores). Se guardan cifradas y no se vuelven a mostrar; las de Sandbox y las de Producción no se mezclan. Guardar no conecta nada: usa «Probar conexión». Para recibir los pagos, copia la dirección de eventos de este formulario en el panel de Wompi (Desarrolladores > Eventos). Esta conexión es independiente de la cuenta de Wompi con la que Ava cobra sus propias suscripciones.';
    }

    public function blockedBy(): array
    {
        return [
            'Prueba en Sandbox con llaves reales: pago aprobado y rechazado, evento firmado y reintento.',
            'Confirmar con Wompi la consulta de transacciones por referencia y la anulación por API (no se pudieron verificar en su documentación).',
        ];
    }

    public function fields(): array
    {
        return [
            ['name' => 'environment', 'label' => 'Ambiente', 'kind' => 'select', 'section' => 'Ambiente', 'required' => true, 'default' => 'sandbox', 'options' => [['value' => 'sandbox', 'label' => 'Sandbox (pruebas)'], ['value' => 'production', 'label' => 'Producción']], 'hint' => 'Producción no cobra mientras la instalación no la autorice. Al cambiar de ambiente hay que escribir de nuevo las llaves y volver a probar la conexión.'],
            ['name' => 'public_key', 'label' => 'Llave pública', 'kind' => 'text', 'section' => 'Credenciales de Wompi', 'required' => true, 'placeholder' => 'pub_test_…', 'hint' => 'Empieza por pub_test_ (Sandbox) o pub_prod_ (Producción).'],
            ['name' => 'integrity_secret', 'label' => 'Secreto de integridad', 'kind' => 'secret', 'section' => 'Credenciales de Wompi', 'required' => true, 'placeholder' => 'test_integrity_…', 'hint' => 'Firma el pago que Ava envía a Wompi. Empieza por test_integrity_ o prod_integrity_.'],
            ['name' => 'events_secret', 'label' => 'Secreto de eventos', 'kind' => 'secret', 'section' => 'Credenciales de Wompi', 'required' => true, 'placeholder' => 'test_events_…', 'hint' => 'Comprueba que los eventos vienen de Wompi. Empieza por test_events_ o prod_events_.'],
            ['name' => 'events_url', 'label' => 'Dirección de eventos (URL de eventos en Wompi)', 'kind' => 'copy', 'section' => 'Dirección de eventos', 'required' => false, 'hint' => 'Cópiala en Wompi (Desarrolladores > Eventos), una por ambiente. Es propia de esta conexión.'],
            ['name' => 'rotate_events_url', 'label' => '¿Generar una dirección de eventos nueva?', 'kind' => 'select', 'section' => 'Dirección de eventos', 'required' => false, 'default' => 'no', 'options' => [['value' => 'no', 'label' => 'No, conservar la actual'], ['value' => 'yes', 'label' => 'Sí: la anterior deja de funcionar']], 'hint' => 'Si la cambias, actualízala en el panel de Wompi.'],
        ];
    }

    public function rules(array $input, ?Integration $current): array
    {
        $env = ($input['environment'] ?? null) === 'production' ? 'prod' : 'test';
        $prefixed = fn (string $prefix, string $label) => function (string $attribute, mixed $value, \Closure $fail) use ($prefix, $label, $env) {
            if ($value !== null && $value !== '' && ! str_starts_with((string) $value, str_replace('{env}', $env, $prefix))) {
                $fail("{$label} no corresponde al ambiente elegido: debe empezar por ".str_replace('{env}', $env, $prefix).'.');
            }
        };

        return [
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'public_key' => ['nullable', 'string', 'regex:/^pub_(test|prod)_[A-Za-z0-9]{8,64}$/', $prefixed('pub_{env}_', 'La llave pública')],
            'integrity_secret' => ['nullable', 'string', 'regex:/^(test|prod)_integrity_[A-Za-z0-9]{8,128}$/', $prefixed('{env}_integrity_', 'El secreto de integridad')],
            'events_secret' => ['nullable', 'string', 'regex:/^(test|prod)_events_[A-Za-z0-9]{8,128}$/', $prefixed('{env}_events_', 'El secreto de eventos')],
            'rotate_events_url' => ['nullable', Rule::in(['yes', 'no'])],
        ];
    }

    public function messages(): array
    {
        return [
            'public_key.regex' => 'La llave pública de Wompi empieza por pub_test_ o pub_prod_.',
            'integrity_secret.regex' => 'El secreto de integridad empieza por test_integrity_ o prod_integrity_.',
            'events_secret.regex' => 'El secreto de eventos empieza por test_events_ o prod_events_.',
        ];
    }

    public function build(array $input, ?Integration $current): array
    {
        $secrets = [];

        foreach (self::SECRETS as $name) {
            $value = filled($input[$name] ?? null) ? trim($input[$name]) : ($current?->secrets[$name] ?? null);

            if (filled($value)) {
                $secrets[$name] = $value;
            }
        }

        $salt = ($input['rotate_events_url'] ?? 'no') === 'yes' || ! filled($current?->config['events_salt'] ?? null) ? Str::random(24) : $current->config['events_salt'];

        return ['config' => array_filter([
            'environment' => $input['environment'],
            'public_key' => filled($input['public_key'] ?? null) ? trim($input['public_key']) : ($current?->config['public_key'] ?? null),
            'events_salt' => $salt,
        ], fn ($value) => $value !== null), 'secrets' => $secrets];
    }

    public function problems(array $config, ?array $secrets): array
    {
        $env = ($config['environment'] ?? 'sandbox') === 'production' ? 'prod' : 'test';
        $problems = [];

        foreach ([['public_key', $config['public_key'] ?? null, "pub_{$env}_", 'la llave pública'], ['integrity_secret', $secrets['integrity_secret'] ?? null, "{$env}_integrity_", 'el secreto de integridad'], ['events_secret', $secrets['events_secret'] ?? null, "{$env}_events_", 'el secreto de eventos']] as [, $value, $prefix, $label]) {
            if (! filled($value)) {
                $problems[] = "Falta {$label}.";
            } elseif (! str_starts_with((string) $value, $prefix)) {
                $problems[] = ucfirst($label).' no corresponde al ambiente elegido: vuelve a escribirlo.';
            }
        }

        return $problems;
    }

    public function form(Integration $integration): array
    {
        $token = WompiEventToken::for($integration);
        $form = [
            'environment' => $integration->config['environment'] ?? 'sandbox',
            'public_key' => $integration->config['public_key'] ?? '',
            'rotate_events_url' => 'no',
            'events_url' => $token ? url('/api/webhooks/payments/wompi/'.$token) : null,
        ];

        foreach (self::SECRETS as $name) {
            $value = $integration->secrets[$name] ?? null;
            $form[$name] = '';
            $form["{$name}_set"] = filled($value);
            $form["{$name}_mask"] = filled($value) ? '••••'.substr($value, -4) : null;
        }

        return $form;
    }

    public function summary(Integration $integration): array
    {
        $key = $integration->config['public_key'] ?? null;

        return ['rows' => array_values(array_filter([
            ['label' => 'Ambiente', 'value' => ($integration->config['environment'] ?? 'sandbox') === 'production' ? 'Producción' : 'Sandbox'],
            filled($key) ? ['label' => 'Llave pública', 'value' => substr($key, 0, strpos($key, '_', 4) + 1).'••••'.substr($key, -4)] : null,
            ['label' => 'Secretos', 'value' => filled($integration->secrets['integrity_secret'] ?? null) && filled($integration->secrets['events_secret'] ?? null) ? 'Guardados' : 'Incompletos'],
        ]))];
    }

    public function supportsTest(): bool
    {
        return true;
    }

    /** Asks Wompi for the merchant of the public key in that environment. Fixed messages only; the answer is never stored. */
    public function test(Integration $integration): TestResult
    {
        if ($this->problems($integration->config ?? [], $integration->secrets) !== []) {
            return TestResult::failure('Configuración incompleta: faltan llaves o no corresponden al ambiente elegido.');
        }

        $started = hrtime(true);
        $response = $this->client->merchant($integration);
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);
        $production = ($integration->config['environment'] ?? 'sandbox') === 'production';

        if ($response['ok'] && isset($response['data']['id'])) {
            return new TestResult(true, 'Wompi reconoció la llave pública en '.($production ? 'Producción' : 'Sandbox').'. Los secretos de integridad y de eventos no se pueden comprobar con la API: se comprueban con el primer pago de prueba y su evento.', $response['status'], $ms);
        }

        return TestResult::failure(match (true) {
            $response['error'] === 'timeout' => 'Wompi no respondió a tiempo.',
            $response['error'] === 'connection' => 'No se pudo conectar con Wompi.',
            in_array($response['status'], [401, 403, 404, 422], true) => 'Wompi no reconoce esa llave pública en este ambiente.',
            default => 'Wompi respondió con un error inesperado.',
        }, $response['status'], $ms);
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        return [
            'Ambiente de Wompi (Workspace)' => $config['environment'] ?? null,
            'Llave pública de Wompi (Workspace)' => Masked::of($config['public_key'] ?? null),
            'Secreto de integridad de Wompi (Workspace)' => Masked::of($secrets['integrity_secret'] ?? null),
            'Secreto de eventos de Wompi (Workspace)' => Masked::of($secrets['events_secret'] ?? null),
            'Dirección de eventos de Wompi (Workspace)' => Masked::of($config['events_salt'] ?? null),
        ];
    }
}
