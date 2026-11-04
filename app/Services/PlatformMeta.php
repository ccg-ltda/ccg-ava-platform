<?php

namespace App\Services;

use App\Models\MetaPlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The platform's Meta app (App ID, secrets, verify token, Graph version): ONE for the whole platform, administered from Configuraciones by the platform administration and stored in the
 * database with the secrets encrypted. Every place that needs one of these values asks `get()`: the database value wins and an empty one falls back to the environment variable it
 * always came from (config/services.php), so a deployment that only used `.env` keeps working until someone saves here. It holds nothing of any Workspace: each Workspace's account,
 * Page and tokens live in its own `integrations` row. Secrets are write-only (an empty field keeps the stored one) and never logged.
 */
class PlatformMeta
{
    /** key => config path of the environment fallback */
    public const FIELDS = [
        'app_id' => 'services.meta.app_id',
        'app_secret' => 'services.meta.app_secret',
        'instagram_app_id' => 'services.meta.instagram_app_id',
        'instagram_app_secret' => 'services.meta.instagram_app_secret',
        'webhook_verify_token' => 'services.meta.webhook_verify_token',
        'graph_version' => 'integrations.meta.graph_version',
    ];

    private const SECRETS = ['app_secret', 'instagram_app_secret', 'webhook_verify_token'];

    private const CACHE = 'platform.meta.row';

    private static bool $ready = false;

    /** Whether the table exists (the migration may not be applied yet). Only a YES is remembered, so a long-lived worker notices the migration without a restart. */
    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('meta_platform_settings');
    }

    /** The effective value (database first, then the environment), or null. */
    public static function get(string $key): ?string
    {
        abort_unless(isset(self::FIELDS[$key]), 500);

        $stored = self::stored()[$key] ?? null;

        return filled($stored) ? (string) $stored : (filled($fallback = config(self::FIELDS[$key])) ? (string) $fallback : null);
    }

    /** Where the effective value of a key comes from: `database`, `environment` or `none`. */
    public static function source(string $key): string
    {
        return filled(self::stored()[$key] ?? null) ? 'database' : (filled(config(self::FIELDS[$key])) ? 'environment' : 'none');
    }

    /** @return array<string, ?string> the decrypted row, cached briefly so a long-lived worker never keeps a stale secret for long */
    private static function stored(): array
    {
        if (! self::ready()) {
            return [];
        }

        return Cache::remember(self::CACHE, 60, fn () => MetaPlatformSetting::query()->first()?->makeVisible(self::SECRETS)->only(array_keys(self::FIELDS)) ?? []);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * Validates and saves the values the administrator sent. A blank value keeps what is stored; `clear` empties named fields. Formats are checked BEFORE saving (an App ID is digits, a
     * secret is the 32 hexadecimal characters Meta issues, the verify token is a plain string of 16 to 100 characters, the Graph version looks like v26.0). Any change clears the last validation.
     *
     * @param  array<string, mixed>  $data
     * @return list<string> keys of the fields that changed (for the audit; never values)
     *
     * @throws ValidationException
     */
    public function save(array $data, User $by): array
    {
        abort_unless(self::ready(), 409, 'La actualización de la base de datos que guarda esta configuración todavía no se ha aplicado.');

        $rules = [
            'app_id' => ['/^\d{8,20}$/', 'El App ID de Facebook son entre 8 y 20 dígitos.'],
            'instagram_app_id' => ['/^\d{8,20}$/', 'El App ID de Instagram son entre 8 y 20 dígitos.'],
            'app_secret' => ['/^[a-f0-9]{32}$/i', 'El secreto de la app son 32 caracteres hexadecimales (lo muestra Meta en Configuración de la app > Básica).'],
            'instagram_app_secret' => ['/^[a-f0-9]{32}$/i', 'El secreto de la app de Instagram son 32 caracteres hexadecimales.'],
            'webhook_verify_token' => ['/^[A-Za-z0-9._~\-]{16,100}$/', 'El token de verificación debe tener entre 16 y 100 letras, números o . _ ~ -'],
            'graph_version' => ['/^v\d{1,2}\.\d$/', 'La versión de Graph debe verse como v26.0.'],
        ];
        $errors = [];

        foreach ($rules as $field => [$pattern, $message]) {
            $value = $data[$field] ?? null;

            if (is_string($value) && trim($value) !== '' && ! preg_match($pattern, trim($value))) {
                $errors[$field] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $row = MetaPlatformSetting::query()->first() ?? new MetaPlatformSetting;
        $changed = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value) && trim($value) !== '' && trim($value) !== $row->{$field}) {
                $row->{$field} = trim($value);
                $changed[] = $field;
            }
        }

        foreach ((array) ($data['clear'] ?? []) as $field) {
            if (isset(self::FIELDS[$field]) && filled($row->{$field})) {
                $row->{$field} = null;
                $changed[] = $field;
            }
        }

        if ($changed !== []) {
            $row->forceFill(['validation_status' => null, 'validated_at' => null, 'validation_message' => null]);
        }

        $row->updated_by_user_id = $by->id;
        $row->save();
        self::forget();

        return $changed;
    }

    /** What the interface may know: never a secret, only whether each is set (and where it comes from) and its last four characters. @return array<string, mixed> */
    public function present(): array
    {
        $tail = fn (?string $value) => filled($value) ? '••••'.substr($value, -4) : null;
        $row = self::ready() ? MetaPlatformSetting::query()->first() : null;
        $field = fn (string $key, bool $secret) => ['set' => self::get($key) !== null, 'source' => self::source($key), 'hint' => $secret ? $tail(self::get($key)) : self::get($key)];

        return [
            'ready' => self::ready(),
            'fields' => ['app_id' => $field('app_id', false), 'app_secret' => $field('app_secret', true), 'instagram_app_id' => $field('instagram_app_id', false), 'instagram_app_secret' => $field('instagram_app_secret', true), 'webhook_verify_token' => $field('webhook_verify_token', true), 'graph_version' => $field('graph_version', false)],
            // What is still missing for each channel (the same text the integrations screen shows to the Workspaces).
            'missing' => ['instagram' => MetaConnection::platformIssue('instagram'), 'messenger' => MetaConnection::platformIssue('messenger')],
            'validation' => ['status' => $row?->validation_status, 'at' => $row?->validated_at?->toIso8601String(), 'message' => $row?->validation_message],
            'urls' => app(MetaConnection::class)->urls(),
        ];
    }

    public function recordValidation(bool $ok, string $message): void
    {
        $row = MetaPlatformSetting::query()->first() ?? new MetaPlatformSetting;
        $row->forceFill(['validation_status' => $ok ? 'ok' : 'failed', 'validated_at' => now(), 'validation_message' => $message])->save();
        self::forget();
    }
}
