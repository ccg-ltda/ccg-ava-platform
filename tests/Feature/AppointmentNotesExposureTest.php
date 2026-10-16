<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The free-text note of an appointment can receive whatever a person types, including health information nobody asked for (D-07 is open).
 * Until a policy exists the note is kept ONLY where staff with `view-bookings` read the agenda: it must not travel to the customer link,
 * the assistant's answers, the audit trail, the customer file or another Workspace.
 */
class AppointmentNotesExposureTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const NOTE = 'NOTA-SENSIBLE-123';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('CLINICA', ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings', 'customers']);
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['service_id' => $this->workspace->services()->value('id'), 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233'], 'notes' => self::NOTE];
    }

    public function test_the_note_never_reaches_the_customer_link_the_assistant_the_audit_or_the_customer_file(): void
    {
        // Public page: the visitor's own note is stored but never echoed back.
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();
        $created = $this->postJson("/api/booking/{$key}/appointments", $this->payload())->assertCreated();
        $this->assertStringNotContainsString(self::NOTE, $created->getContent());
        $this->assertStringNotContainsString(self::NOTE, $this->getJson("/api/booking/{$key}/appointments/".$created->json('token'))->getContent());

        // Assistant: book and list; the answer whitelist has no note.
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'x']);
        $token = app(AgentAccess::class)->generate($bot);
        $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573009998888', 'contact_name' => 'Ana']);
        $contact = ['channel' => 'whatsapp', 'contact_id' => '573009998888'];
        $booked = $this->withToken($token)->postJson('/api/agent/booking/appointments', $contact + $this->payload(['starts_at' => '2026-10-13T12:00:00Z', 'customer' => ['name' => 'Ana', 'phone' => '573009998888']]))->assertCreated();
        $this->assertStringNotContainsString(self::NOTE, $booked->getContent());
        $this->assertStringNotContainsString(self::NOTE, $this->withToken($token)->getJson('/api/agent/booking/appointments?channel=whatsapp&contact_id=573009998888')->getContent());

        // Audit trail: nothing about any note.
        $this->assertStringNotContainsString(self::NOTE, AuditLog::all()->toJson());

        // Customer file: shows the booking but not its note.
        $this->actAsMember($this->workspace);
        $customer = $this->workspace->customers()->create(['first_name' => 'Laura', 'phone' => '+573001112233', 'phone_normalized' => '573001112233', 'phone_e164' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);
        $this->assertStringNotContainsString(self::NOTE, $this->get("/customers/{$customer->id}")->getContent());

        // Staff with view-bookings do read it in the agenda (that is its only purpose).
        $this->assertStringContainsString(self::NOTE, $this->get('/bookings?from=2026-10-13&days=1')->getContent());
    }

    public function test_a_role_without_view_bookings_and_another_workspace_never_see_the_note(): void
    {
        $this->actAsMember($this->workspace);
        $this->post('/bookings', $this->payload())->assertSessionHas('success');

        $this->actAsMember($this->workspace, 'cliente');
        $this->get('/bookings')->assertForbidden();

        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $this->actAsMember($other);
        $this->assertStringNotContainsString(self::NOTE, $this->get('/bookings?from=2026-10-13&days=7')->getContent());
    }
}
