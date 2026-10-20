<?php

namespace Tests\Feature;

use App\Customers\CustomerRegistry;
use App\Exceptions\DemoNotAllowed;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Services\DemoBusinessData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The demonstration data of the operational modules: coherent, marked as demo, idempotent, removable and confined to the administrative Workspace. */
class DemoBusinessDataTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $admin;

    private Workspace $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $organization = Organization::create(['name' => 'CCG']);
        $this->admin = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE', 'name' => 'Cliente']);
        foreach ([$this->admin, $this->client] as $workspace) {
            $workspace->settings()->create(WorkspaceSetting::defaults());
        }
        // No test may reach a provider.
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function demo(): DemoBusinessData
    {
        return app(DemoBusinessData::class);
    }

    public function test_generating_creates_a_coherent_set_for_every_module_with_variety_and_volume(): void
    {
        $counts = $this->demo()->generate($this->admin);

        $this->assertSame(10, $counts['customers']);
        $this->assertSame(10, $counts['orders']);
        $this->assertSame(10, $counts['products']);
        $this->assertSame(10, $counts['services']);
        $this->assertSame(5, $counts['locations']);
        $this->assertSame(10, $counts['professionals']);
        $this->assertSame(10, $counts['resources']);
        $this->assertSame(3, $counts['exceptions']);
        $this->assertSame(10, $counts['appointments']);
        $this->assertSame(3, $counts['templates']);
        $this->assertSame(1, $counts['campaigns']);

        // Enough variety to try filters, pagination and every state.
        $this->assertEqualsCanonicalizing(['received', 'confirmed', 'preparing', 'ready', 'dispatched', 'delivered', 'cancelled'], $this->admin->orders()->distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['pickup', 'delivery'], $this->admin->orders()->distinct()->pluck('delivery_method')->all());
        $this->assertEqualsCanonicalizing(['requested', 'confirmed', 'completed', 'no_show', 'cancelled'], $this->admin->appointments()->distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['simulated'], $this->admin->campaigns()->distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing([true, false], $this->admin->customers()->distinct()->pluck('is_active')->map(fn ($v) => (bool) $v)->all());
        $this->assertEqualsCanonicalizing(['granted', 'unknown', 'revoked'], $this->admin->customers()->distinct()->pluck('marketing_consent')->all());
        $this->assertEqualsCanonicalizing([true, false], $this->admin->products()->distinct()->pluck('is_active')->map(fn ($v) => (bool) $v)->all());
        $this->assertEqualsCanonicalizing([true, false], $this->admin->services()->distinct()->pluck('is_active')->map(fn ($v) => (bool) $v)->all());
        $this->assertTrue($this->admin->appointments()->where('starts_at', '<', now())->exists() && $this->admin->appointments()->where('starts_at', '>', now())->exists());
    }

    public function test_the_records_are_related_to_each_other_inside_the_workspace(): void
    {
        $this->demo()->generate($this->admin);

        // Orders: the total is the sum of their lines, every line is a product of the Workspace, the customer is a real record of it.
        foreach ($this->admin->orders()->with('lines')->get() as $order) {
            $this->assertEqualsWithDelta((float) $order->total, (float) $order->lines->sum('line_total'), 0.001, "order #{$order->number} total");
            $this->assertSame(0, $order->lines->filter(fn ($line) => $line->product_id && ! $this->admin->products()->whereKey($line->product_id)->exists())->count());
            $this->assertTrue($this->admin->customers()->whereKey($order->customer_id)->exists());
            $this->assertNotEmpty($order->statusChanges ?? $order->lines);
        }

        // Appointments: service, professional, sede and customer all belong to the Workspace, and the price is the one the service had.
        foreach ($this->admin->appointments()->get() as $appointment) {
            $this->assertTrue($this->admin->services()->whereKey($appointment->service_id)->exists());
            $this->assertTrue($this->admin->professionals()->whereKey($appointment->professional_id)->exists());
            $this->assertTrue($this->admin->locations()->whereKey($appointment->location_id)->exists());
            $this->assertTrue($this->admin->bookingCustomers()->whereKey($appointment->customer_id)->exists());
            $this->assertTrue($appointment->ends_at->gt($appointment->starts_at));
            $this->assertTrue($appointment->blocks_until->gte($appointment->ends_at));
            if ($appointment->status === Appointment::CANCELLED) {
                $this->assertNotNull($appointment->cancel_reason);
            }
        }

        // No professional is booked twice at the same time.
        $overlaps = $this->admin->appointments()->whereIn('status', ['requested', 'confirmed'])->get()->groupBy('professional_id')
            ->filter(fn ($rows) => $rows->sortBy('starts_at')->values()->contains(fn ($a, $i) => isset($rows->sortBy('starts_at')->values()[$i + 1]) && $rows->sortBy('starts_at')->values()[$i + 1]->starts_at->lt($a->blocks_until)));
        $this->assertCount(0, $overlaps);

        // Campaigns: a prepared or simulated one has its recipients, taken from the customers who authorised.
        $simulated = $this->admin->campaigns()->where('status', 'simulated')->firstOrFail();
        $this->assertGreaterThan(0, $simulated->recipients()->count());
    }

    public function test_everything_is_marked_as_fictitious_and_no_real_contact_data_is_used(): void
    {
        $this->demo()->generate($this->admin);

        foreach ($this->admin->customers()->get() as $customer) {
            $this->assertStringEndsWith('@demo.invalid', $customer->email);
            $this->assertStringStartsWith('+1202555', (string) $customer->phone_e164 ?: '+1202555');
            $this->assertStringContainsString('demo', mb_strtolower($customer->last_name));
        }
        foreach ($this->admin->products()->get() as $product) {
            $this->assertStringStartsWith('DEMO-', $product->sku);
            $this->assertStringStartsWith('Demo · ', $product->name);
        }
        foreach ([$this->admin->services(), $this->admin->professionals(), $this->admin->locations(), $this->admin->messageTemplates(), $this->admin->campaigns()] as $relation) {
            $this->assertSame(0, $relation->where('name', 'not like', 'Demo · %')->count());
        }
        Http::assertNothingSent();
    }

    public function test_generating_again_changes_nothing(): void
    {
        $first = $this->demo()->generate($this->admin);
        $snapshot = [Customer::count(), Order::count(), Appointment::count()];

        $second = $this->demo()->generate($this->admin);

        $this->assertSame($first, $second);
        $this->assertSame($snapshot, [Customer::count(), Order::count(), Appointment::count()]);
    }

    public function test_the_targets_are_the_total_of_each_module_so_records_people_created_count_and_stay_untouched(): void
    {
        $service = $this->admin->services()->create(['name' => 'Pelo', 'duration_minutes' => 30, 'price' => 20000, 'is_active' => true]);
        $professional = $this->admin->professionals()->create(['name' => 'Pedro', 'is_active' => true]);
        $product = $this->admin->products()->create(['sku' => '123', 'name' => 'Carne', 'price' => 12000, 'is_active' => true]);
        $customer = app(CustomerRegistry::class)->save($this->admin, ['first_name' => 'Real', 'phone' => '+573001112233', 'email' => 'real@example.com', 'source' => 'manual', 'marketing_consent' => 'unknown', 'is_active' => true], [], []);

        $this->demo()->generate($this->admin);

        foreach (['customers', 'products', 'services', 'professionals', 'orders', 'appointments', 'locations', 'bookableResources', 'scheduleExceptions', 'messageTemplates', 'campaigns'] as $relation) {
            $target = ['bookableResources' => 'resources', 'scheduleExceptions' => 'exceptions', 'messageTemplates' => 'templates'][$relation] ?? $relation;
            $this->assertSame(DemoBusinessData::TARGETS[$target], $this->admin->{$relation}()->count(), "total of {$relation}");
        }

        // What a person created is still there, unchanged, and is not marked as demo.
        $this->assertSame('Pelo', $service->fresh()->name);
        $this->assertSame('Pedro', $professional->fresh()->name);
        $this->assertSame('Carne', $product->fresh()->name);
        $this->assertSame('real@example.com', $customer->fresh()->email);

        // A second run creates nothing.
        $rows = fn () => collect(['customers', 'products', 'services', 'professionals', 'orders', 'appointments', 'locations', 'bookableResources', 'scheduleExceptions', 'messageTemplates', 'campaigns'])->map(fn ($r) => $this->admin->{$r}()->count())->all();
        $before = $rows();
        $this->demo()->generate($this->admin);
        $this->assertSame($before, $rows());
        $this->assertSame(0, DB::table('audit_logs')->count(), 'the generator writes no audit records');
    }

    public function test_cleaning_removes_only_what_carries_the_demo_marks(): void
    {
        $this->demo()->generate($this->admin);
        $realCustomer = $this->admin->customers()->create(['first_name' => 'Persona', 'last_name' => 'Real', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);
        $realProduct = $this->admin->products()->create(['sku' => 'REAL-1', 'name' => 'Producto real', 'price' => 1000]);
        $realService = $this->admin->services()->create(['name' => 'Servicio real', 'duration_minutes' => 30]);

        $removed = $this->demo()->clean($this->admin);

        $this->assertSame(10, $removed['customers']);
        $counts = $this->demo()->counts($this->admin);
        $this->assertSame(array_fill_keys(array_keys($counts), 0), $counts);
        $this->assertTrue(Customer::whereKey($realCustomer->id)->exists());
        $this->assertTrue($realProduct->fresh()->exists);
        $this->assertTrue($realService->fresh()->exists);
        $this->assertSame(0, $this->admin->orders()->count() + $this->admin->appointments()->count() + $this->admin->campaigns()->count());
    }

    public function test_it_can_be_generated_again_after_cleaning(): void
    {
        $this->demo()->generate($this->admin);
        $this->demo()->clean($this->admin);

        $this->assertSame(10, $this->demo()->generate($this->admin)['customers']);
    }

    public function test_it_only_runs_in_the_administrative_workspace_and_never_in_production(): void
    {
        $this->expectException(DemoNotAllowed::class);
        $this->demo()->generate($this->client);
    }

    public function test_a_client_workspace_never_receives_or_sees_demo_data(): void
    {
        $this->demo()->generate($this->admin);

        $this->assertSame(array_fill_keys(array_keys($this->demo()->counts($this->client)), 0), $this->demo()->counts($this->client));
        $this->assertSame(0, $this->client->customers()->count() + $this->client->orders()->count() + $this->client->appointments()->count() + $this->client->products()->count());
    }

    public function test_production_refuses_to_generate_or_clean(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        foreach (['generate', 'clean'] as $action) {
            try {
                $this->demo()->{$action}($this->admin);
                $this->fail("{$action} must refuse in production");
            } catch (DemoNotAllowed) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Customer::count());
    }

    public function test_the_command_runs_with_the_same_guards_and_prints_what_it_did(): void
    {
        $this->artisan('demo:data generate')->expectsOutputToContain('Datos demo en DESARROLLO_DEV')->assertSuccessful();
        $this->assertSame(10, Customer::where('workspace_id', $this->admin->id)->count());

        $this->artisan('demo:data status')->assertSuccessful();
        $this->artisan('demo:data', ['action' => 'clean', '--confirm' => 'DESARROLLO_DEV'])->assertSuccessful();
        $this->assertSame(0, Customer::count());
        $this->artisan('demo:data nonsense')->assertExitCode(2);

        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('demo:data generate')->expectsOutputToContain('solo existen en entornos locales')->assertFailed();
    }

    public function test_cleaning_needs_the_workspace_code_typed_and_without_it_nothing_is_removed(): void
    {
        $this->artisan('demo:data generate')->assertSuccessful();
        $before = $this->demo()->counts($this->admin);

        $this->artisan('demo:data', ['action' => 'clean'])->expectsOutputToContain('--confirm=DESARROLLO_DEV')->assertFailed();
        $this->artisan('demo:data', ['action' => 'clean', '--confirm' => 'OTRO_WORKSPACE'])->assertFailed();
        $this->artisan('demo:data', ['action' => 'clean', '--confirm' => ''])->assertFailed();
        $this->assertSame($before, $this->demo()->counts($this->admin), 'a clean that was not confirmed removes nothing');

        $this->artisan('demo:data', ['action' => 'clean', '--confirm' => 'desarrollo_dev'])->assertSuccessful();
        $this->assertSame(0, array_sum($this->demo()->counts($this->admin)));
    }

    public function test_nothing_removes_demo_data_by_itself_a_seeder_the_scheduler_or_a_new_catalog(): void
    {
        $this->artisan('demo:data generate')->assertSuccessful();
        $before = $this->demo()->counts($this->admin);
        $this->assertGreaterThan(0, array_sum($before));

        // What a deploy or an update runs: every seeder, again.
        foreach (['db:seed', 'db:seed --class=RolesAndPermissionsSeeder', 'db:seed --class=ModuleCatalogSeeder'] as $command) {
            $this->artisan($command)->assertSuccessful();
        }

        $this->assertSame($before, $this->demo()->counts($this->admin));

        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode(' | ');
        $this->assertStringNotContainsString('demo:', $scheduled, 'no scheduled command touches demo data');
    }

    public function test_without_demo_data_the_screens_show_real_empty_states_and_no_invented_records(): void
    {
        $user = User::factory()->create();
        $this->seedRoleCatalog();
        $this->admin->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $this->admin->id]);

        foreach (['/customers' => 'customers', '/orders' => 'orders', '/catalog' => 'products', '/campaigns' => 'campaigns'] as $url => $prop) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where("{$prop}.meta.total", 0)->where("{$prop}.data", []));
        }
        $this->get('/conversations')->assertOk()->assertInertia(fn ($page) => $page->where('conversations', []));
        $this->assertSame(0, array_sum($this->demo()->counts($this->admin)));
    }

    public function test_the_demo_screens_open_and_paginate_for_an_administrator(): void
    {
        $this->demo()->generate($this->admin);
        $user = User::factory()->create();
        $this->seedRoleCatalog();
        $this->admin->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $this->admin->id]);

        foreach (['/customers', '/catalog', '/orders', '/services', '/locations', '/professionals', '/bookings', '/campaigns'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/customers?customers_per_page=10')->assertInertia(fn ($page) => $page->where('customers.meta.total', 10)->where('customers.meta.last_page', 1));
        $this->get('/orders')->assertInertia(fn ($page) => $page->where('orders.meta.total', 10));
        $order = $this->admin->orders()->first();
        $this->get("/orders/{$order->id}")->assertOk();
        $customer = $this->admin->customers()->first();
        $this->get("/customers/{$customer->id}")->assertOk();
        $campaign = $this->admin->campaigns()->first();
        $this->get("/campaigns/{$campaign->id}")->assertOk();
    }
}
