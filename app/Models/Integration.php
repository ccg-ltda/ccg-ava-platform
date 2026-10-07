<?php

namespace App\Models;

use App\Integrations\IntegrationRegistry;
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

    /** The common fields plus whatever the integration type audits (see IntegrationType::auditValues). */
    public function auditSnapshot(Closure $get): array
    {
        $type = app(IntegrationRegistry::class)->get($get('type') ?? $this->type);

        return $this->auditBaseSnapshot($get) + $type->auditValues($get('config') ?? [], $get('secrets'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
