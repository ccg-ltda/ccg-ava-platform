<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Booking\BookerCalendar;
use App\Booking\BookerMerchantCalendar;
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
            ['name' => 'api', 'label' => 'API de Booker', 'kind' => 'select', 'required' => true, 'default' => 'merchant', 'options' => [['value' => 'merchant', 'label' => 'Merchant (horarios y reservas)'], ['value' => 'customer', 'label' => 'Customer (solo lectura de servicios)']], 'hint' => 'Merchant es la API que Booker confirmó para consultar horarios y crear citas.'],
            ['name' => 'location_id', 'label' => 'Sucursal principal (Location ID)', 'kind' => 'text', 'required' => true, 'placeholder' => '1234', 'hint' => 'El ID de la ubicación que Booker habilitó para este entorno. En producción nunca uses el ID de pruebas.'],
            ['name' => 'locations', 'label' => 'Otras sucursales (opcional)', 'kind' => 'textarea', 'required' => false, 'placeholder' => "5678 | Sede Norte\n9012 | Sede Sur", 'hint' => 'Una por línea: Location ID | nombre. Con varias sucursales el asistente pregunta cuál quiere el cliente.'],
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
            'api' => ['nullable', Rule::in(['merchant', 'customer'])],
            'location_id' => ['required', 'string', 'regex:/^\d{1,12}$/'],
            'locations' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) use ($input) {
                if ($this->parseLocations((string) $value, $input['location_id'] ?? null) === null) {
                    $fail('Escribe una sucursal por línea con el formato «Location ID | nombre», sin repetir IDs (máximo 20).');
                }
            }],
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

        $main = trim($input['location_id']);
        $extra = $this->parseLocations((string) ($input['locations'] ?? ''), $main) ?? [];
        $test = array_map('strval', config('integrations.booker.merchant.test_location_ids'));

        // The ID of Booker's shared test location can never be the production configuration, whatever the form said.
        if ($input['environment'] === 'production' && array_intersect(array_merge([$main], array_column($extra, 'id')), $test) !== []) {
            throw ValidationException::withMessages(['location_id' => 'Ese ID es una ubicación de pruebas de Booker: no se acepta en producción.']);
        }

        return ['config' => ['environment' => $input['environment'], 'api' => $input['api'] ?? ($current?->config['api'] ?? 'customer'), 'location_id' => $main, 'locations' => array_merge([['id' => $main, 'name' => 'Sede principal']], $extra)], 'secrets' => $secrets];
    }

    public function form(Integration $integration): array
    {
        $form = [
            'environment' => $integration->config['environment'], 'api' => $integration->config['api'] ?? 'customer', 'location_id' => $integration->config['location_id'],
            'locations' => collect($integration->config['locations'] ?? [])->slice(1)->map(fn (array $location) => "{$location['id']} | {$location['name']}")->implode("\n"),
        ];

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
            ['label' => 'API', 'value' => ($integration->config['api'] ?? 'customer') === 'merchant' ? 'Merchant' : 'Customer'],
            ['label' => 'Ubicación', 'value' => $integration->config['location_id']],
            ['label' => 'Sucursales configuradas', 'value' => (string) max(1, count($integration->config['locations'] ?? []))],
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

            if (($integration->config['api'] ?? 'customer') === 'merchant') {
                // A harmless read of the catalog proves the token works with the Merchant API for that location (nothing is created).
                $client->merchant('treatments', ['LocationID' => (int) $integration->config['location_id'], 'UsePaging' => true, 'PageSize' => 1, 'PageNumber' => 1], retry: true);
            } else {
                $client->get('/v4.1/customer/location/'.(int) $integration->config['location_id']);
            }
        } catch (ProviderException $exception) {
            return TestResult::failure($this->reason($exception), null, $this->elapsed($start));
        }

        return new TestResult(true, 'Booker respondió: las credenciales y la ubicación son válidas.', 200, $this->elapsed($start));
    }

    public function calendar(Integration $integration): CalendarProvider
    {
        $client = new BookerClient($integration, $this->target);

        return ($integration->config['api'] ?? 'customer') === 'merchant' ? new BookerMerchantCalendar($integration, $client) : new BookerCalendar($integration, $client);
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        return [
            'Entorno de Booker' => $config['environment'] ?? null,
            'API de Booker' => $config['api'] ?? 'customer',
            'Ubicación de Booker' => $config['location_id'] ?? null,
            'Sucursales de Booker' => collect($config['locations'] ?? [])->pluck('id')->implode(', ') ?: null,
            'Client ID de Booker' => Masked::of($secrets['client_id'] ?? null),
            'Client Secret de Booker' => Masked::of($secrets['client_secret'] ?? null),
            'Subscription Key de Booker' => Masked::of($secrets['subscription_key'] ?? null),
        ];
    }

    /**
     * One location per line, `LocationID | name`. Null when a line is malformed, an ID repeats (or repeats the main one) or there are more than 20.
     *
     * @return list<array{id: string, name: string}>|null
     */
    private function parseLocations(string $text, ?string $main): ?array
    {
        $seen = $main !== null ? [trim($main)] : [];
        $locations = [];

        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*(\d{1,12})\s*\|\s*(.{1,80}?)\s*$/u', $line, $match) !== 1 || in_array($match[1], $seen, true) || count($locations) >= 20) {
                return null;
            }

            $seen[] = $match[1];
            $locations[] = ['id' => $match[1], 'name' => $match[2]];
        }

        return $locations;
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
