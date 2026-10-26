<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** «Canal preferido» is how the customer wants to be contacted, not the channel a conversation came from; old values are reported apart and the migration is reversible. */
class CustomerContactPreferenceTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'customers']);
        $this->actAsMember($this->workspace);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['first_name' => 'Laura', 'phone' => '+57 300 111 2233', 'source' => 'manual', 'marketing_consent' => 'unknown'], $overrides);
    }

    public function test_the_options_are_the_contact_preferences_and_never_the_conversation_channels(): void
    {
        $this->get('/customers')->assertInertia(fn ($page) => $page
            ->where('options.preferences', ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'call' => 'Llamada telefónica', 'email' => 'Correo electrónico']));
    }

    public function test_each_preference_is_accepted_and_the_field_stays_optional(): void
    {
        foreach (['whatsapp', 'sms', 'call', 'email'] as $i => $preference) {
            $this->post('/customers', $this->payload(['phone' => '+57 300 111 22'.(10 + $i), 'preferred_channel' => $preference]))->assertSessionHasNoErrors();
            $this->assertSame($preference, Customer::latest('id')->first()->preferred_channel);
        }

        $this->post('/customers', $this->payload(['phone' => '+57 300 999 0000']))->assertSessionHasNoErrors();
        $this->assertNull(Customer::latest('id')->first()->preferred_channel);
    }

    public function test_a_conversation_channel_is_not_a_valid_preference(): void
    {
        foreach (['web', 'instagram', 'messenger', 'telegram'] as $value) {
            $this->post('/customers', $this->payload(['preferred_channel' => $value]))->assertSessionHasErrors('preferred_channel');
        }

        $this->assertSame(0, Customer::count());
    }

    public function test_a_stored_old_value_is_shown_as_no_preference_with_the_original_reported_and_editing_does_not_fail(): void
    {
        $customer = $this->workspace->customers()->create(['first_name' => 'Ana', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown', 'preferred_channel' => 'instagram']);

        $this->get("/customers/{$customer->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('customer.preferredChannel', null)->where('customer.legacyPreferredChannel', 'Instagram'));

        $this->put("/customers/{$customer->id}", $this->payload(['first_name' => 'Ana María', 'phone' => '+573001112233', 'preferred_channel' => null]))->assertSessionHasNoErrors();
        $this->assertNull($customer->fresh()->preferred_channel);
    }

    public function test_the_migration_moves_only_the_old_values_keeps_them_and_down_restores_them(): void
    {
        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'customers']);
        $make = fn (Workspace $ws, string $phone, ?string $channel) => $ws->customers()->create(['first_name' => 'C', 'phone' => $phone, 'source' => 'manual', 'marketing_consent' => 'unknown', 'preferred_channel' => $channel]);
        $web = $make($this->workspace, '+573001110001', 'web');
        $messenger = $make($other, '+573001110002', 'messenger');
        $keeps = $make($this->workspace, '+573001110003', 'whatsapp');
        $email = $make($this->workspace, '+573001110004', 'email');
        $none = $make($this->workspace, '+573001110005', null);
        $before = $web->only(['first_name', 'phone', 'email', 'notes']);

        $migration = require database_path('migrations/2026_11_02_100000_separate_legacy_customer_contact_preferences.php');
        $migration->down(); // the schema of the tests already has the column (it ran with the others): go back to the state before it
        DB::table('customers')->where('id', $web->id)->update(['preferred_channel' => 'web']);
        DB::table('customers')->where('id', $messenger->id)->update(['preferred_channel' => 'messenger']);
        $migration->up();

        $this->assertNull($web->fresh()->preferred_channel);
        $this->assertSame('web', DB::table('customers')->where('id', $web->id)->value('legacy_preferred_channel'));
        $this->assertSame('messenger', DB::table('customers')->where('id', $messenger->id)->value('legacy_preferred_channel'));
        $this->assertSame(['whatsapp', 'email', null], [$keeps->fresh()->preferred_channel, $email->fresh()->preferred_channel, $none->fresh()->preferred_channel]);
        $this->assertNull(DB::table('customers')->where('id', $keeps->id)->value('legacy_preferred_channel'));
        $this->assertSame($before, $web->fresh()->only(['first_name', 'phone', 'email', 'notes']), 'nothing else of the customer changes');

        // A preference chosen after up() survives down().
        DB::table('customers')->where('id', $messenger->id)->update(['preferred_channel' => 'sms']);
        $migration->down();

        $this->assertSame('web', $web->fresh()->preferred_channel);
        $this->assertSame('sms', $messenger->fresh()->preferred_channel);
        $this->assertFalse(Schema::hasColumn('customers', 'legacy_preferred_channel'));

        $migration->up(); // leave the schema as the other tests expect it
    }
}
