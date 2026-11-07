<?php

namespace App\Payments\Gateways;

use App\Audit\AuditLogger;
use App\Models\PaymentLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * The link a customer opens to pay one payable. The token is random (48 characters), shown ONCE when the link is created and stored only as SHA-256: nobody, not even a
 * database reader, can rebuild a link. It expires, can be revoked, and only identifies the payable and Workspace it was created for: it carries no amount.
 */
class PaymentLinks
{
    public function __construct(private readonly Payables $payables, private readonly AuditLogger $audit) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{link: PaymentLink, token: string} */
    public function create(Workspace $workspace, Payable $payable, ?User $by): array
    {
        if ($payable->paymentWorkspaceId() !== $workspace->id || ! $payable->paymentOpen() || $payable->paymentAmountMinor() <= 0) {
            throw new PaymentException('Este pedido no se puede cobrar.', 'not_payable');
        }

        $token = Str::random(48);
        $link = PaymentLink::create([
            'workspace_id' => $workspace->id, 'payable_type' => $payable->paymentType(), 'payable_id' => $payable->paymentId(),
            'token_hash' => self::hash($token), 'expires_at' => now()->addHours((int) config('payments.link_ttl_hours')), 'created_by' => $by?->id,
        ]);

        $this->audit->recordForActor('created', 'payment', $link->id, 'Enlace de pago de '.$payable->paymentDescription(), [], $workspace, 'Pagos (automático)');

        return ['link' => $link, 'token' => $token];
    }

    /** Revokes every usable link of a payable; returns how many. */
    public function revokeFor(Workspace $workspace, Payable $payable): int
    {
        $count = PaymentLink::where('workspace_id', $workspace->id)->where('payable_type', $payable->paymentType())->where('payable_id', $payable->paymentId())
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);

        if ($count > 0) {
            $this->audit->recordForActor('updated', 'payment', $payable->paymentId(), 'Enlaces de pago de '.$payable->paymentDescription(), [AuditLogger::change('Enlaces de pago', 'Activos', 'Revocados')], $workspace, 'Pagos (automático)');
        }

        return $count;
    }

    /** The usable link of a token, or null (unknown, expired or revoked: the caller must not say which). */
    public function resolve(string $token): ?PaymentLink
    {
        if (strlen($token) !== 48) {
            return null;
        }

        $link = PaymentLink::where('token_hash', self::hash($token))->first();

        return $link && $link->isUsable() ? $link : null;
    }

    public function payable(PaymentLink $link): ?Payable
    {
        return $this->payables->find($link->workspace_id, $link->payable_type, $link->payable_id);
    }
}
