<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The customer record of a Workspace (see config/customers.php). Created and read only through `$workspace->customers()`; never
 * deleted, only deactivated. `phone_normalized` and `email_normalized` are the identities used to detect duplicates and are always
 * derived from `phone` / `email` here, so no code path can leave them out of step.
 */
#[Fillable(['first_name', 'last_name', 'phone', 'email', 'source', 'notes', 'marketing_consent', 'consent_note', 'preferred_channel', 'is_active'])]
class Customer extends Model
{
    use Audited {
        auditDisplay as private defaultAuditDisplay;
    }

    public const CONSENT_GRANTED = 'granted';

    public const CONSENT_REVOKED = 'revoked';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'consent_at' => 'datetime', 'anonymized_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $customer->phone_normalized = static::normalizePhone($customer->phone);
            $customer->email_normalized = static::normalizeEmail($customer->email);

            if ($customer->isDirty('marketing_consent')) {
                $customer->consent_at = now();
            }
        });
    }

    /** Digits only, so "+57 300 111" and "57300111" are the same number (same rule as BookingCustomer). */
    public static function normalizePhone(?string $phone): ?string
    {
        return BookingCustomer::normalizePhone($phone);
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CustomerTag::class);
    }

    public function auditResource(): string
    {
        return 'customer';
    }

    public function auditLabel(): string
    {
        return $this->fullName();
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['source' => 'Origen', 'marketing_consent' => 'Consentimiento', 'preferred_channel' => 'Canal preferido', 'is_active' => 'Activo'];
    }

    /** Whatever identifies or describes a person is audited as "changed", never stored: the audit log is read by people who may not see them. */
    public function auditMasked(): array
    {
        return ['first_name' => 'Nombre', 'last_name' => 'Apellidos', 'phone' => 'Teléfono', 'email' => 'Correo', 'notes' => 'Notas', 'consent_note' => 'Constancia del consentimiento'];
    }

    protected function auditDisplay(mixed $value): ?string
    {
        return is_string($value) && array_key_exists($value, config('customers.consents')) ? config('customers.consents')[$value] : $this->defaultAuditDisplay($value);
    }
}
