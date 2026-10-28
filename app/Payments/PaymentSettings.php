<?php

namespace App\Payments;

use App\Models\PaymentProviderSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Credentials of the payment provider managed from the platform (Configuraciones > Impuestos y facturación). One row per provider and environment; the ACTIVE
 * environment decides which row (and so which API host and key prefix) is used. Secrets are write-only: an empty value on update keeps the stored one.
 * When no row exists for the active environment, the environment variables stay as the compatible fallback.
 */
class PaymentSettings
{
    public const ENVIRONMENTS = ['sandbox', 'production'];

    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('payment_provider_settings');
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    public function active(string $provider = 'wompi'): ?PaymentProviderSetting
    {
        return self::ready() ? PaymentProviderSetting::where('provider', $provider)->where('is_active', true)->first() : null;
    }

    public function row(string $environment, string $provider = 'wompi'): ?PaymentProviderSetting
    {
        return self::ready() ? PaymentProviderSetting::where('provider', $provider)->where('environment', $environment)->first() : null;
    }

    /**
     * Saves the credentials of one environment and (optionally) makes it the active one. Blank secrets keep what is stored. Changing any credential clears the validation.
     *
     * @param  array<string, mixed>  $data  environment, public_key, private_key, events_secret, integrity_secret, activate
     */
    public function save(array $data, User $by): PaymentProviderSetting
    {
        abort_unless(self::ready(), 409, 'La actualización de la base de datos que guarda esta configuración todavía no se ha aplicado.');

        $environment = $data['environment'];
        $prefix = $environment === 'production' ? 'pub_prod_' : 'pub_test_';

        if (filled($data['public_key'] ?? null) && ! str_starts_with($data['public_key'], $prefix)) {
            throw ValidationException::withMessages(['public_key' => 'La llave pública de '.($environment === 'production' ? 'Producción' : 'Sandbox')." debe empezar por {$prefix}."]);
        }

        $row = PaymentProviderSetting::firstOrNew(['provider' => 'wompi', 'environment' => $environment]);
        $changed = false;

        foreach (['public_key', 'private_key', 'events_secret', 'integrity_secret'] as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value) && trim($value) !== '' && trim($value) !== $row->{$field}) {
                $row->{$field} = trim($value);
                $changed = true;
            }
        }

        if ($changed) {
            $row->forceFill(['validation_status' => null, 'validated_at' => null, 'validation_message' => null]);
        }

        $row->updated_by_user_id = $by->id;

        if (! empty($data['activate'])) {
            PaymentProviderSetting::where('provider', 'wompi')->where('environment', '!=', $environment)->update(['is_active' => false]);
            $row->is_active = true;
        }

        $row->save();

        return $row;
    }

    /** What the interface may know: never a secret, only whether it is set and its last four characters. */
    public function present(?PaymentProviderSetting $row): array
    {
        $tail = fn (?string $secret) => filled($secret) ? '••••'.substr($secret, -4) : null;

        return [
            'publicKey' => $row?->public_key,
            'privateKeySet' => filled($row?->private_key), 'privateKeyHint' => $tail($row?->private_key),
            'eventsSecretSet' => filled($row?->events_secret), 'eventsSecretHint' => $tail($row?->events_secret),
            'integritySecretSet' => filled($row?->integrity_secret), 'integritySecretHint' => $tail($row?->integrity_secret),
            'isActive' => (bool) $row?->is_active,
            // incomplete (something missing) | saved (complete, never validated or changed since) | validated | failed
            'state' => match (true) {
                ! $row || blank($row->public_key) || blank($row->events_secret) || blank($row->integrity_secret) => 'incomplete',
                $row->validation_status === 'ok' => 'validated',
                $row->validation_status === 'failed' => 'failed',
                default => 'saved',
            },
            'validatedAt' => $row?->validated_at?->toIso8601String(),
            'validationMessage' => $row?->validation_message,
        ];
    }
}
