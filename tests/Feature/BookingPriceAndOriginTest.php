<?php

namespace Tests\Feature;

use App\Booking\AppointmentService;
use App\Models\Appointment;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Service;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * What an appointment remembers: the price it had WHEN it was booked and where it came from. Both are decided by the server from data it
 * holds (the service, the conversation), never from a field the client sends, and both leave the past alone when the service changes.
 */
class BookingPriceAndOriginTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings'];

    private Workspace $workspace;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
        $this->workspace->settingsOrDefault()->update(['currency' => 'COP']);
        $this->service = $this->makeService($this->workspace, ['name' => 'Consulta', 'duration_minutes' => 30, 'price' => 80000]);
        $this->makeProfessional($this->workspace, [$this->service]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233']];
    }

    /** @return array{Chatbot, string} */
    private function bot(Workspace $workspace): array
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);

        return [$bot, app(AgentAccess::class)->generate($bot)];
    }

    // --- price -------------------------------------------------------------------------------------------------------------------

    public function test_the_appointment_keeps_the_price_and_currency_it_had_when_it_was_booked(): void
    {
        $this->actAsMember($this->workspace);
        $this->post('/bookings', $this->payload())->assertSessionHas('success');
        $appointment = Appointment::firstOrFail();

        $this->assertSame('80000.00', $appointment->price_amount);
        $this->assertSame('COP', $appointment->price_currency);

        // The service is repriced and the Workspace changes currency: the booked appointment is exactly what it was.
        $this->service->update(['price' => 120000]);
        $this->workspace->settingsOrDefault()->update(['currency' => 'USD']);
        $this->assertSame('80000.00', $appointment->fresh()->price_amount);
        $this->assertSame('COP', $appointment->fresh()->price_currency);

        // New bookings use the new price; moving the old one does not reprice it.
        $this->post('/bookings', $this->payload(['starts_at' => '2026-10-13T12:00:00Z', 'customer' => ['name' => 'Otra', 'phone' => '+573009998888']]))->assertSessionHas('success');
        $this->assertSame('120000.00', Appointment::orderByDesc('id')->first()->price_amount);
        $this->assertSame('USD', Appointment::orderByDesc('id')->first()->price_currency);
        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T14:00:00Z'])->assertSessionHas('success');
        $this->assertSame('80000.00', $appointment->fresh()->price_amount);

        $this->get('/bookings')->assertInertia(fn ($page) => $page->where('appointments.0.price', 120000)->where('appointments.0.currency', 'USD')->where('appointments.1.price', 80000)->where('appointments.1.currency', 'COP'));
    }

    public function test_a_service_without_price_records_none_and_never_a_zero(): void
    {
        $free = $this->makeService($this->workspace, ['name' => 'Valoración', 'price' => null]);
        $this->workspace->professionals()->first()->services()->attach($free->id, ['workspace_id' => $this->workspace->id]);
        $this->actAsMember($this->workspace);

        $this->post('/bookings', $this->payload(['service_id' => $free->id]))->assertSessionHas('success');

        $appointment = Appointment::firstOrFail();
        $this->assertNull($appointment->price_amount);
        $this->assertNull($appointment->price_currency);
        $this->get('/bookings')->assertInertia(fn ($page) => $page->where('appointments.0.price', null));
    }

    public function test_appointments_from_before_prices_were_recorded_show_no_price_instead_of_todays(): void
    {
        $this->actAsMember($this->workspace);
        $this->post('/bookings', $this->payload())->assertSessionHas('success');
        // What a historical row looks like: the columns exist but nothing was written.
        DB::table('appointments')->update(['price_amount' => null, 'price_currency' => null]);

        $this->get('/bookings')->assertInertia(fn ($page) => $page->where('appointments.0.price', null)->where('appointments.0.currency', null));
    }

    public function test_the_service_form_accepts_only_valid_prices(): void
    {
        $this->actAsMember($this->workspace);
        $base = ['duration_minutes' => 30];

        $this->post('/services', $base + ['name' => 'A', 'price' => '-1'])->assertSessionHasErrors('price');
        $this->post('/services', $base + ['name' => 'B', 'price' => 'abc'])->assertSessionHasErrors('price');
        $this->post('/services', $base + ['name' => 'C', 'price' => '10.999'])->assertSessionHasErrors('price');
        $this->post('/services', $base + ['name' => 'D', 'price' => '100000000'])->assertSessionHasErrors('price');
        $this->assertSame(1, $this->workspace->services()->count());

        $this->post('/services', $base + ['name' => 'E', 'price' => '49.90'])->assertSessionHasNoErrors();
        $this->post('/services', $base + ['name' => 'F', 'price' => '0'])->assertSessionHasNoErrors();
        $this->post('/services', $base + ['name' => 'G', 'price' => ''])->assertSessionHasNoErrors();
        $this->assertSame('49.90', $this->workspace->services()->where('name', 'E')->value('price'));
        $this->assertNull($this->workspace->services()->where('name', 'G')->value('price'));
    }

    public function test_the_public_page_and_the_assistant_tell_the_price_that_was_recorded(): void
    {
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();
        $token = $this->postJson("/api/booking/{$key}/appointments", $this->payload())->assertCreated()->assertJsonPath('appointment.price', 80000)->assertJsonPath('appointment.currency', 'COP')->json('token');

        $this->service->update(['price' => 1]);
        $this->getJson("/api/booking/{$key}/appointments/{$token}")->assertOk()->assertJsonPath('appointment.price', 80000);
    }

    // --- origin ------------------------------------------------------------------------------------------------------------------

    public function test_each_entry_point_records_its_own_origin_and_ignores_what_the_client_claims(): void
    {
        $user = $this->actAsMember($this->workspace);
        $this->post('/bookings', $this->payload(['channel' => 'chat', 'origin_channel' => 'whatsapp']))->assertSessionHas('success');
        $admin = Appointment::firstOrFail();
        $this->assertSame('admin', $admin->channel);
        $this->assertNull($admin->origin_channel);

        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();
        $this->postJson("/api/booking/{$key}/appointments", $this->payload(['starts_at' => '2026-10-13T12:00:00Z', 'channel' => 'admin', 'origin_channel' => 'whatsapp', 'customer' => ['name' => 'Web', 'phone' => '+573005550000']]))->assertCreated();
        $web = Appointment::orderByDesc('id')->first();
        $this->assertSame('web', $web->channel);
        $this->assertNull($web->origin_channel);

        $this->get('/bookings')->assertInertia(fn ($page) => $page
            ->where('appointments.0.originLabel', 'Agenda del negocio')
            ->where('appointments.1.originLabel', 'Página de reservas'));
        $this->assertNotNull($user->id);
    }

    public function test_an_assistant_booking_is_credited_to_the_channel_of_its_own_conversation_only(): void
    {
        [$bot, $token] = $this->bot($this->workspace);
        $whatsapp = $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura']);
        $web = $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'web', 'contact_id' => 'visitor-1', 'contact_name' => 'Visitante']);
        $call = fn (Conversation $conversation, array $extra = []) => $this->withToken($token)->postJson('/api/agent/booking/appointments', $extra + [
            'channel' => $conversation->channel, 'contact_id' => $conversation->contact_id, 'service_id' => $this->service->id,
            'starts_at' => $conversation->channel === 'web' ? '2026-10-13T12:00:00Z' : '2026-10-13T10:00:00Z',
            'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233'],
        ]);

        // Claiming a different origin in the body changes nothing.
        $call($whatsapp, ['origin_channel' => 'web'])->assertCreated();
        $call($web, ['origin_channel' => 'whatsapp'])->assertCreated();

        $byConversation = Appointment::pluck('origin_channel', 'conversation_id');
        $this->assertSame('whatsapp', $byConversation[$whatsapp->id]);
        $this->assertSame('web', $byConversation[$web->id]);
        $this->assertSame(['chat'], Appointment::pluck('channel')->unique()->values()->all());

        $this->actAsMember($this->workspace);
        $this->get('/bookings?from=2026-10-13&days=1')->assertInertia(fn ($page) => $page
            ->where('appointments.0.originLabel', 'Asistente · WhatsApp')
            ->where('appointments.1.originLabel', 'Asistente · Chat web'));
    }

    public function test_a_conversation_of_another_workspace_is_never_credited_and_unknown_channels_are_refused(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        [$otherBot] = $this->bot($other);
        $foreign = $otherBot->conversations()->create(['workspace_id' => $other->id, 'channel' => 'whatsapp', 'contact_id' => '573009998888', 'contact_name' => 'Ajeno']);
        $service = app(AppointmentService::class);

        $this->assertNull($service->originChannel($this->workspace, 'chat', $foreign->id));
        $this->assertSame('whatsapp', $service->originChannel($other, 'chat', $foreign->id));
        $this->assertNull($service->originChannel($other, 'admin', $foreign->id), 'only an assistant booking is credited to a conversation');
        $this->assertNull($service->originChannel($other, 'chat', null));

        $this->expectException(InvalidArgumentException::class);
        $service->book($this->workspace, $this->service, null, $this->now()->addDay()->setTime(10, 0), ['name' => 'X', 'phone' => '+573001112233', 'email' => null], 'whatsapp');
    }

    public function test_the_agenda_filters_by_origin_and_ignores_a_made_up_one(): void
    {
        $this->actAsMember($this->workspace);
        $this->post('/bookings', $this->payload())->assertSessionHas('success');
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();
        $this->postJson("/api/booking/{$key}/appointments", $this->payload(['starts_at' => '2026-10-13T12:00:00Z', 'customer' => ['name' => 'Web', 'phone' => '+573005550000']]))->assertCreated();

        $this->get('/bookings?from=2026-10-13&days=1&origin=web')->assertInertia(fn ($page) => $page->has('appointments', 1)->where('appointments.0.channel', 'web')->where('filters.origin', 'web'));
        $this->get('/bookings?from=2026-10-13&days=1&origin=admin')->assertInertia(fn ($page) => $page->has('appointments', 1)->where('appointments.0.channel', 'admin'));
        $this->get('/bookings?from=2026-10-13&days=1&origin=facebook')->assertOk()->assertInertia(fn ($page) => $page->has('appointments', 2)->where('filters.origin', null));
    }

    // --- migration: compatible with what already exists ---------------------------------------------------------------------------

    public function test_the_migration_keeps_every_existing_appointment_and_only_credits_what_the_data_proves(): void
    {
        $this->actAsMember($this->workspace);
        [$bot] = $this->bot($this->workspace);
        $conversation = $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura']);
        $migration = require database_path('migrations/2026_10_18_100000_create_locations_and_booking_traceability.php');

        // Go back to the schema as it was before, with appointments that already exist.
        $migration->down();
        $customer = $this->workspace->bookingCustomers()->create(['name' => 'Laura', 'phone' => '573001112233']);
        $row = fn (array $extra) => $extra + [
            'workspace_id' => $this->workspace->id, 'service_id' => $this->service->id, 'customer_id' => $customer->id, 'service_name' => 'Consulta', 'status' => 'confirmed',
            'provider' => 'internal', 'sync_status' => 'none', 'reschedule_count' => 0, 'conversation_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ];
        $times = fn (string $start) => ['starts_at' => $start, 'ends_at' => $start, 'blocks_until' => $start];
        DB::table('appointments')->insert([
            $row($times('2026-10-13 09:00:00') + ['channel' => 'chat', 'conversation_id' => $conversation->id]),
            $row($times('2026-10-13 10:00:00') + ['channel' => 'chat']),
            $row($times('2026-10-13 11:00:00') + ['channel' => 'web']),
            $row($times('2026-10-13 12:00:00') + ['channel' => 'admin']),
        ]);

        $migration->up();

        $this->assertSame(4, Appointment::count(), 'no appointment is lost');
        $this->assertSame(['whatsapp', null, null, null], Appointment::orderBy('starts_at')->pluck('origin_channel')->all(), 'only the one that points to a real conversation is credited');
        $this->assertSame([null, null, null, null], Appointment::orderBy('starts_at')->pluck('price_amount')->all(), 'a past price is never invented from the current one');
        $this->assertSame([null, null, null, null], Appointment::orderBy('starts_at')->pluck('location_id')->all());
    }
}
