<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BookingCustomer;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Service;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_availability_never_offers_a_taken_slot_and_a_day_without_hours_is_an_empty_answer_not_an_error(): void
    {
        $this->conversation();
        $query = "/availability?service_id={$this->service->id}&from=2026-10-13&days=1";
        $before = collect($this->agentCall('GET', $query)->assertOk()->json('slots'))->pluck('startsAt');
        $this->assertContains('2026-10-13T10:00:00Z', $before);

        $this->agentCall('POST', '/appointments', $this->book())->assertCreated();

        $after = collect($this->agentCall('GET', $query)->assertOk()->json('slots'))->pluck('startsAt');
        $this->assertNotContains('2026-10-13T10:00:00Z', $after, 'a booked slot is no longer offered');
        $this->assertLessThan($before->count(), $after->count(), 'slots overlapping the booking disappear too');

        // Nothing is open on this date: the workflow gets an empty list to tell the contact, never a 4xx/5xx or an invented slot.
        $this->agentCall('GET', "/availability?service_id={$this->service->id}&from=2026-12-25&days=1")->assertOk()->assertJsonPath('slots', []);
    }

    // --- D-TEL: the phone of a WhatsApp contact comes from the conversation, not from the workflow --------------------------------

    /** The booking the workflow sends when it does NOT know the contact's number (Ava never gives it to the workflow). */
    private function bookWithoutPhone(string $contact = '573001112233', array $customer = ['name' => 'Laura Pérez']): array
    {
        return ['channel' => 'whatsapp', 'contact_id' => $contact, 'service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $customer];
    }

    public function test_on_whatsapp_a_booking_without_phone_uses_the_verified_number_of_the_conversation(): void
    {
        $this->conversation();

        $response = $this->agentCall('POST', '/appointments', $this->bookWithoutPhone())->assertCreated();

        $this->assertSame('573001112233', BookingCustomer::sole()->phone);
        $this->assertSame('Laura Pérez', BookingCustomer::sole()->name);
        // The number goes into the booking, never back out to the workflow: the answer, the audit and the trace carry no phone.
        $this->assertStringNotContainsString('573001112233', $response->getContent());
        $this->assertStringNotContainsString('573001112233', json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString('573001112233', json_encode(DB::table('agent_tool_calls')->get()->all()));
    }

    public function test_a_phone_the_workflow_does_send_is_kept_and_never_replaced_by_the_conversation_number(): void
    {
        $this->conversation();

        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone('573001112233', ['name' => 'Para su mamá', 'phone' => '+57 310 555 0000']))->assertCreated();

        $this->assertSame(['573105550000'], BookingCustomer::pluck('phone')->all());
    }

    public function test_a_whatsapp_id_that_is_not_a_valid_international_number_is_never_used_as_a_phone(): void
    {
        foreach (['12345' => 'too short for a phone', 'abc-123-xyz' => 'an internal identifier', '999999999999999999' => 'more digits than a phone has', '0573001112233' => 'a leading zero'] as $contact => $why) {
            $this->conversation((string) $contact);

            $refused = $this->agentCall('POST', '/appointments', $this->bookWithoutPhone((string) $contact))->assertStatus(422);

            $this->assertSame('contact_required', $refused->json('reason'), $why);
            $this->assertSame(0, Appointment::count(), $why);
            $this->assertSame(0, BookingCustomer::count(), $why);
        }

        // The Workspace may accept an email alone; then the booking proceeds and no phone is invented.
        $this->workspace->bookingSetting()->updateOrCreate([], ['require_phone' => false]);
        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone('12345', ['name' => 'Laura', 'email' => 'laura@example.com']))->assertCreated();
        $this->assertNull(BookingCustomer::sole()->phone);
    }

    public function test_webchat_asks_for_a_phone_or_an_email_and_never_takes_one_from_the_visitor_id(): void
    {
        $this->bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'web', 'contact_id' => '573001112233', 'contact_name' => 'Visitante']);
        $web = fn (array $customer) => ['channel' => 'web', 'contact_id' => '573001112233', 'service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $customer];

        $refused = $this->agentCall('POST', '/appointments', $web(['name' => 'Laura']))->assertStatus(422);

        $this->assertSame('contact_required', $refused->json('reason'));
        $this->assertStringContainsString('teléfono o un correo', $refused->json('message'));
        $this->assertSame(0, Appointment::count());
        // The trace records a rejected call with the fixed code, never the contact data.
        $this->assertSame(['rejected', 'contact_required'], [DB::table('agent_tool_calls')->where('tool', 'booking.book')->value('status'), DB::table('agent_tool_calls')->where('tool', 'booking.book')->value('reason')]);

        // Operations that need no contact data are not blocked by the missing data.
        $this->agentCall('GET', "/availability?service_id={$this->service->id}&from=2026-10-13&days=1")->assertOk();
        $this->agentCall('GET', '/appointments', ['channel' => 'web', 'contact_id' => '573001112233'])->assertOk()->assertJsonPath('appointments', []);

        // By default the Workspace also asks for a phone: an email alone is not enough, and the answer says which datum is missing.
        $this->agentCall('POST', '/appointments', $web(['name' => 'Laura', 'email' => 'laura@example.com']))->assertStatus(422)->assertJsonPath('reason', 'contact_required');
        $this->assertSame(0, Appointment::count());

        $this->agentCall('POST', '/appointments', $web(['name' => 'Laura', 'phone' => '+57 300 111 2233']))->assertCreated();
        $this->assertSame(['573001112233'], BookingCustomer::pluck('phone')->all());
    }

    public function test_the_workspace_rule_that_asks_for_an_email_is_still_enforced_with_the_same_reason(): void
    {
        $this->workspace->bookingSetting()->updateOrCreate([], ['require_email' => true]);
        $this->conversation();

        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone())->assertStatus(422)->assertJsonPath('reason', 'contact_required');
        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone('573001112233', ['name' => 'Laura', 'email' => 'laura@example.com']))->assertCreated();
    }

    public function test_retries_and_a_second_booking_by_the_same_contact_never_duplicate_the_booking_or_the_customer(): void
    {
        $this->conversation();

        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone())->assertCreated();
        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone())->assertOk()->assertJsonPath('replayed', true);
        // The workflow that did send the contact's own number lands on the same booking too.
        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone('573001112233', ['name' => 'Laura Pérez', 'phone' => '573001112233']))->assertOk()->assertJsonPath('replayed', true);
        // The same person books another time: the same customer, no second record.
        $this->agentCall('POST', '/appointments', ['starts_at' => '2026-10-13T14:00:00Z'] + $this->bookWithoutPhone())->assertCreated();

        $this->assertSame([2, 1], [Appointment::count(), BookingCustomer::count()]);
    }

    public function test_the_same_whatsapp_number_in_another_workspace_is_a_different_customer_in_that_workspace_only(): void
    {
        $other = $this->bookingWorkspace('OTHER', self::MODULES);
        [$otherBot, $otherToken] = $this->bot($other);
        $otherService = $this->makeService($other);
        $this->makeProfessional($other, [$otherService]);
        $this->conversation();
        $this->conversation('573001112233', $otherBot);

        $this->agentCall('POST', '/appointments', $this->bookWithoutPhone())->assertCreated();
        $theirs = $this->agentCall('POST', '/appointments', ['service_id' => $otherService->id] + $this->bookWithoutPhone(), $otherToken)->assertCreated();

        $this->assertSame([1, 1], [BookingCustomer::where('workspace_id', $this->workspace->id)->count(), BookingCustomer::where('workspace_id', $other->id)->count()]);
        $this->assertSame($other->id, Appointment::findOrFail($theirs->json('appointment.id'))->workspace_id);
    }
}
