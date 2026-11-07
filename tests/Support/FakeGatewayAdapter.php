<?php

namespace Tests\Support;

use App\Models\Integration;
use App\Models\PaymentTransaction;
use App\Payments\Gateways\CheckoutContext;
use App\Payments\Gateways\GatewayAdapter;
use App\Payments\Gateways\GatewayCapabilities;
use App\Payments\Gateways\GatewayCheckout;
use App\Payments\Gateways\GatewayOutcome;
use App\Payments\Gateways\GatewayResult;
use App\Payments\Gateways\RefundResult;
use App\Payments\Gateways\RefundsPayments;
use RuntimeException;

/**
 * A provider double for the TEST SUITE ONLY (it is registered by the tests in config, never by the application): it speaks no real protocol and never reaches a network.
 * What it «answers» is whatever the test sets in `$answers` (by provider reference). This is a simulation and is never presented as a Sandbox test.
 */
class FakeGatewayAdapter implements GatewayAdapter, RefundsPayments
{
    /** @var array<string, GatewayResult|callable> */
    public static array $answers = [];

    public static int $sessions = 0;

    public static int $queries = 0;

    public static bool $failSession = false;

    public static string $url = 'https://pay.fake.test/authorize';

    public static ?CheckoutContext $context = null;

    public static ?RefundResult $refundAnswer = null;

    public static bool $canRefund = true;

    public static function reset(): void
    {
        self::$answers = [];
        self::$sessions = 0;
        self::$queries = 0;
        self::$failSession = false;
        self::$url = 'https://pay.fake.test/authorize';
        self::$context = null;
        self::$refundAnswer = null;
        self::$canRefund = true;
    }

    public function key(): string
    {
        return 'fakepay';
    }

    public function label(): string
    {
        return 'FakePay';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(query: true, notifications: false, refund: self::$canRefund, partialRefund: false);
    }

    public function currencies(): array
    {
        return ['DOP', 'COP'];
    }

    public function hosts(): array
    {
        return ['pay.fake.test'];
    }

    public function countries(): array
    {
        return ['DO', 'CO'];
    }

    public function environment(Integration $integration): string
    {
        return ($integration->config['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    public function configurationProblems(Integration $integration): array
    {
        return empty($integration->config['merchant']) ? ['Falta el comercio.'] : [];
    }

    public function providerReference(string $reference): string
    {
        return substr($reference, 0, 20);
    }

    public function createSession(PaymentTransaction $transaction, Integration $integration, CheckoutContext $context): GatewayCheckout
    {
        self::$sessions++;
        self::$context = $context;

        if (self::$failSession) {
            throw new RuntimeException('provider says: secret-token-should-never-leak');
        }

        return new GatewayCheckout('form_post', self::$url, ['SESSION' => 'sess-'.$transaction->provider_reference], ['session' => 'sess-'.$transaction->provider_reference, 'key' => 'key-'.$transaction->provider_reference]);
    }

    public function resume(PaymentTransaction $transaction, Integration $integration): GatewayCheckout
    {
        return new GatewayCheckout('form_post', 'https://pay.fake.test/authorize', ['SESSION' => $transaction->secrets['session']]);
    }

    public function query(PaymentTransaction $transaction, Integration $integration): GatewayResult
    {
        self::$queries++;
        $answer = self::$answers[$transaction->provider_reference] ?? new GatewayResult(GatewayOutcome::Pending);

        return is_callable($answer) ? $answer($transaction) : $answer;
    }

    public function refund(PaymentTransaction $transaction, Integration $integration, int $amountMinor): RefundResult
    {
        return self::$refundAnswer ?? new RefundResult(true, 'void-'.$transaction->external_id);
    }

    /** What a provider would report for a paid transaction, built from the transaction itself. */
    public static function approved(PaymentTransaction $transaction, array $override = []): GatewayResult
    {
        return new GatewayResult(
            GatewayOutcome::Approved, $override['amount'] ?? $transaction->amount_minor, $override['currency'] ?? $transaction->currency,
            $override['reference'] ?? $transaction->provider_reference, $override['external'] ?? 'EXT-'.$transaction->id, 'AUTH1', '00',
        );
    }
}
