<?php

namespace Tests\Feature;

use App\Booking\Locations;
use App\Models\Professional;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Categories of services (a list per Workspace, never free text), «Todos los servicios» of a sede (a flag, no fictitious id) and the refusal of inactive or
 * foreign records in NEW assignments. Everything is decided by the server whatever the form sent.
 */
class ServiceCategoriesAndScopesTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations'];

    private Workspace $workspace;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
        $this->other = $this->bookingWorkspace('OTRO', self::MODULES);
        $this->actAsMember($this->workspace);
    }

    private function service(array $overrides = []): array
    {
        return $overrides + ['name' => 'Corte', 'duration_minutes' => 30, 'buffer_minutes' => 0];
    }

    // --- categories --------------------------------------------------------------------------------------------------

    public function test_a_category_is_created_renamed_and_deactivated_without_duplicates(): void
    {
        $this->post('/services/categories', ['name' => '  Coloración  '])->assertSessionHasNoErrors();
        $category = ServiceCategory::firstOrFail();
        $this->assertSame('Coloración', $category->name);
        $this->assertSame($this->workspace->id, $category->workspace_id);

        $this->post('/services/categories', ['name' => 'coloración'])->assertSessionHasErrors('name'); // same text, another capitalization
        $this->post('/services/categories', ['name' => '   '])->assertSessionHasErrors('name');

        $service = $this->makeService($this->workspace, ['name' => 'Tinte']);
        $this->post('/services', $this->service(['name' => 'Mechas', 'category_id' => $category->id]))->assertSessionHasNoErrors();
        $this->assertSame('Coloración', Service::where('name', 'Mechas')->value('category'));

        $this->put("/services/categories/{$category->id}", ['name' => 'Color'])->assertSessionHasNoErrors();
        $this->assertSame('Color', Service::where('name', 'Mechas')->value('category'), 'the services follow the rename');

        $this->post("/services/categories/{$category->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame($category->id, Service::where('name', 'Mechas')->value('category_id'), 'deactivating keeps the history');
        $this->assertNotNull($service->fresh());
    }

    public function test_the_form_lists_active_categories_and_the_server_refuses_an_inactive_one_for_a_new_assignment(): void
    {
        $active = ServiceCategory::create(['workspace_id' => $this->workspace->id, 'name' => 'Barba', 'normalized_name' => 'barba']);
        $inactive = ServiceCategory::create(['workspace_id' => $this->workspace->id, 'name' => 'Vieja', 'normalized_name' => 'vieja', 'is_active' => false]);

        $this->get('/services')->assertInertia(fn ($page) => $page->has('categoryAdmin.data', 2)->where('categoryAdmin.canCreate', true));
        $this->post('/services', $this->service(['category_id' => $inactive->id]))->assertSessionHasErrors('category_id');
        $this->post('/services', $this->service(['category_id' => $active->id]))->assertSessionHasNoErrors();

        // A service that already has it keeps it when edited, even if the category was switched off since.
        $kept = $this->workspace->services()->create(['name' => 'Antiguo', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'category_id' => $inactive->id, 'category' => 'Vieja']);
        $this->put("/services/{$kept->id}", $this->service(['name' => 'Antiguo', 'category_id' => $inactive->id]))->assertSessionHasNoErrors();
        $this->assertSame($inactive->id, $kept->fresh()->category_id);
    }

    public function test_a_category_of_another_workspace_can_not_be_used_or_touched_and_free_text_is_ignored(): void
    {
        $foreign = ServiceCategory::create(['workspace_id' => $this->other->id, 'name' => 'Ajena', 'normalized_name' => 'ajena']);

        $this->post('/services', $this->service(['category_id' => $foreign->id]))->assertSessionHasErrors('category_id');
        $this->put("/services/categories/{$foreign->id}", ['name' => 'Mía'])->assertNotFound();
        $this->post("/services/categories/{$foreign->id}/deactivate")->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);

        $this->post('/services', $this->service(['name' => 'Libre', 'category' => 'Escrita a mano']))->assertSessionHasNoErrors();
        $this->assertNull(Service::where('name', 'Libre')->value('category'), 'a typed category creates no second source');
    }

    // --- «Todos los servicios» de una sede ---------------------------------------------------------------------------

    private function location(string $name, bool $all = false)
    {
        return $this->workspace->locations()->create(['name' => $name, 'offers_all_services' => $all]);
    }

    public function test_a_sede_can_offer_all_services_without_a_list_and_a_list_is_ignored_while_it_is_on(): void
    {
        $one = $this->makeService($this->workspace, ['name' => 'Uno']);

        $this->post('/locations', ['name' => 'Norte', 'offers_all_services' => true])->assertSessionHasNoErrors();
        $north = $this->workspace->locations()->where('name', 'Norte')->firstOrFail();
        $this->assertTrue($north->offers_all_services);
        $this->assertSame(0, $north->services()->count());

        $this->put("/locations/{$north->id}", ['name' => 'Norte', 'offers_all_services' => true, 'service_ids' => [$one->id]])->assertSessionHasNoErrors();
        $this->assertSame(0, $north->services()->count(), 'no ambiguous mix of the flag and a list');

        $this->post('/locations', ['name' => 'Sur'])->assertSessionHasErrors('service_ids'); // neither the flag nor a service
        $this->get('/locations')->assertInertia(fn ($page) => $page->where('locations.0.offersAllServices', true));
    }

    public function test_excluding_a_service_turns_it_into_an_explicit_list_and_new_services_are_covered_by_the_flag(): void
    {
        $a = $this->makeService($this->workspace, ['name' => 'A']);
        $b = $this->makeService($this->workspace, ['name' => 'B']);
        $north = $this->location('Norte', all: true);
        $locations = app(Locations::class);

        $this->assertTrue($locations->offers($north, $a->id));
        $later = $this->makeService($this->workspace, ['name' => 'Posterior']);
        $this->assertTrue($locations->offers($north, $later->id), 'a service created afterwards needs no reassignment');

        $this->put("/locations/{$north->id}", ['name' => 'Norte', 'offers_all_services' => false, 'service_ids' => [$a->id]])->assertSessionHasNoErrors();
        $north->refresh();
        $this->assertFalse($north->offers_all_services);
        $b->locations()->sync([$this->location('Centro')->id => ['workspace_id' => $this->workspace->id]]);
        $this->assertTrue($locations->offers($north, $a->id));
        $this->assertFalse($locations->offers($north, $b->id), 'B is now restricted to Centro and Norte no longer offers every service');
    }

    public function test_a_flagged_sede_offers_a_service_restricted_elsewhere_in_the_agenda(): void
    {
        $service = $this->makeService($this->workspace, ['name' => 'Exclusivo']);
        $center = $this->location('Centro');
        $north = $this->location('Norte', all: true);
        $service->locations()->sync([$center->id => ['workspace_id' => $this->workspace->id]]);
        $pro = $this->makeProfessional($this->workspace, [$service], weekdays: []);
        $pro->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'location_id' => $north->id]);
        $day = CarbonImmutable::parse('2026-10-13', 'UTC');

        $this->assertNotSame([], $this->calculator()->slots($this->workspace, $service, null, $day, $day->addDay(), locationId: $north->id));
    }

    public function test_an_inactive_or_foreign_service_can_not_be_assigned_to_a_sede_by_a_forged_request(): void
    {
        $inactive = $this->makeService($this->workspace, ['name' => 'Retirado', 'is_active' => false]);
        $foreign = $this->makeService($this->other, ['name' => 'Ajeno']);

        $this->post('/locations', ['name' => 'Norte', 'service_ids' => [$inactive->id]])->assertSessionHasErrors('service_ids');
        $this->post('/locations', ['name' => 'Norte', 'service_ids' => [$foreign->id]])->assertSessionHasErrors('service_ids.0');
        $this->assertSame(0, $this->workspace->locations()->count());
    }

    // --- professionals: inactive records in new assignments -----------------------------------------------------------

    public function test_a_professional_can_not_get_an_inactive_service_or_sede_but_keeps_the_ones_it_already_has(): void
    {
        $active = $this->makeService($this->workspace, ['name' => 'Activo']);
        $retired = $this->makeService($this->workspace, ['name' => 'Retirado', 'is_active' => false]);
        $closed = $this->location('Cerrada');
        $closed->update(['is_active' => false]);
        $pro = $this->makeProfessional($this->workspace, [$active], 'Ana');

        $this->put("/professionals/{$pro->id}", ['name' => 'Ana', 'service_ids' => [$active->id, $retired->id]])->assertSessionHasErrors('service_ids');
        $this->put("/professionals/{$pro->id}", ['name' => 'Ana', 'service_ids' => [$active->id], 'schedule' => [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00', 'location_id' => $closed->id]]])->assertSessionHasErrors('schedule');

        // What it already has stays (service switched off later).
        $pro->services()->attach($retired->id, ['workspace_id' => $this->workspace->id]);
        $this->put("/professionals/{$pro->id}", ['name' => 'Ana', 'service_ids' => [$active->id, $retired->id]])->assertSessionHasNoErrors();
        $this->assertInstanceOf(Professional::class, $pro->fresh());
    }
}
