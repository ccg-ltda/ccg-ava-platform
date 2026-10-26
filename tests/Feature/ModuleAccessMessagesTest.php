<?php

namespace Tests\Feature;

use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The three situations are told apart: the module is not in the plan of the Workspace, the module is there but the role lacks the permission, and the user is
 * authorized. A rejected request says which one it is and never leaks another Workspace; Configuraciones opens for every Workspace that has it.
 */
class ModuleAccessMessagesTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->client = $this->bookingWorkspace('CLIENTE', ['dashboard', 'customers', 'settings']);
    }

    public function test_a_module_missing_from_the_plan_says_so_in_the_page_and_in_json(): void
    {
        $this->actAsMember($this->client);

        $this->get('/orders')->assertForbidden()->assertSee('Este módulo no está incluido en el plan de tu Workspace.', false);
        $this->getJson('/orders')->assertForbidden()->assertJsonPath('message', 'Este módulo no está incluido en el plan de tu Workspace.');
        $this->post('/orders', [])->assertForbidden();
    }

    public function test_a_missing_permission_in_an_available_module_is_not_reported_as_a_missing_module(): void
    {
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-dashboard']);
        $this->actAsMember($this->client, 'lector');

        $response = $this->get('/customers')->assertForbidden();
        $response->assertSee('Tu rol no tiene permiso para esta acción.', false);
        $response->assertDontSee('no está incluido en el plan', false);
    }

    public function test_an_authorized_user_of_an_available_module_gets_the_page(): void
    {
        $this->actAsMember($this->client);

        $this->get('/customers')->assertOk();
        $this->get('/settings')->assertOk();
    }

    public function test_the_error_never_names_another_workspace(): void
    {
        $other = $this->bookingWorkspace('SECRETO', ['dashboard', 'orders', 'product_catalog']);
        $this->actAsMember($this->client);

        $body = $this->get('/orders')->assertForbidden()->getContent();

        $this->assertStringNotContainsString('SECRETO', $body);
        $this->assertStringNotContainsString($other->name, $body);
    }

    public function test_the_sidebar_props_list_exactly_the_modules_the_server_allows(): void
    {
        $this->actAsMember($this->client);
        $allowed = app(ModuleAccess::class)->keys($this->client->fresh());

        $this->get('/customers')->assertInertia(fn ($page) => $page->where('modules', fn ($modules) => collect($modules)->sort()->values()->all() === collect($allowed)->sort()->values()->all()));
        $this->assertNotContains('orders', $allowed);
        $this->get('/orders')->assertForbidden();
    }

    public function test_the_administrative_workspace_keeps_every_implemented_module(): void
    {
        $dev = Workspace::create(['organization_id' => $this->client->organization_id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $keys = app(ModuleAccess::class)->keys($dev);

        foreach (array_keys(config('modules.implemented')) as $module) {
            $this->assertContains($module, $keys);
        }
    }

    /** Configuraciones opens with different data: no saved row, a saved row, another currency; a reader and an editor; never the platform email template. */
    public function test_settings_open_for_client_workspaces_with_different_data_and_roles(): void
    {
        $noRow = $this->bookingWorkspace('SIN_FILA', ['dashboard', 'settings']);
        $noRow->settings()->delete();
        $custom = $this->bookingWorkspace('OTRA_MONEDA', ['dashboard', 'settings']);
        $custom->settings()->update(['currency' => 'USD', 'timezone' => 'America/New_York', 'tax_country' => 'US', 'tax_enabled' => false]);
        Role::findOrCreate('lector_config', 'web')->syncPermissions(['view-settings']);

        foreach ([[$noRow, 'admin', true], [$custom, 'admin', true], [$custom, 'lector_config', false]] as [$workspace, $role, $canEdit]) {
            $this->actAsMember($workspace, $role);

            $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('canEdit', $canEdit)
                ->where('emailTemplate', null)
                ->has('settings.currency')->has('catalog.currencies'));
        }
    }

    public function test_settings_data_comes_only_from_the_active_workspace(): void
    {
        $mine = $this->bookingWorkspace('MIA', ['dashboard', 'settings']);
        $mine->update(['name' => 'Mi negocio']);
        $theirs = $this->bookingWorkspace('SUYA', ['dashboard', 'settings']);
        $theirs->update(['name' => 'Negocio ajeno']);
        $theirs->settings()->update(['description' => 'secreto del otro']);
        $this->actAsMember($mine);

        $this->get('/settings?workspace='.$theirs->id)->assertOk()->assertInertia(fn ($page) => $page->where('settings.name', 'Mi negocio')->where('settings.description', ''));
    }
}
