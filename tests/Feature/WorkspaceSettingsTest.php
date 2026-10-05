<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Rules\ReadableBrandColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Configuraciones: preferences of the active Workspace. */
class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function otherWorkspace(): Workspace
    {
        return Workspace::create(['organization_id' => Organization::firstOrFail()->id, 'code' => 'OTHER_WS', 'name' => 'Other']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mi negocio',
            'description' => 'Una descripción',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'date_format' => 'ymd',
            'time_format' => '24h',
            'appearance' => 'dark',
            'primary_color' => '#0f766e',
            'tax_country' => 'CO',
            'tax_enabled' => true,
            'tax_name' => 'IVA',
            'tax_rate' => 19,
        ], $overrides);
    }

    // --- reading ---------------------------------------------------------------------------------------------

    public function test_a_workspace_that_never_saved_uses_the_defaults(): void
    {
        $this->actAs();

        $this->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Index')
            ->where('settings.name', 'Test Workspace')
            ->where('settings.currency', 'DOP')
            ->where('settings.timezone', 'America/Santo_Domingo')
            ->where('settings.date_format', 'dmy')
            ->where('settings.time_format', '12h')
            ->where('settings.appearance', 'light')
            ->where('settings.primary_color', '#1d4ed8')
            ->where('settings.tax_country', 'DO')
            ->where('settings.tax_name', 'ITBIS')
            ->where('settings.tax_rate', 18)
            ->where('settings.logo_url', null));
    }

    public function test_the_catalog_offers_the_supported_options_and_country_defaults(): void
    {
        $this->actAs();

        $this->get('/settings')->assertInertia(function (AssertableInertia $page) {
            $catalog = $page->toArray()['props']['catalog'];
            $countries = collect($catalog['taxCountries'])->keyBy('code');

            $this->assertEqualsCanonicalizing(['COP', 'DOP', 'USD', 'EUR'], collect($catalog['currencies'])->pluck('value')->all());
            $this->assertSame(['DOP', 'ITBIS', 18], [$countries['DO']['currency'], $countries['DO']['tax']['name'], $countries['DO']['tax']['rate']]);
            $this->assertSame(['COP', 'IVA', 19], [$countries['CO']['currency'], $countries['CO']['tax']['name'], $countries['CO']['tax']['rate']]);
            $this->assertSame(['USD', 'Sales Tax'], [$countries['US']['currency'], $countries['US']['tax']['name']]);
            $this->assertSame(['EUR', 'IVA', 21], [$countries['ES']['currency'], $countries['ES']['tax']['name'], $countries['ES']['tax']['rate']]);
            $this->assertNotEmpty($countries['CO']['authority']);
            $this->assertNotEmpty($countries['US']['note']);
        });
    }

    // --- saving ----------------------------------------------------------------------------------------------

    public function test_an_admin_saves_every_preference_and_they_persist(): void
    {
        $this->actAs();

        $this->post('/settings', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Configuración guardada correctamente');

        $workspace = Workspace::first();
        $this->assertSame('Mi negocio', $workspace->name);

        $settings = $workspace->settings;
        $this->assertSame('Una descripción', $settings->description);
        $this->assertSame(['COP', 'America/Bogota', 'ymd', '24h', 'dark', '#0f766e'], [
            $settings->currency, $settings->timezone, $settings->date_format, $settings->time_format, $settings->appearance, $settings->primary_color,
        ]);
        $this->assertSame(['CO', true, 'IVA', 19.0], [$settings->tax_country, $settings->tax_enabled, $settings->tax_name, $settings->tax_rate]);

        // Saving again updates the same row.
        $this->post('/settings', $this->payload(['currency' => 'USD', 'tax_enabled' => false]))->assertSessionHasNoErrors();
        $this->assertSame(1, WorkspaceSetting::count());
        $this->assertSame('USD', $workspace->fresh()->settings->currency);
        $this->assertFalse($workspace->fresh()->settings->tax_enabled);

        $this->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page->where('settings.currency', 'USD')->where('settings.timezone', 'America/Bogota'));
    }

    public function test_a_blank_description_is_stored_as_null(): void
    {
        $this->actAs();

        $this->post('/settings', $this->payload(['description' => '']))->assertSessionHasNoErrors();

        $this->assertNull(Workspace::first()->settings->description);
    }

    public function test_settings_belong_to_the_workspace_and_cannot_reach_another(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $other->settings()->create(['currency' => 'EUR', 'tax_country' => 'ES', 'tax_name' => 'IVA', 'tax_rate' => 21]);

        // The client cannot choose the Workspace, whatever it sends.
        $this->post('/settings', $this->payload(['workspace_id' => $other->id, 'workspace' => 'OTHER_WS', 'id' => $other->id]))->assertSessionHasNoErrors();

        $this->assertSame('Mi negocio', Workspace::where('code', 'TEST_WS')->first()->name);
        $this->assertSame('Other', $other->fresh()->name);
        $this->assertSame('EUR', $other->fresh()->settings->currency);
        $this->assertSame('COP', Workspace::where('code', 'TEST_WS')->first()->settings->currency);
    }

    public function test_the_shared_workspace_prop_carries_the_preferences(): void
    {
        $this->actAs();
        $this->post('/settings', $this->payload());

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspace.settings.appearance', 'dark')
            ->where('workspace.settings.primaryColor', '#0f766e')
            ->where('workspace.settings.currency', 'COP')
            ->where('workspace.settings.tax.name', 'IVA'));
    }

    public function test_dates_follow_the_workspace_format_and_timezone(): void
    {
        $admin = $this->actAs();
        $workspace = Workspace::first();
        $admin->forceFill(['created_at' => '2026-03-05 02:30:00'])->save(); // UTC

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page->where('users.data.0.created_at', '04/03/2026')); // Santo Domingo (UTC-4), dmy

        $this->post('/settings', $this->payload(['timezone' => 'UTC', 'date_format' => 'mdy']))->assertSessionHasNoErrors();
        $this->assertSame('03/05/2026', $workspace->fresh()->settingsOrDefault()->formatDate($admin->fresh()->created_at));
        $this->assertSame('02:30', $workspace->fresh()->settingsOrDefault()->formatTime($admin->fresh()->created_at));
    }

    // --- permissions -----------------------------------------------------------------------------------------

    public function test_only_roles_with_manage_settings_can_read_or_save(): void
    {
        foreach (['supervisor', 'cliente'] as $role) {
            $this->actAs($role);

            $this->get('/settings')->assertForbidden();
            $this->post('/settings', $this->payload())->assertForbidden();
        }

        $this->assertSame('Test Workspace', Workspace::first()->name);
        $this->assertSame(0, WorkspaceSetting::count());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/settings')->assertRedirect('/login');
        $this->post('/settings', $this->payload())->assertRedirect('/login');
    }

    public function test_a_custom_role_with_manage_settings_can_use_it(): void
    {
        $this->seedRoleCatalog();
        Role::findOrCreate('configurador', 'web')->syncPermissions(['manage-settings', 'view-dashboard']);
        $this->actAs('configurador');

        $this->get('/settings')->assertOk();
        $this->post('/settings', $this->payload())->assertSessionHasNoErrors();
    }

    // --- validation ------------------------------------------------------------------------------------------

    public function test_invalid_values_are_rejected_and_nothing_is_saved(): void
    {
        $this->actAs();

        $bad = [
            ['name' => ''],
            ['name' => str_repeat('a', 256)],
            ['description' => str_repeat('a', 501)],
            ['currency' => 'MXN'],
            ['timezone' => 'Mars/Olympus'],
            ['date_format' => 'xyz'],
            ['time_format' => '48h'],
            ['appearance' => 'sepia'],
            ['primary_color' => 'blue'],
            ['primary_color' => '#ffffff'],
            ['primary_color' => '#ffff00'],
            ['tax_country' => 'FR'],
            ['tax_name' => ''],
            ['tax_rate' => -1],
            ['tax_rate' => 101],
            ['tax_rate' => 19.555],
            ['tax_rate' => 'abc'],
            ['tax_enabled' => 'maybe'],
        ];

        foreach ($bad as $override) {
            $this->post('/settings', $this->payload($override))->assertSessionHasErrors(array_key_first($override));
        }

        $this->assertSame(0, WorkspaceSetting::count());
        $this->assertSame('Test Workspace', Workspace::first()->name);
    }

    public function test_the_brand_color_rule_requires_readable_white_text(): void
    {
        $this->assertGreaterThan(4.5, ReadableBrandColor::contrastWithWhite('#1d4ed8'));
        $this->assertEqualsWithDelta(1.0, ReadableBrandColor::contrastWithWhite('#ffffff'), 0.001);
        $this->assertLessThan(4.5, ReadableBrandColor::contrastWithWhite('#f59e0b'));
    }

    public function test_saving_never_touches_the_internal_code_or_membership(): void
    {
        $admin = $this->actAs();
        $workspace = Workspace::first();

        $this->post('/settings', $this->payload(['code' => 'HACKED', 'is_active' => false, 'organization_id' => 99]))->assertSessionHasNoErrors();

        $fresh = $workspace->fresh();
        $this->assertSame('TEST_WS', $fresh->code);
        $this->assertTrue($fresh->is_active);
        $this->assertSame($workspace->organization_id, $fresh->organization_id);
        $this->assertTrue($fresh->users()->whereKey($admin->id)->exists());
    }

    public function test_guests_cannot_fetch_a_logo_and_a_failed_save_leaves_no_file_behind(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->actAs();
        $this->post('/settings', $this->payload(['logo' => UploadedFile::fake()->image('logo.png')]));
        $path = Workspace::first()->settings->logo_path;

        // A rejected request must not write anything to storage.
        $this->post('/settings', $this->payload(['tax_rate' => 500, 'logo' => UploadedFile::fake()->image('otro.png')]))->assertSessionHasErrors('tax_rate');
        $this->assertCount(1, Storage::allFiles('workspaces'));
        Storage::assertExists($path);

        auth()->logout();
        $this->flushSession();
        $this->get('/workspace/logo')->assertRedirect('/login');
    }

    // --- logo ------------------------------------------------------------------------------------------------

    public function test_the_logo_is_stored_per_workspace_served_to_members_and_replaced_cleanly(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->actAs();
        $workspace = Workspace::first();

        $this->post('/settings', $this->payload(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)]))->assertSessionHasNoErrors();

        $first = $workspace->fresh()->settings->logo_path;
        $this->assertStringStartsWith("workspaces/{$workspace->id}/logo/", $first);
        Storage::assertExists($first);
        $this->get('/workspace/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-cache, private');
        $etag = $this->get('/workspace/logo')->headers->get('ETag');
        $this->withHeaders(['If-None-Match' => $etag])->get('/workspace/logo')->assertStatus(304);
        $this->withHeaders(['If-None-Match' => '"someone-elses-logo"'])->get('/workspace/logo')->assertOk();
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('workspace.settings.logoUrl', fn ($url) => str_contains((string) $url, '/workspace/logo')));

        $this->post('/settings', $this->payload(['logo' => UploadedFile::fake()->image('nuevo.jpg', 100, 100)]))->assertSessionHasNoErrors();
        $second = $workspace->fresh()->settings->logo_path;
        $this->assertNotSame($first, $second);
        Storage::assertMissing($first);
        Storage::assertExists($second);

        $this->post('/settings', $this->payload(['remove_logo' => true]))->assertSessionHasNoErrors();
        $this->assertNull($workspace->fresh()->settings->logo_path);
        Storage::assertMissing($second);
        $this->get('/workspace/logo')->assertNotFound();
    }

    public function test_a_workspace_never_receives_another_workspaces_logo(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->actAs();
        $this->post('/settings', $this->payload(['logo' => UploadedFile::fake()->image('logo.png')]));

        $other = $this->otherWorkspace();
        $member = User::factory()->create();
        $other->users()->attach($member->id, ['role' => 'cliente']);
        $this->actingAs($member)->withSession(['workspace_id' => $other->id]);

        $this->get('/workspace/logo')->assertNotFound();
    }

    public function test_invalid_logos_are_rejected(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->actAs();

        foreach ([
            UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->image('grande.png')->size(2048),
        ] as $file) {
            $this->post('/settings', $this->payload(['logo' => $file]))->assertSessionHasErrors('logo');
        }

        $this->assertSame(0, WorkspaceSetting::count());
    }
}
