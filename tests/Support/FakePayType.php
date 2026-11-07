<?php

namespace Tests\Support;

use App\Integrations\IntegrationType;
use App\Integrations\TestResult;
use App\Models\Integration;

/** The integration type of the FakePay test double: it exists only so audited Integration rows of that type can be written in tests. */
class FakePayType implements IntegrationType
{
    public function key(): string
    {
        return 'fakepay';
    }

    public function label(): string
    {
        return 'FakePay';
    }

    public function rules(array $input, ?Integration $current): array
    {
        return [];
    }

    public function build(array $input, ?Integration $current): array
    {
        return ['config' => [], 'secrets' => []];
    }

    public function form(Integration $integration): array
    {
        return [];
    }

    public function summary(Integration $integration): array
    {
        return [];
    }

    public function supportsTest(): bool
    {
        return false;
    }

    public function test(Integration $integration): TestResult
    {
        throw new \LogicException('The double does not talk to anything.');
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        return [];
    }
}
