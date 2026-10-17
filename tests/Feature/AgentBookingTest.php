<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Service;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\Support\SimulatedCalendar;
use Tests\TestCase;

/**
 * The booking tools of Ava Chat. The automation (n8n) runs the operation a contact asked for through the agent API; Ava keeps the
 * booking, the audit and the truth about what happened. n8n is not tested here (it is simulated by the test itself): this proves Ava's
 * side of the contract.
 */
class AgentBookingTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings'];

    private Workspace $workspace;

    private Chatbot $bot;

    private string $token;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
        [$this->bot, $this->token] = $this->bot($this->workspace);
        $this->service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$this->service]);
    }

    /** @return array{Chatbot, string} */
    private function bot(Workspace $workspace): array
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);

        return [$bot, app(AgentAccess::class)->generate($bot)];
    }

    private function conversation(string $contact = '573001112233', ?Chatbot $bot = null): Conversation
    {
        return ($bot ?? $this->bot)->conversations()->create(['workspace_id' => $bot?->workspace_id ?? $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => 'Laura']);
    }

    private function agentCall(string $method, string $path, array $data = [], ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->json($method, "/api/agent/booking{$path}", $data);
    }

    private function book(array $overrides = [], string $contact = '573001112233'): array
    {
        return $overrides + ['channel' => 'whatsapp', 'contact_id' => $contact, 'service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => $contact]];
    }

    // --- discovery and reading ---------------------------------------------------------------------------------------------------

    public function test_the_agent_configuration_announces_the_booking_tools_only_when_the_plan_has_them(): void
    {
        $this->withToken($this->token)->getJson('/api/agent/config')->assertOk()->assertJsonPath('booking.available', true)->assertJsonPath('booking.endpoints.availability', url('/api/agent/booking/availability'));

        $this->contractModules($this->workspace, ['dashboard', 'assistants', 'conversations']);
        $this->withToken($this->token)->getJson('/api/agent/config')->assertOk()->assertJsonPath('booking', ['available' => false]);
    }

    public function test_the_automation_reads_services_professionals_and_availability_and_what_the_agenda_can_do(): void
    {
        $this->agentCall('GET', '/services')->assertOk()->assertJsonPath('services.0.name', 'Manicure')->assertJsonPath('capabilities', ['availability' => true, 'book' => true, 'reschedule' => true, 'cancel' => true]);
        $this->agentCall('GET', "/professionals?service_id={$this->service->id}")->assertOk()->assertJsonPath('professionals.0.name', 'Ana');
        $this->agentCall('GET', "/availability?service_id={$this->service->id}&from=2026-10-13&days=1")->assertOk()->assertJsonPath('slots.0.startsAt', '2026-10-13T09:00:00Z');
        $this->agentCall('GET', '/availability')->assertStatus(422);
    }

    // --- booking from a conversation -----------------------------------------------------------------------------------------------

    public function test_a_booking_is_tied_to_the_conversation_of_the_contact_and_the_real_result_is_returned(): void
    {
        $conversation = $this->conversation();

        $response = $this->agentCall('POST', '/appointments', $this->book())->assertCreated();

        $response->assertJsonPath('confirmed', true)->assertJsonPath('appointment.status', 'confirmed');
        $appointment = Appointment::sole();
        $this->assertSame([$conversation->id, 'chat', $this->workspace->id], [$appointment->conversation_id, $appointment->channel, $appointment->workspace_id]);

        $event = AuditLog::where('resource_type', 'appointment')->sole();
        $this->assertSame(['system', 'Asistente (automático)', 'created'], [$event->actor, $event->user_name, $event->action]);
        $this->assertStringNotContainsString('573001112233', json_encode($event->toArray()));
    }

    public function test_without_a_reported_conversation_there_is_nothing_to_attach_the_booking_to(): void
    {
        $this->agentCall('POST', '/appointments', $this->book())->assertNotFound()->assertJsonPath('reason', 'not_found');
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_workspace_that_confirms_bookings_never_gets_a_confirmed_answer_for_a_pending_one(): void
    {
        $this->workspace->bookingSetting()->updateOrCreate([], ['requires_confirmation' => true]);
        $this->conversation();

        $response = $this->agentCall('POST', '/appointments', $this->book())->assertCreated();

        // The booking exists but the business has not accepted it: the assistant must say "por confirmar", and Ava says so too.
        $response->assertJsonPath('confirmed', false)->assertJsonPath('appointment.status', 'requested');
        $this->assertSame(['requested'], Appointment::pluck('status')->all());

        // Repeating the request returns the same pending booking, still not confirmed.
        $this->agentCall('POST', '/appointments', $this->book())->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('confirmed', false);
        $this->assertSame(1, Appointment::count());
    }

    public function test_a_refused_booking_creates_nothing_and_reports_no_confirmation(): void
    {
        $this->conversation();
        $this->agentCall('POST', '/appointments', $this->book())->assertCreated();

        // The same slot for another person is refused: the answer carries a reason and no `confirmed` flag, and nothing new exists.
        $this->conversation('573009998877');
        $refused = $this->agentCall('POST', '/appointments', $this->book(['customer' => ['name' => 'Otra Persona', 'phone' => '+573009998877']], '573009998877'))->assertStatus(409);

        $this->assertSame('slot_unavailable', $refused->json('reason'));
        $this->assertArrayNotHasKey('confirmed', $refused->json());
        $this->assertArrayNotHasKey('appointment', $refused->json());
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_contact_is_validated_and_a_taken_slot_is_reported_as_such(): void
    {
        $this->conversation();
        $this->agentCall('POST', '/appointments', ['service_id' => $this->service->id])->assertStatus(422);
        $this->agentCall('POST', '/appointments', $this->book(['customer' => ['name' => '']]))->assertStatus(422);

        $this->agentCall('POST', '/appointments', $this->book())->assertCreated();
        $this->conversation('573009998877');
        $this->agentCall('POST', '/appointments', $this->book([], '573009998877'))->assertStatus(409)->assertJsonPath('reason', 'slot_unavailable');
        $this->assertSame(1, Appointment::count());
    }

    public function test_a_contact_sees_and_changes_only_the_appointments_of_its_own_conversation(): void
    {
        $this->conversation();
        $this->conversation('573009998877');
        $mine = $this->agentCall('POST', '/appointments', $this->book())->json('appointment.id');
        $theirs = $this->agentCall('POST', '/appointments', $this->book(['starts_at' => '2026-10-14T10:00:00Z'], '573009998877'))->json('appointment.id');

        $this->assertSame([$mine], array_column($this->agentCall('GET', '/appointments?channel=whatsapp&contact_id=573001112233')->assertOk()->json('appointments'), 'id'));

        $this->agentCall('POST', "/appointments/{$theirs}/cancel", ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertNotFound();
        $this->agentCall('POST', "/appointments/{$theirs}/reschedule", ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'starts_at' => '2026-10-15T10:00:00Z'])->assertNotFound();
        $this->assertSame('confirmed', Appointment::find($theirs)->status);

        $this->agentCall('POST', "/appointments/{$mine}/reschedule", ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'starts_at' => '2026-10-15T11:00:00Z'])->assertOk()->assertJsonPath('appointment.startsAt', '2026-10-15T11:00:00Z');
        $this->agentCall('POST', "/appointments/{$mine}/cancel", ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertOk()->assertJsonPath('appointment.status', 'cancelled');
        $this->agentCall('POST', "/appointments/{$mine}/cancel", ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertStatus(409);
    }

    // --- isolation, plan and failures ------------------------------------------------------------------------------------------------------

    public function test_the_token_of_another_workspace_never_reaches_this_agenda(): void
    {
        $other = $this->bookingWorkspace('OTRO', self::MODULES);
        [$otherBot, $otherToken] = $this->bot($other);
        $otherService = $this->makeService($other, ['name' => 'Ajeno']);
        $this->makeProfessional($other, [$otherService], 'Ajena');
        $this->conversation();
        $this->agentCall('POST', '/appointments', $this->book());

        $this->assertSame(['Ajeno'], array_column($this->agentCall('GET', '/services', [], $otherToken)->json('services'), 'name'));
        $this->agentCall('POST', '/appointments', $this->book(['service_id' => $this->service->id]), $otherToken)->assertStatus(404); // no conversation there either
        $this->conversation('573001112233', $otherBot);
        $this->agentCall('POST', '/appointments', $this->book(['service_id' => $this->service->id]), $otherToken)->assertStatus(422); // that service does not exist there
        $this->assertSame([], $this->agentCall('GET', '/appointments?channel=whatsapp&contact_id=573001112233', [], $otherToken)->json('appointments'));
    }

    public function test_the_tools_need_every_booking_module_and_the_assistant_and_the_inbox(): void
    {
        foreach (['service_catalog', 'professionals', 'bookings'] as $missing) {
            $this->contractModules($this->workspace, array_diff(self::MODULES, [$missing]));
            $this->agentCall('GET', '/services')->assertForbidden();
            $this->agentCall('POST', '/appointments', $this->book())->assertForbidden();
        }

        foreach (['assistants', 'conversations'] as $missing) {
            $this->contractModules($this->workspace, array_diff(self::MODULES, [$missing]));
            $this->agentCall('GET', '/services')->assertForbidden();
        }

        $this->agentCall('GET', '/services', [], 'ava_invalid')->assertUnauthorized();
        $this->withHeaders([])->getJson('/api/agent/booking/services')->assertUnauthorized();
    }

    public function test_a_failure_of_the_external_agenda_is_reported_and_never_as_a_confirmation(): void
    {
        $simulated = new SimulatedCalendar;
        $simulated->failWith['book'] = 'unavailable';
        $this->useSimulatedAgenda($simulated);
        $this->useBookerSetting($this->workspace);
        $this->conversation();

        // A lost answer may have been processed: unknown, and never repeated by itself (see PlatformRunTest for the full recovery).
        $response = $this->agentCall('POST', '/appointments', $this->book(['service_id' => 'S1']))->assertStatus(409);

        $this->assertSame('uncertain', $response->json('reason'));
        $this->assertArrayNotHasKey('confirmed', $response->json());
        $this->assertSame(['requested', 'uncertain', 'unavailable'], [Appointment::sole()->status, Appointment::sole()->sync_status, Appointment::sole()->sync_error]);

        // A certain refusal (credentials) is a failure and the contact may try again.
        Appointment::query()->delete();
        $simulated->failWith['book'] = 'auth';
        $refused = $this->agentCall('POST', '/appointments', $this->book(['service_id' => 'S1']))->assertStatus(502);
        $this->assertSame('provider', $refused->json('reason'));
        $this->assertSame(['failed', 'auth'], [Appointment::sole()->status, Appointment::sole()->sync_error]);

        $simulated->failWith = [];
        $ok = $this->agentCall('POST', '/appointments', $this->book(['service_id' => 'S1']))->assertCreated();
        $this->assertSame(['confirmed', true], [$ok->json('appointment.status'), $ok->json('confirmed')]);
    }

    public function test_an_agenda_that_cannot_book_is_announced_so_the_automation_can_tell_the_contact(): void
    {
        $this->useSimulatedAgenda(new SimulatedCalendar(['services' => true, 'professionals' => true, 'availability' => false, 'book' => false, 'reschedule' => false, 'cancel' => true, 'find' => true]));
        $this->useBookerSetting($this->workspace);
        $this->conversation();

        $this->agentCall('GET', '/services')->assertOk()->assertJsonPath('capabilities.book', false)->assertJsonPath('capabilities.availability', false);
        $this->agentCall('POST', '/appointments', $this->book(['service_id' => 'S1']))->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->assertSame(0, Appointment::count());
    }
}
