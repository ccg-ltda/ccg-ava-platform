<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order of E-commerce or Delivery. It is registered, NOT necessarily paid: Ava does not manage payments. Its lines hold what was bought as it was
 * when it was placed; `total` is computed by the server and never edited. Never deleted: it leaves the flow by being `cancelled`.
 * Created only through App\Orders\OrderService.
 */
#[Fillable(['workspace_id', 'number', 'customer_id', 'conversation_id', 'idempotency_key', 'idempotency_fingerprint', 'customer_name', 'customer_phone', 'customer_email', 'delivery_method', 'delivery_address', 'delivery_city', 'delivery_reference', 'recipient_name', 'recipient_phone', 'location_id', 'location_name', 'currency', 'total', 'status', 'status_changed_at', 'notes', 'cancel_reason'])]
class Order extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'status_changed_at' => 'datetime', 'number' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, config('orders.terminal'), true);
    }

    public function auditResource(): string
    {
        return 'order';
    }

    /** No customer name: the label identifies the order without personal data. */
    public function auditLabel(): string
    {
        return "Pedido #{$this->number}";
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['status' => 'Estado', 'delivery_method' => 'Entrega', 'location_name' => 'Sede', 'total' => 'Total', 'currency' => 'Moneda', 'cancel_reason' => 'Motivo de cancelación'];
    }
}
