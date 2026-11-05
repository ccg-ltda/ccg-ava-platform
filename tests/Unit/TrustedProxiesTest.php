<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_a_wildcard_is_never_honoured_in_production(): void
    {
        $this->assertSame(TrustedProxies::PRIVATE_RANGES, TrustedProxies::resolve('*', 'production'));
        $this->assertSame(TrustedProxies::PRIVATE_RANGES, TrustedProxies::resolve(' * ', 'production'));
    }

    #[DataProvider('nonProduction')]
    public function test_a_wildcard_still_works_outside_production(string $environment): void
    {
        $this->assertSame('*', TrustedProxies::resolve('*', $environment));
    }

    /** @return array<string, array{string}> */
    public static function nonProduction(): array
    {
        return ['local' => ['local'], 'testing' => ['testing'], 'staging' => ['staging']];
    }

    public function test_an_exact_list_is_used_as_it_is_in_every_environment_and_unset_trusts_none(): void
    {
        foreach (['production', 'local'] as $environment) {
            $this->assertSame(['203.0.113.10', '10.1.0.0/16'], TrustedProxies::resolve(' 203.0.113.10 , 10.1.0.0/16,, ', $environment));
            $this->assertSame([], TrustedProxies::resolve(null, $environment));
            $this->assertSame([], TrustedProxies::resolve('', $environment));
        }
    }

    public function test_the_private_default_matches_the_production_compose_file(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2).'/docker-compose.prod.yml');

        $this->assertStringContainsString(implode(',', TrustedProxies::PRIVATE_RANGES), $compose);
    }
}
