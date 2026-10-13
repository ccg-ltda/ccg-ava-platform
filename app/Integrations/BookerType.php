<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Booking\BookerCalendar;
use App\Booking\CalendarProvider;
use App\Booking\ProviderException;
use App\Models\Integration;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Booker account of ONE Workspace: the environment and the location (`config`, not secret) and the OAuth client
 * credentials plus the API subscription key (`secrets`, encrypted, write-only). Saved data is never a connection: the test makes
 * a real request (a token, then the location) and only a success reports it as verified. See BookerClient for what is known
 * about the API and from where, and BookerCalendar for the operations Ava really offers through it.
 */
class BookerType implements CalendarProviderType
{
    private const SECRETS = ['client_id', 'client_secret', 'subscription_key'];

    public function __construct(private readonly SafeHttpTarget $target) {}

    public function key(): string
    {
        return 'booker';
    }

    public function label(): string
    {
        return 'Booker';
    }

    public function description(): string
    {
        return 'Agenda externa: servicios, profesionales y citas se leen de Booker, que sigue siendo la fuente de verdad.';
    }

    public function fields(): array
    {
        return [
            ['name' => 'environment', 'label' => 'Entorno', 'kind' => 'select', 'required' => true, 'options' => [['value' => 'staging', 'label' => 'Sandbox (pruebas)'], ['value' => 'production', 'label' => 'Producción']], 'hint' => 'Usa Sandbox hasta que Booker confirme el acceso a producción.'],
            ['name' => 'location_id', 'label' => 'ID de la ubicación (Location ID)', 'kind' => 'text', 'required' => true, 'placeholder' => '1234'],
            ['name' => 'client_id', 'label' => 'Client ID', 'kind' => 'secret', 'required' => true],
            ['name' => 'client_secret', 'label' => 'Client Secret', 'kind' => 'secret', 'required' => true],
            ['name' => 'subscription_key', 'label' => 'Subscription Key', 'kind' => 'secret', 'required' => true, 'hint' => 'La clave de suscripción del portal de desarrolladores de Booker.'],
        ];
    }

    public function notice(): string
    {
        return 'Ava usa estas credenciales solo en el servidor para hablar con Booker. Guardarlas no conecta nada: usa «Probar conexión». Con Booker conectado y elegido como agenda, los servicios y profesionales se leen de Booker; Ava no mezcla esa agenda con la suya.';
    }

    public function rules(array $input, ?Integration $current): array
    {
        return [
            'environment' => ['required', Rule::in(array_keys(config('integrations.booker.urls')))],
            'location_id' => ['required', 'string', 'regex:/^\d{1,12}$/'],
            'client_id' => ['nullable', 'string', 'max:512'],
            'client_secret' => ['nullable', 'string', 'max:4096'],
            'subscription_key' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function build(array $input, ?Integration $current): array
    {
        $secrets = [];

        foreach (self::SECRETS as $name) {
            $value = filled($input[$name] ?? null) ? trim($input[$name]) : ($current?->secrets[$name] ?? null);

            if (! filled($value)) {
                throw ValidationException::withMessages([$name => 'Completa este dato de Booker.']);
            }

            $secrets[$name] = $value;
        }

        return ['config' => ['environment' => $input['environment'], 'location_id' => $input['location_id']], 'secrets' => $secrets];
    }

    public function form(Integration $integration): array
    {
        $form = ['environment' => $integration->config['environment'], 'location_id' => $integration->config['location_id']];

        foreach (self::SECRETS as $name) {
            $form[$name] = '';
            $form["{$name}_set"] = filled($integration->secrets[$name] ?? null);
        }

        return $form;
    }

    public function summary(Integration $integration): array
    {
        return ['rows' => [
            ['label' => 'Entorno', 'value' => $integration->config['environment'] === 'production' ? 'Producción' : 'Sandbox'],
            ['label' => 'Ubicación', 'value' => $integration->config['location_id']],
        ]];
    }

    public function supportsTest(): bool
    {
        return true;
    }

    /** Gets a token (proves the client credentials and the subscription key) and reads the location (proves it exists for them). Fixed messages only. */
    public function test(Integration $integration): TestResult
    {
        $client = new BookerClient($integration, $this->target);
        $start = hrtime(true);

        try {
            $client->authenticate();
            $client->get('/v4.1/customer/location/'.(int) $integration->config['location_id']);
        } catch (ProviderException $exception) {
            return TestResult::failure($this->reason($exception), null, $this->elapsed($start));
        }

        return new TestResult(true, 'Booker respondió: las credenciales y la ubicación son válidas.', 200, $this->elapsed($start));
    }

    public function calendar(Integration $integration): CalendarProvider
    {
        return new BookerCalendar($integration, new BookerClient($integration, $this->target));
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        return [
            'Entorno de Booker' => $config['environment'] ?? null,
            'Ubicación de Booker' => $config['location_id'] ?? null,
            'Client ID de Booker' => Masked::of($secrets['client_id'] ?? null),
            'Client Secret de Booker' => Masked::of($secrets['client_secret'] ?? null),
            'Subscription Key de Booker' => Masked::of($secrets['subscription_key'] ?? null),
        ];
    }

    private function reason(ProviderException $exception): string
    {
        return 'No pudimos conectarnos con Booker. '.match ($exception->codeName) {
            ProviderException::AUTH => 'Booker rechazó las credenciales, la clave de suscripción o la ubicación. Revísalas.',
            ProviderException::TIMEOUT => 'Booker no respondió a tiempo.',
            ProviderException::RATE_LIMITED => 'Booker está limitando las solicitudes. Inténtalo en unos minutos.',
            ProviderException::UNAVAILABLE => 'Booker no está disponible o la dirección no es accesible.',
            ProviderException::INVALID => 'Booker no reconoce esa ubicación.',
            default => 'Booker respondió con un error inesperado.',
        };
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
