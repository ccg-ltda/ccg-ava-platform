<?php

namespace Tests\Feature;

use App\Models\PaymentProviderSetting;
use App\Payments\WompiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What Wompi's configuration resolves to before any request: the host of each environment, the key prefix that is accepted, the signature of the checkout and
 * which source wins. Pure configuration: nothing here talks to Wompi and every key is fictitious.
 */
class WompiConfigTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $environment, array $values = [], bool $active = true): PaymentProviderSetting
    {
        $prefix = $environment === 'production' ? 'pub_prod_' : 'pub_test_';

        return PaymentProviderSetting::create(['provider' => 'wompi', 'environment' => $environment, 'is_active' => $active, 'public_key' => $prefix.'db',
            'events_secret' => 'db-events-secret', 'integrity_secret' => 'db-integrity-secret', ...$values]);
    }

    private function config(): WompiConfig
    {
        return new WompiConfig;
    }

    public function test_each_environment_has_its_own_host_and_event_marker(): void
    {
        $this->row('sandbox');
        $this->assertSame(['sandbox', 'https://sandbox.wompi.co/v1', 'test'], [$this->config()->environment(), $this->config()->apiUrl(), $this->config()->eventEnvironment()]);

        PaymentProviderSetting::query()->delete();
        $this->row('production');
        $this->assertSame(['production', 'https://production.wompi.co/v1', 'prod'], [$this->config()->environment(), $this->config()->apiUrl(), $this->config()->eventEnvironment()]);
    }

    public function test_a_key_of_the_other_environment_or_a_missing_secret_makes_it_unusable_never_a_silent_attempt(): void
    {
        $this->row('production', ['public_key' => 'pub_test_wrong_environment']);
        $this->assertFalse($this->config()->usable(), 'A sandbox key in production.');

        PaymentProviderSetting::query()->delete();
        $this->row('sandbox', ['public_key' => 'pub_prod_wrong_environment']);
        $this->assertFalse($this->config()->usable(), 'A production key in sandbox.');

        foreach (['events_secret', 'integrity_secret', 'public_key'] as $missing) {
            PaymentProviderSetting::query()->delete();
            $this->row('sandbox', [$missing => null]);
            $this->assertFalse($this->config()->usable(), "Without {$missing}.");
        }

        PaymentProviderSetting::query()->delete();
        $this->row('sandbox');
        $this->assertTrue($this->config()->usable());
    }

    public function test_the_integrity_signature_is_sha256_of_reference_amount_currency_and_the_secret(): void
    {
        $this->row('sandbox', ['integrity_secret' => 'test_integrity_fake']);

        // Wompi's documented concatenation: reference + amount in cents + currency + integrity secret.
        $this->assertSame(hash('sha256', 'SIGNUP-REF-1'.'4990000'.'COP'.'test_integrity_fake'), $this->config()->integritySignature('SIGNUP-REF-1', 4990000, 'COP'));
        $this->assertNotSame($this->config()->integritySignature('SIGNUP-REF-1', 4990000, 'COP'), $this->config()->integritySignature('SIGNUP-REF-1', 4990001, 'COP'));
        $this->assertNotSame($this->config()->integritySignature('SIGNUP-REF-1', 4990000, 'COP'), $this->config()->integritySignature('SIGNUP-REF-2', 4990000, 'COP'));
    }

    public function test_the_active_row_wins_over_the_variables_and_the_two_sources_are_never_mixed(): void
    {
        config(['wompi.environment' => 'production', 'wompi.public_key' => 'pub_prod_env', 'wompi.events_secret' => 'env-events', 'wompi.integrity_secret' => 'env-integrity']);

        // No active row: the variables (compatibility), as a whole.
        $env = $this->config();
        $this->assertSame(['environment', 'production', 'env-integrity'], [$env->source(), $env->environment(), $env->integritySecret()]);

        // An INACTIVE row never applies.
        $this->row('sandbox', [], active: false);
        $this->assertSame('environment', $this->config()->source());

        // An active row replaces the variables completely, even a field it lacks (it is "not usable", it does not borrow the variable's value).
        PaymentProviderSetting::query()->delete();
        $this->row('sandbox', ['integrity_secret' => null]);
        $db = $this->config();
        $this->assertSame(['database', 'sandbox', ''], [$db->source(), $db->environment(), $db->integritySecret()]);
        $this->assertFalse($db->usable());
    }

    public function test_the_checkout_and_the_api_never_use_a_host_from_a_request(): void
    {
        $this->row('sandbox');

        $this->assertSame('https://checkout.wompi.co/p/', $this->config()->checkoutBase());
        $this->assertStringStartsWith('https://sandbox.wompi.co/', $this->config()->apiUrl());
    }
}
