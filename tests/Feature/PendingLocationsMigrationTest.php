<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The code can reach an environment before its database is migrated (it happened in development: `locations` did not exist and Sedes, Agenda
 * and Profesionales answered 500 after ~3 s with a debug page of a megabyte). Until the migration is applied the pages that READ must open and
 * say what is missing, writes that need the new structure must be refused with a clear message (never a database error), and
 * `integrations:diagnose` must name the pending migration. The schema is taken back to "before" with the migration's own down().
 */
class PendingLocationsMigrationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MIGRATION = '2026_10_18_100000_create_locations_and_booking_traceability';

    private Workspace $workspace;

    private Service $service;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('CLINICA', ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations', 'assistants', 'conversations']);
        $this->service = $this->makeService($this->workspace);
        $this->professional = $this->makeProfessional($this->workspace, [$this->service]);
        $this->actAsMember($this->workspace);

        // The schema as it was before the migration, with the migration still unapplied.
        (require database_path('migrations/'.self::MIGRATION.'.php'))->down();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
    }

    public function test_the_pages_open_and_the_locations_page_says_what_is_missing(): void
    {
        $this->get('/locations')->assertOk()->assertInertia(fn ($page) => $page->component('Locations/Index')->where('schemaPending', true)->has('locations', 0));
        $this->get('/bookings')->assertOk()->assertInertia(fn ($page) => $page->where('options.locations', [])->where('options.locationRequired', false));
        $this->get('/professionals')->assertOk()->assertInertia(fn ($page) => $page->where('locations', []));
        $this->get('/services')->assertOk();
    }

    public function test_changes_that_need_the_new_structure_are_refused_clearly_and_leave_nothing_behind(): void
    {
        $this->post('/locations', ['name' => 'Sede Norte'])->assertSessionHas('error')->assertSessionMissing('success');
        $this->post('/locations/1/deactivate')->assertSessionHas('error');
        $this->put('/locations/1', ['name' => 'X'])->assertSessionHas('error');
        $this->assertFalse(\Schema::hasTable('locations'));

        $this->post('/bookings', ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertSessionHas('error');
        $this->assertSame(0, Appointment::count());

        // The schedule can still be saved as before, but nothing can be tied to a location that cannot exist yet.
        $base = ['name' => 'Ana', 'service_ids' => [$this->service->id]];
        $this->put("/professionals/{$this->professional->id}", $base + ['schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00']]])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->professional->scheduleRules()->count());
        $this->put("/professionals/{$this->professional->id}", $base + ['schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00', 'location_id' => 1]]])->assertSessionHasErrors('schedule.0.location_id');
    }

    public function test_the_open_channels_answer_without_failing(): void
    {
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();

        $this->getJson("/api/booking/{$key}/config")->assertOk()->assertJsonPath('locations', [])->assertJsonPath('locationRequired', false);
        $this->getJson("/api/booking/{$key}/services")->assertOk();
        $this->postJson("/api/booking/{$key}/appointments", ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertStatus(409)->assertJsonPath('reason', 'state');
    }

    public function test_the_diagnosis_names_the_pending_migration(): void
    {
        Artisan::call('integrations:diagnose');
        $output = Artisan::output();

        $this->assertStringContainsString('migración(es) pendiente(s)', $output);
        $this->assertStringContainsString(self::MIGRATION, $output);
    }
}
