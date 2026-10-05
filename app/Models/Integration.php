<?php

namespace App\Models;

use App\Audit\Masked;
use App\Models\Concerns\Audited;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An external connection owned by one Workspace. `secrets` is encrypted at rest and hidden from serialization:
 * it must only be read by the integration type that talks to the service, never sent to the frontend.
 */
#[Fillable([
    'name', 'provider', 'description', 'type', 'is_active', 'config', 'secrets',
    'last_tested_at', 'last_test_ok', 'last_test_status', 'last_test_message',
])]
#[Hidden(['secrets'])]
class Integration extends Model
{
    use Audited;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    public function auditResource(): string
    {
        return 'integration';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['name' => 'Nombre', 'provider' => 'Proveedor', 'description' => 'Descripción', 'is_active' => 'Activa'];
    }

    /**
     * The generic HTTP settings are audited field by field. Credentials, secret header/query values and the body
     * (free text that may hold a credential) are masked: their change is recorded, their content never.
     */
    public function auditSnapshot(Closure $get): array
    {
        $config = $get('config') ?? [];
        $auth = $config['auth'] ?? [];
        $values = $this->auditBaseSnapshot($get) + [
            'URL base' => $this->auditDisplay($config['base_url'] ?? null),
            'Endpoint' => $this->auditDisplay($config['endpoint'] ?? null),
            'Método' => $this->auditDisplay($config['method'] ?? null),
            'Tiempo de espera (s)' => $this->auditDisplay($config['timeout'] ?? null),
            'Autenticación' => config('integrations.http.auth_types.'.($auth['type'] ?? 'none')),
            'Nombre de la credencial' => $this->auditDisplay($auth['name'] ?? null),
            'Ubicación de la credencial' => $this->auditDisplay($auth['location'] ?? null),
            'Usuario' => $this->auditDisplay($auth['username'] ?? null),
            'Body' => Masked::of($config['body'] ?? null),
            'Credenciales' => Masked::of($this->plainSecrets($get)),
        ];

        foreach (['headers' => 'Header', 'query' => 'Parámetro'] as $key => $noun) {
            foreach ($config[$key] ?? [] as $row) {
                $values["{$noun} {$row['name']}"] = ($row['secret'] ?? false) ? 'Secreto' : $this->auditDisplay($row['value'] ?? null);
            }
        }

        return $values;
    }

    /** Only the fingerprint of the decrypted credentials is ever used; they are not stored or shown. */
    private function plainSecrets(Closure $get): mixed
    {
        $secrets = $get('secrets');

        return blank(array_filter($secrets['headers'] ?? [])) && blank(array_filter($secrets['query'] ?? [])) && blank($secrets['auth'] ?? null) ? null : $secrets;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
