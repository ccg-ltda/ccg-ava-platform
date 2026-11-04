<?php

namespace Tests\Feature;

use App\Support\Skeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\ResponseFactory;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The first paint of a page inside the application shell is a skeleton of that page (never a blank screen), chosen by the module's URL from ONE map (config/skeleton.php) that the
 * client also receives. The access screens have no shell and no skeleton. The client side (navigation, retry, reduced motion) is covered by `npm run test:js` and the browser.
 */
class SkeletonShellTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    public function test_a_page_of_the_application_ships_with_the_skeleton_of_its_module_in_the_html(): void
    {
        $this->seedRoleCatalog();
        $ws = $this->bookingWorkspace('SKEL', ['dashboard', 'reports', 'customers', 'conversations', 'assistants', 'integrations', 'orders', 'product_catalog']);
        $this->actAsMember($ws);

        $dashboard = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('id="boot-skeleton"', $dashboard);
        $this->assertStringContainsString('h-[434px] rounded-xl lg:col-span-2', $dashboard, 'dashboard: cards and charts');
        $this->assertStringContainsString('data-boot-side', $dashboard, 'the same shell as the real page: side menu that follows the stored width');

        $this->assertStringContainsString('lg:grid-cols-[22rem_1fr]', $this->get('/conversations')->getContent(), 'conversations: list and chat');
        $this->assertStringContainsString('h-[165px] rounded-xl', $this->get('/integrations')->getContent(), 'integrations: cards');
        $orders = $this->get('/orders')->getContent();
        $this->assertStringContainsString('id="boot-skeleton"', $orders);
        $this->assertStringNotContainsString('lg:col-span-2', $orders, 'a list does not borrow the chart shape');
        $this->assertStringContainsString('h-[86px]', $orders, 'orders: the filter card');
        $this->assertStringContainsString('h-[46px]', $orders, 'orders: its tab bar');
        $this->assertStringContainsString('h-[86px]', $this->get('/customers')->getContent(), 'customers: tabs and filter card');
    }

    public function test_the_skeleton_has_an_accessible_name_a_reduced_motion_safe_style_and_a_way_out_if_the_app_never_loads(): void
    {
        $this->seedRoleCatalog();
        $this->actAsMember($this->bookingWorkspace('SKEL2', ['dashboard', 'customers']));

        $html = $this->get('/dashboard')->getContent();

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Cargando…', $html);
        $this->assertStringContainsString('}, '.config('skeleton.give_up_ms').');', $html, 'the retry message after the configured wait');
        $this->assertStringContainsString("localStorage.getItem('ava.app-theme')", $html, 'the mode of the last session is applied before the first paint');
    }

    public function test_the_access_screens_have_no_shell_and_no_skeleton(): void
    {
        $this->get('/pre-login')->assertOk()->assertDontSee('id="boot-skeleton"', false);
        $this->get('/login')->assertStatus(302);
        $this->get('/legal/terminos-y-condiciones')->assertDontSee('id="boot-skeleton"', false);
    }

    public function test_an_inertia_request_gets_the_page_data_not_the_html_shell(): void
    {
        $this->seedRoleCatalog();
        $this->actAsMember($this->bookingWorkspace('SKEL3', ['dashboard', 'customers']));

        $response = $this->get('/dashboard', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => $this->app->make(ResponseFactory::class)->getVersion()]);

        $this->assertStringNotContainsString('boot-skeleton', $response->getContent());
    }

    public function test_the_client_receives_the_same_map_and_timings(): void
    {
        $this->seedRoleCatalog();
        $this->actAsMember($this->bookingWorkspace('SKEL4', ['dashboard', 'customers']));

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('skeleton.paths', config('skeleton.paths'))->where('skeleton.default', config('skeleton.default'))
            ->where('skeleton.delay', config('skeleton.delay_ms'))->where('skeleton.giveUp', config('skeleton.give_up_ms')));
    }

    public function test_the_variant_follows_the_first_segment_and_falls_back_to_the_default(): void
    {
        $this->assertSame('dashboard', Skeleton::variantFor('dashboard'));
        $this->assertSame('reports', Skeleton::variantFor('/reports'));
        $this->assertSame('tabbed_table', Skeleton::variantFor('customers'));
        $this->assertSame('agenda', Skeleton::variantFor('bookings'));
        $this->assertSame('orders', Skeleton::variantFor('orders/15'));
        $this->assertSame('conversations', Skeleton::variantFor('conversations'));
        $this->assertSame(config('skeleton.default'), Skeleton::variantFor('a-module-that-does-not-exist'));
        $this->assertTrue(Skeleton::hasShell('Orders/Index'));
        $this->assertFalse(Skeleton::hasShell('Auth/Login'));
        $this->assertFalse(Skeleton::hasShell('Legal/Show'));
    }

    public function test_the_map_only_names_variants_that_exist_and_modules_that_are_real_routes(): void
    {
        $variants = ['dashboard', 'reports', 'table', 'tabbed_table', 'orders', 'agenda', 'cards', 'form', 'conversations'];
        $prefixes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0])->unique();

        foreach (config('skeleton.paths') as $path => $variant) {
            $this->assertContains($variant, $variants, "[{$path}] uses an unknown variant");
            $this->assertTrue($prefixes->contains($path), "[{$path}] is not the first segment of any route");
        }

        $this->assertContains(config('skeleton.default'), $variants);
        $this->assertGreaterThan(config('skeleton.delay_ms'), config('skeleton.give_up_ms'));
    }
}
