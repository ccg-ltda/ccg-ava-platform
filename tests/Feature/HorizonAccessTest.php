<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Horizon outside local: nobody by default, only active accounts listed in HORIZON_ALLOWED_EMAILS. */
class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_nobody_enters_with_the_default_empty_list(): void
    {
        config(['horizon.allowed_emails' => []]);

        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    public function test_only_a_listed_active_account_enters_whatever_the_case(): void
    {
        config(['horizon.allowed_emails' => ['ops@example.com']]);

        $this->assertTrue(Gate::forUser(User::factory()->create(['email' => 'OPS@example.com']))->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(User::factory()->create(['email' => 'other@example.com']))->allows('viewHorizon'));
    }

    public function test_a_listed_account_that_was_deactivated_does_not_enter(): void
    {
        config(['horizon.allowed_emails' => ['ops@example.com']]);

        $this->assertFalse(Gate::forUser(User::factory()->create(['email' => 'ops@example.com', 'is_active' => false]))->allows('viewHorizon'));
    }
}
