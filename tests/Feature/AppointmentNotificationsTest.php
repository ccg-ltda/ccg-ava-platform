<?php

namespace Tests\Feature;

use App\Booking\AppointmentNotifier;
use App\Booking\AppointmentReplies;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentReply;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Service;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Confirmations and reminders of appointments, one shared capability. Meta is simulated with Http::fake: these tests prove Ava's side (what it
 * plans, when, what it refuses, what it records and that it never sends twice); they do NOT prove that Meta accepts the messages or that a
 * template is approved, which needs a real account.
 */
class AppointmentNotificationsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'service_catalog', 'professionals', 'bookings', 'integrations', 'assistants', 'conversations'];

    private Workspace $workspace;

    private Workspace $other;

    private Service $service;

    private Integration $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        config(['bookings.notifications.phone_policy_since' => '2026-01-01']);
        $this->workspace = $this->bookingWorkspace('CLINICA', self::MODULES);
        $this->other = $this->bookingWorkspace('SALON', self::MODULES);
        $this->service = $this->makeService($this->workspace, ['name' => 'Consulta', 'duration_minutes' => 30]);
        $this->makeProfessional($this->workspace, [$this->service], 'Dra. Ruiz');
        $this->whatsapp = $this->account($this->workspace);
        $this->workspace->settingsOrDefault()->update(['date_format' => 'ymd', 'time_format' => '24h']);
        $this->meta();
    }

    private int $metaCounter = 0;

    /** Replaces what Meta answers (a new fake each time: the first stub registered would otherwise win). Accepted messages get distinct ids, like the real API. */
    private function meta(mixed $response = null): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => $response ?? fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.++$this->metaCounter]]], 200)]);
    }

    private function account(Workspace $workspace, string $name = 'WhatsApp', bool $active = true, string $phoneId = '111222333'): Integration
    {
        return $workspace->integrations()->create(['name' => $name, 'type' => 'whatsapp', 'is_active' => $active, 'config' => ['phone_number_id' => $phoneId], 'secrets' => ['access_token' => 'TOKEN-SECRETO']]);
    }

    /** @param  array<string, mixed>  $values */
    private function configure(array $values, ?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;
        $workspace->bookingSetting()->updateOrCreate([], $values);
        // The Workspace object of the test already read its (then empty) settings: forget it, as a new request would.
        $workspace->unsetRelation('bookingSetting');
    }

    private function template(string $kind = 'reminder', array $params = ['nombre', 'fecha', 'hora']): array
    {
        return [$kind => ['template' => ['name' => 'recordatorio_cita', 'language' => 'es', 'params' => $params]]];
    }

    /** A booking by the team (confirmed at once) of the main Workspace; "now" is Monday 2026-10-12 08:00 UTC and the professional works Tuesday 09:00-17:00. */
    private function book(string $at = '2026-10-13T10:00:00Z', string $phone = '+573001112233', string $channel = 'admin', ?Workspace $workspace = null, ?int $conversationId = null): Appointment
    {
        $workspace ??= $this->workspace;

        return $this->appointments()->book($workspace, $workspace->services()->firstOrFail(), null, CarbonImmutable::parse($at, 'UTC'), ['name' => 'Laura Pérez', 'phone' => $phone, 'email' => null], $channel, null, $conversationId)['appointment'];
    }

    /** @return list<array<string, mixed>> the bodies sent to Meta's messages endpoint */
    private function sent(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    private function notifications(?Appointment $appointment = null)
    {
        return AppointmentNotification::when($appointment, fn ($q) => $q->where('appointment_id', $appointment->id))->orderBy('id')->get();
    }

    private function openWindow(string $digits = '573001112233', ?Workspace $workspace = null): Conversation
    {
        $workspace ??= $this->workspace;
        $bot = $workspace->chatbots()->firstOrCreate(['name' => 'Asistente'], ['instructions' => 'x']);
        $conversation = $bot->conversations()->firstOrCreate(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'contact_id' => $digits], ['contact_name' => 'Laura']);
        $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'external_id' => 'wamid.IN'.$conversation->messages()->count(), 'sent_at' => now()]);

        return $conversation;
    }

    private function tick(): void
    {
        Artisan::call('appointments:notify');
    }

    // --- configuration: per Workspace, validated, honest --------------------------------------------------------------------------------

    public function test_each_workspace_keeps_its_own_configuration_and_the_form_validates_it(): void
    {
        $this->actAsMember($this->workspace);
        $valid = ['notify_received' => false, 'notify_confirmed' => true, 'reminders_enabled' => true, 'reminder_offsets' => [120, 1440], 'messages' => ['reminder' => ['text' => 'Hola {{nombre}}, te esperamos el {{fecha}}.']]];

        $this->put('/bookings/settings/notifications', $valid)->assertSessionHasNoErrors();
        $saved = $this->workspace->bookingSetting()->first();
        $this->assertSame([1440, 120], $saved->offsets(), 'longest first');
        $this->assertTrue($saved->notify_confirmed);
        $this->assertNull($this->other->bookingSetting()->first(), 'another Workspace is untouched');

        $bad = fn (array $override) => $this->put('/bookings/settings/notifications', array_replace_recursive($valid, $override));
        // (array_replace_recursive keeps the offsets of $valid when given an empty list, so the empty case is sent on its own)
        $this->put('/bookings/settings/notifications', ['notify_received' => false, 'notify_confirmed' => false, 'reminders_enabled' => true, 'reminder_offsets' => []])->assertSessionHasErrors('reminder_offsets');
        $bad(['reminder_offsets' => [5]])->assertSessionHasErrors('reminder_offsets.0');
        $bad(['reminder_offsets' => [20161]])->assertSessionHasErrors('reminder_offsets.0');
        $bad(['reminder_offsets' => [60, 60]])->assertSessionHasErrors('reminder_offsets.0');
        $bad(['reminder_offsets' => [60, 120, 180, 240]])->assertSessionHasErrors('reminder_offsets');
        $bad(['messages' => ['reminder' => ['text' => 'Hola {{apellido}}']]])->assertSessionHasErrors('messages.reminder.text');
        $bad(['messages' => ['reminder' => ['text' => 'Hola {{1}}']]])->assertSessionHasErrors('messages.reminder.text');
        $bad(['messages' => ['reminder' => ['text' => str_repeat('a', 1001)]]])->assertSessionHasErrors('messages.reminder.text');
        $bad(['messages' => ['reminder' => ['template' => ['name' => 'Recordatorio Cita', 'language' => 'es']]]])->assertSessionHasErrors('messages.reminder.template.name');
        $bad(['messages' => ['reminder' => ['template' => ['name' => 'recordatorio', 'language' => '']]]])->assertSessionHasErrors('messages.reminder.template.name');
        $bad(['messages' => ['reminder' => ['template' => ['name' => 'recordatorio', 'language' => 'es', 'params' => ['telefono']]]]])->assertSessionHasErrors('messages.reminder.template.params.0');
        $bad(['notify_integration_id' => $this->account($this->other)->id])->assertSessionHasErrors('notify_integration_id');
        $this->assertSame([1440, 120], $this->workspace->bookingSetting()->first()->offsets(), 'a refused form changes nothing');
    }

    public function test_it_cannot_be_turned_on_without_a_way_to_send_it_or_with_an_external_agenda(): void
    {
        $this->actAsMember($this->workspace);
        $on = ['notify_received' => false, 'notify_confirmed' => true, 'reminders_enabled' => false, 'reminder_offsets' => []];

        $this->whatsapp->update(['is_active' => false]);
        $this->put('/bookings/settings/notifications', $on)->assertSessionHasErrors('reminders_enabled');

        $this->whatsapp->update(['is_active' => true]);
        $this->account($this->workspace, 'Segundo', true, '444555666');
        $this->put('/bookings/settings/notifications', $on)->assertSessionHasErrors('reminders_enabled'); // two accounts and none chosen
        $this->put('/bookings/settings/notifications', $on + ['notify_integration_id' => $this->whatsapp->id])->assertSessionHasNoErrors();

        $this->contractModules($this->workspace, ['dashboard', 'service_catalog', 'professionals', 'bookings']); // no Integraciones
        $this->actAsMember($this->workspace);
        $this->put('/bookings/settings/notifications', ['notify_received' => true] + $on)->assertSessionHasErrors('reminders_enabled');

        // Switching OFF is always possible.
        $this->put('/bookings/settings/notifications', ['notify_received' => false, 'notify_confirmed' => false, 'reminders_enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->workspace->bookingSetting()->first()->sendsNotifications());

        $this->configure(['provider' => 'booker']);
        $this->put('/bookings/settings/notifications', $on)->assertSessionHasErrors('reminders_enabled');
    }

    public function test_the_screen_says_what_is_missing_and_only_managers_can_save(): void
    {
        $this->actAsMember($this->workspace);
        $this->whatsapp->update(['is_active' => false]);
        $this->get('/bookings')->assertInertia(fn ($page) => $page->where('settings.notifications.ready', true)->where('settings.notifications.canSend', false)->where('settings.notifications.problem', fn ($p) => str_contains($p, 'WhatsApp')));

        $this->whatsapp->update(['is_active' => true]);
        $this->get('/bookings')->assertInertia(fn ($page) => $page->where('settings.notifications.canSend', true)->where('settings.notifications.problem', null)->has('settings.notifications.variables', 7));

        $this->actAsMember($this->workspace, 'cliente');
        $this->put('/bookings/settings/notifications', ['notify_received' => false, 'notify_confirmed' => false, 'reminders_enabled' => false])->assertForbidden();
    }

    // --- receipt and confirmation --------------------------------------------------------------------------------------------------

    public function test_a_confirmed_booking_sends_the_confirmation_with_the_declared_template(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed', ['nombre', 'servicio', 'fecha', 'hora', 'sede'])]);

        $appointment = $this->book();

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('template', $sent[0]['type']);
        $this->assertSame('recordatorio_cita', $sent[0]['template']['name']);
        $this->assertSame(['code' => 'es'], $sent[0]['template']['language']);
        $this->assertSame('573001112233', $sent[0]['to']);
        $this->assertSame(['Laura', 'Consulta', '2026-10-13', '10:00', '-'], array_column($sent[0]['template']['components'][0]['parameters'], 'text'), 'an empty value (no location) is a dash: Meta refuses empty parameters');
        $notification = $this->notifications($appointment)->sole();
        $this->assertSame([AppointmentNotification::SENT, 'confirmed', 'template', 'wamid.OUT1'], [$notification->status, $notification->kind, $notification->mode, $notification->external_id]);
        $this->assertStringNotContainsString('TOKEN-SECRETO', json_encode($notification->toArray()));
    }

    public function test_a_booking_that_waits_for_acceptance_gets_a_receipt_and_the_confirmation_only_when_the_business_confirms(): void
    {
        $this->configure(['requires_confirmation' => true, 'notify_received' => true, 'notify_confirmed' => true, 'notify_messages' => $this->template('received', ['nombre']) + $this->template('confirmed', ['nombre'])]);
        $this->actAsMember($this->workspace);
        $key = $this->workspace->bookingSetting()->first()->ensurePublicKey();
        $this->workspace->bookingSetting()->update(['public_enabled' => true]);

        $this->postJson("/api/booking/{$key}/appointments", ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertCreated();
        $appointment = Appointment::firstOrFail();

        $this->assertSame(Appointment::REQUESTED, $appointment->status);
        $this->assertSame(['received'], $this->notifications()->pluck('kind')->all(), 'receiving a request is not confirming it');

        $this->post("/bookings/{$appointment->id}/status", ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->assertSame(['received', 'confirmed'], $this->notifications()->pluck('kind')->all());
        $this->assertCount(2, $this->sent());
    }

    public function test_free_text_goes_out_only_inside_the_24_hour_window_and_otherwise_nothing_is_invented(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => ['confirmed' => ['text' => 'Hola {{nombre}}: {{servicio}} el {{fecha}} a las {{hora}} en {{negocio}}.']]]);

        // No message from the contact and no template: skipped, never sent as free text.
        $first = $this->book();
        $this->assertSame([], $this->sent());
        $this->assertSame([AppointmentNotification::SKIPPED, 'needs_template'], [$this->notifications($first)->sole()->status, $this->notifications($first)->sole()->reason]);

        // The contact wrote an hour ago: free text is allowed.
        $conversation = $this->openWindow();
        $second = $this->book('2026-10-13T11:00:00Z');
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('text', $sent[0]['type']);
        $this->assertSame('Hola Laura: Consulta el 2026-10-13 a las 11:00 en Salón CLINICA.', $sent[0]['text']['body']);
        $this->assertSame('sent', $this->notifications($second)->sole()->status);

        // 25 hours of silence close the window again.
        $conversation->messages()->update(['created_at' => now()->subHours(25)]);
        $third = $this->book('2026-10-13T12:00:00Z');
        $this->assertSame('needs_template', $this->notifications($third)->sole()->reason);
        $this->assertCount(1, $this->sent());
    }

    // --- reminders ----------------------------------------------------------------------------------------------------------------

    public function test_a_reminder_goes_out_once_at_the_configured_time_even_if_the_scheduler_runs_twice(): void
    {
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => $this->template()]);
        $appointment = $this->book(); // Tuesday 10:00Z: the reminder is due Monday 10:00Z

        $this->tick();
        $this->assertSame([], $this->sent(), 'nothing is due at 08:00');
        $this->assertSame(0, AppointmentNotification::count());

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:30', 'UTC'));
        $this->tick();
        $this->tick();

        $this->assertCount(1, $this->sent());
        $notification = $this->notifications($appointment)->sole();
        $this->assertSame(['reminder', 1440, AppointmentNotification::SENT], [$notification->kind, $notification->offset_minutes, $notification->status]);
        $this->assertSame('2026-10-12 10:00:00', $notification->scheduled_for->utc()->toDateTimeString());
    }

    public function test_several_reminders_each_once_and_an_anticipation_that_already_passed_is_not_invented(): void
    {
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440, 120], 'notify_messages' => $this->template()]);
        $this->book('2026-10-13T10:00:00Z');
        $short = $this->book('2026-10-12T10:00:00Z'); // booked 2 h ahead: the "1 day before" moment is long gone

        $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:30', 'UTC'));
        $this->tick();
        $this->assertSame([120], $this->notifications($short)->pluck('offset_minutes')->all(), 'only the 2-hours one fits and it is due right now');
        $this->assertCount(1, $this->sent());

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:30', 'UTC'));
        $this->tick();
        $this->assertSame(1 + 1, count($this->sent()), 'the 1-day reminder of the Tuesday appointment; the Monday one already started and is never messaged');
        $this->travelTo(CarbonImmutable::parse('2026-10-13 08:00:30', 'UTC'));
        $this->tick();
        $this->assertCount(3, $this->sent(), 'and the 2-hours one of Tuesday');
    }

    public function test_moving_an_appointment_retires_the_old_reminders_and_plans_the_new_ones(): void
    {
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => $this->template()]);
        $appointment = $this->book();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:55:00', 'UTC'));
        $this->tick(); // planned (due 10:00) but not yet sent
        $this->assertSame([AppointmentNotification::SCHEDULED], $this->notifications()->pluck('status')->all());

        $this->actAsMember($this->workspace);
        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-14T10:00:00Z'])->assertSessionHas('success');
        $this->assertSame([AppointmentNotification::CANCELLED, 'rescheduled'], [$this->notifications()->first()->status, $this->notifications()->first()->reason]);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:30', 'UTC'));
        $this->tick();
        $this->assertSame([], $this->sent(), 'the old moment passes without a message');

        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:30', 'UTC'));
        $this->tick();
        $this->assertCount(1, $this->sent());
        $this->assertSame([1, 0], [$this->notifications()->last()->version, $this->notifications()->first()->version === 0 ? 0 : 1]);
        $this->assertSame('2026-10-13 10:00:00', $this->notifications()->last()->scheduled_for->utc()->toDateTimeString());
    }

    public function test_a_cancelled_or_closed_appointment_is_never_reminded_even_if_the_row_was_already_queued(): void
    {
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => $this->template()]);
        $appointment = $this->book();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:55:00', 'UTC'));
        $this->tick();
        $row = $this->notifications()->sole();

        // The cancellation reaches the database without going through Ava's own code (another process, a manual change): the send itself re-checks.
        DB::table('appointments')->where('id', $appointment->id)->update(['status' => 'cancelled']);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:30', 'UTC'));
        $this->tick();

        $this->assertSame([], $this->sent());
        $this->assertSame([AppointmentNotification::CANCELLED, 'appointment_closed'], [$row->fresh()->status, $row->fresh()->reason]);

        // And the normal path retires the plan at once.
        $second = $this->book('2026-10-13T11:00:00Z');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:55:00', 'UTC'));
        $this->tick();
        $this->assertSame([AppointmentNotification::SCHEDULED], $this->notifications($second)->pluck('status')->unique()->all());
        $this->actAsMember($this->workspace);
        $this->post("/bookings/{$second->id}/cancel", [])->assertSessionHas('success');
        $this->assertSame([AppointmentNotification::CANCELLED], $this->notifications($second)->pluck('status')->unique()->all());
    }

    public function test_nothing_is_sent_for_an_old_booking_an_external_agenda_a_foreign_workspace_or_a_switched_off_kind(): void
    {
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => $this->template()]);
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => $this->template()], $this->other);
        $this->other->integrations()->delete();

        // Booked before the international phone policy: its digits may be a local number read as a foreign one, so it is never messaged.
        $old = $this->book();
        DB::table('appointments')->where('id', $old->id)->update(['created_at' => '2025-12-01 00:00:00']);
        // An appointment of an external agenda.
        $external = $this->book('2026-10-13T11:00:00Z');
        $external->forceFill(['provider' => 'booker'])->save();
        // Another Workspace whose WhatsApp account is missing.
        $this->makeProfessional($this->other, [$this->makeService($this->other)], 'Ana');
        $foreign = $this->book('2026-10-13T10:00:00Z', '+573009998888', 'admin', $this->other);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:30', 'UTC'));
        $this->tick();

        $this->assertSame([], $this->sent());
        $this->assertSame(0, $this->notifications($old)->count());
        $this->assertSame(0, $this->notifications($external)->count());
        $this->assertSame(['channel_no_whatsapp'], $this->notifications($foreign)->pluck('reason')->all());
        $this->assertSame($this->other->id, $this->notifications($foreign)->first()->workspace_id);

        // Switch the reminders off after they were planned: they are retired, not sent.
        $fresh = $this->book('2026-10-13T11:00:00Z', '+573001112244');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:55:00', 'UTC'));
        $this->tick();
        $this->assertSame([AppointmentNotification::SCHEDULED], $this->notifications($fresh)->pluck('status')->all());
        $this->configure(['reminders_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 11:00:30', 'UTC'));
        $this->tick();
        $this->assertSame([], $this->sent());
        $this->assertSame([AppointmentNotification::CANCELLED, 'disabled'], [$this->notifications($fresh)->first()->status, $this->notifications($fresh)->first()->reason]);
    }

    // --- providers: failures, retries, uncertainty -----------------------------------------------------------------------------------

    public function test_a_failure_that_sent_nothing_is_retried_a_limited_number_of_times_and_then_recorded(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $this->meta(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $appointment = $this->book();
        $row = $this->notifications($appointment)->sole();
        $this->assertSame([AppointmentNotification::SCHEDULED, 1], [$row->status, $row->attempts], 'planned again for later');
        $this->assertTrue($row->scheduled_for->gt(now()));

        foreach ([2, 3] as $attempt) {
            $this->travelTo($row->fresh()->scheduled_for->addSecond());
            $this->tick();
            $row = $row->fresh();
            $this->assertSame($attempt, $row->attempts);
        }

        $this->assertSame(AppointmentNotification::FAILED, $row->status);
        $this->assertSame('No se pudo conectar con Meta.', $row->reason);
        $this->tick();
        $this->assertSame(3, $row->fresh()->attempts, 'a failed message is not tried again');
    }

    public function test_an_answer_that_may_have_been_lost_is_uncertain_and_never_sent_again(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $this->meta(Http::response('error', 500));

        $appointment = $this->book();
        $row = $this->notifications($appointment)->sole();
        $this->assertSame(AppointmentNotification::UNCERTAIN, $row->status);
        $this->assertSame(1, $row->attempts);

        $this->travelTo(now()->addHours(2));
        $this->tick();
        $this->app->make(AppointmentNotifier::class)->send($row->id);
        $this->assertCount(1, $this->sent(), 'one request ever');
        $this->assertSame(1, $row->fresh()->attempts);

        // Meta later echoes Ava's own tag: the message takes Meta's id and state, nobody resends it.
        $this->assertTrue(app(AppointmentNotifier::class)->applyStatus($this->workspace->id, ['id' => 'wamid.LATE', 'status' => 'delivered', 'biz_opaque_callback_data' => "avn:{$row->id}"]));
        $this->assertSame(['wamid.LATE', AppointmentNotification::DELIVERED], [$row->fresh()->external_id, $row->fresh()->status]);
    }

    public function test_a_template_meta_does_not_have_is_recorded_as_failed_with_a_fixed_reason(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $this->meta(Http::response(['error' => ['code' => 132001, 'message' => 'Template name does not exist TOKEN-SECRETO 573001112233']], 404));

        $row = $this->notifications($this->book())->sole();

        $this->assertSame(AppointmentNotification::FAILED, $row->status);
        $this->assertSame('Meta no tiene esa plantilla aprobada en ese idioma: revisa el nombre y el idioma.', $row->reason);
        $this->assertSame(1, $row->attempts, 'a refusal is not retried');
        $this->assertStringNotContainsString('573001112233', (string) $row->reason);
    }

    public function test_the_rate_limit_is_retried_with_backoff(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $this->meta(Http::response(['error' => ['code' => 130429]], 429));

        $row = $this->notifications($this->book())->sole();

        $this->assertSame([AppointmentNotification::SCHEDULED, 1], [$row->status, $row->attempts]);
        $this->assertSame(60, (int) now()->diffInSeconds($row->scheduled_for));
    }

    public function test_delivery_states_only_move_forward_and_only_inside_the_workspace(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $row = $this->notifications($this->book())->sole();
        $notifier = app(AppointmentNotifier::class);

        $id = $row->fresh()->external_id;
        $this->assertSame('wamid.OUT1', $id);
        $this->assertTrue($notifier->applyStatus($this->workspace->id, ['id' => 'wamid.OUT1', 'status' => 'read']));
        $this->assertSame(AppointmentNotification::READ, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->delivered_at);

        $notifier->applyStatus($this->workspace->id, ['id' => 'wamid.OUT1', 'status' => 'delivered']);
        $notifier->applyStatus($this->workspace->id, ['id' => 'wamid.OUT1', 'status' => 'failed']);
        $this->assertSame(AppointmentNotification::READ, $row->fresh()->status, 'a late event never undoes a read');

        $this->assertFalse($notifier->applyStatus($this->other->id, ['id' => 'wamid.OUT1', 'status' => 'failed']), 'a status of another Workspace touches nothing');
        $this->assertFalse($notifier->applyStatus($this->workspace->id, ['id' => 'wamid.NOPE', 'status' => 'read']));
        $this->assertFalse($notifier->applyStatus($this->workspace->id, ['id' => 'wamid.OUT1', 'status' => 'exploded']));
    }

    /** Posts a signed WhatsApp status event to the real webhook of the platform (Meta's signature, as the readiness tests do). */
    private function statusEvent(string $phoneNumberId, string $wamid, string $state, ?string $tag = null)
    {
        config(['services.meta.app_secret' => 'meta-app-SECRET', 'services.meta.webhook_verify_token' => 'verify-me']);
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'statuses' => [['id' => $wamid, 'recipient_id' => '573001112233', 'status' => $state] + ($tag ? ['biz_opaque_callback_data' => $tag] : [])],
        ]]]]]];
        $raw = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-app-SECRET')], $raw);
    }

    public function test_meta_status_events_through_the_real_webhook_update_the_notification_of_that_workspace_only(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        // The number is linked to a chatbot channel, as in a real deployment: that is how the webhook knows which Workspace an event is for.
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'x']);
        $bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'integration_id' => $this->whatsapp->id, 'is_active' => true]);
        $row = $this->notifications($this->book())->sole();
        $wamid = $row->fresh()->external_id;

        $this->statusEvent('111222333', $wamid, 'delivered')->assertOk();
        $this->assertSame(AppointmentNotification::DELIVERED, $row->fresh()->status);
        $this->statusEvent('111222333', $wamid, 'read')->assertOk();
        $this->statusEvent('111222333', $wamid, 'delivered')->assertOk();
        $this->assertSame(AppointmentNotification::READ, $row->fresh()->status, 'a late event never undoes a read');

        // The same wamid announced by the number of ANOTHER Workspace touches nothing here.
        $otherAccount = $this->account($this->other, 'WhatsApp', true, '999888777');
        $otherBot = $this->other->chatbots()->create(['name' => 'Otro', 'instructions' => 'x']);
        $otherBot->channels()->create(['workspace_id' => $this->other->id, 'channel' => 'whatsapp', 'integration_id' => $otherAccount->id, 'is_active' => true]);
        DB::table('appointment_notifications')->where('id', $row->id)->update(['status' => 'sent', 'read_at' => null, 'delivered_at' => null]);
        $this->statusEvent('999888777', $wamid, 'failed')->assertOk();
        $this->assertSame(AppointmentNotification::SENT, $row->fresh()->status);
    }

    public function test_an_uncertain_message_is_recognized_by_its_tag_when_meta_reports_it(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'x']);
        $bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'integration_id' => $this->whatsapp->id, 'is_active' => true]);
        $this->meta(Http::response('error', 500));
        $row = $this->notifications($this->book())->sole();
        $this->assertSame(AppointmentNotification::UNCERTAIN, $row->status);

        $this->statusEvent('111222333', 'wamid.FROMTAG', 'delivered', "avn:{$row->id}")->assertOk();

        $this->assertSame(['wamid.FROMTAG', AppointmentNotification::DELIVERED], [$row->fresh()->external_id, $row->fresh()->status]);
        $this->assertCount(1, $this->sent(), 'recognizing it never resends it');
    }

    // --- idempotency and concurrency ---------------------------------------------------------------------------------------------------

    public function test_the_same_message_is_planned_once_and_sent_once_whatever_runs_twice(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $appointment = $this->book();
        $notifier = app(AppointmentNotifier::class);
        $row = $this->notifications($appointment)->sole();

        $this->assertSame(0, $notifier->plan($appointment, 'confirmed', 0, now()), 'the plan already exists');
        $this->expectException(QueryException::class);
        try {
            DB::table('appointment_notifications')->insert(['workspace_id' => $appointment->workspace_id, 'appointment_id' => $appointment->id, 'kind' => 'confirmed', 'offset_minutes' => 0, 'version' => 0, 'channel' => 'whatsapp', 'scheduled_for' => now(), 'status' => 'scheduled', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            $notifier->send($row->id);
            $notifier->send($row->id);
            $this->assertCount(1, $this->sent());
        }
    }

    public function test_work_left_half_done_by_a_crash_is_put_right_without_resending(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $appointment = $this->book();
        $queued = $this->notifications($appointment)->sole();
        DB::table('appointment_notifications')->where('id', $queued->id)->update(['status' => 'queued', 'external_id' => null, 'updated_at' => now()->subMinutes(20)]);

        $stuckSending = $this->book('2026-10-13T11:00:00Z');
        $sending = $this->notifications($stuckSending)->sole();
        DB::table('appointment_notifications')->where('id', $sending->id)->update(['status' => 'sending', 'external_id' => null, 'updated_at' => now()->subMinutes(20)]);

        $result = app(AppointmentNotifier::class)->run();

        $this->assertSame(2, $result['recovered']);
        $this->assertSame(AppointmentNotification::UNCERTAIN, $sending->fresh()->status, 'it may have left: never resent');
        $this->assertNotSame(AppointmentNotification::QUEUED, $queued->fresh()->status, 'what never started goes back to waiting and is sent');
    }

    // --- recipients ----------------------------------------------------------------------------------------------------------------------

    public function test_a_phone_that_is_not_a_real_international_number_is_skipped(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $appointment = $this->book();
        $appointment->customer->update(['phone' => '12345']);
        $row = $this->notifications($appointment)->sole();
        $row->update(['status' => 'scheduled']);

        app(AppointmentNotifier::class)->send($row->id);

        $this->assertSame([AppointmentNotification::SKIPPED, 'no_valid_phone'], [$row->fresh()->status, $row->fresh()->reason]);
    }

    public function test_the_workspaces_timezone_decides_what_the_customer_reads_across_a_dst_change(): void
    {
        $this->workspace->settingsOrDefault()->update(['timezone' => 'America/New_York']);
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed', ['fecha', 'hora'])]);
        $this->workspace->professionals()->first()->scheduleRules()->delete();
        // 2026-11-01 02:00 New York turns back an hour: this Sunday the day is 25 hours long. The professional works 09:00-17:00 local.
        $this->workspace->professionals()->first()->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 7, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        $this->workspace->bookingSetting()->updateOrCreate([], ['max_advance_days' => 60]);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));

        $this->book('2026-11-01T14:00:00Z'); // 09:00 EST (UTC-5) after the change
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $this->book('2026-10-25T13:00:00Z'); // a Sunday before the change: 09:00 EDT (UTC-4)

        $texts = collect($this->sent())->map(fn ($body) => array_column($body['template']['components'][0]['parameters'], 'text'))->all();
        $this->assertSame([['2026-11-01', '09:00'], ['2026-10-25', '09:00']], $texts, 'both are 9 in the morning for the customer although their UTC hours differ');
    }

    // --- the customer's own answer -----------------------------------------------------------------------------------------------------

    /** @return array{Chatbot, string, Conversation} */
    private function assistant(?Workspace $workspace = null, string $contact = '573001112233'): array
    {
        $workspace ??= $this->workspace;
        $bot = $workspace->chatbots()->firstOrCreate(['name' => 'Asistente'], ['instructions' => 'x']);
        $conversation = $bot->conversations()->firstOrCreate(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'contact_id' => $contact], ['contact_name' => 'Laura']);

        return [$bot, app(AgentAccess::class)->generate($bot), $conversation];
    }

    private function confirmCall(string $token, int $appointmentId, string $contact = '573001112233')
    {
        return $this->withToken($token)->postJson("/api/agent/booking/appointments/{$appointmentId}/confirm", ['channel' => 'whatsapp', 'contact_id' => $contact]);
    }

    public function test_the_assistant_records_the_customers_confirmation_without_confirming_the_booking(): void
    {
        $this->configure(['requires_confirmation' => true]);
        [, $token, $conversation] = $this->assistant();
        $appointment = $this->book('2026-10-13T10:00:00Z', '+573001112233', 'chat', null, $conversation->id);
        $this->assertSame(Appointment::REQUESTED, $appointment->status);

        $this->confirmCall($token, $appointment->id)->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('appointment.customerConfirmed', true)->assertJsonPath('appointment.status', 'requested');
        $this->confirmCall($token, $appointment->id)->assertOk()->assertJsonPath('replayed', true);

        $fresh = $appointment->fresh();
        $this->assertSame(Appointment::REQUESTED, $fresh->status, 'the customer answering never accepts the booking for the business');
        $this->assertSame('assistant', $fresh->customer_confirmation_source);
        $this->assertNotNull($fresh->customer_confirmed_at);
    }

    public function test_a_late_or_incompatible_confirmation_is_refused_and_revives_nothing(): void
    {
        [, $token, $conversation] = $this->assistant();
        $cancelled = $this->book('2026-10-13T10:00:00Z', '+573001112233', 'chat', null, $conversation->id);
        $this->actAsMember($this->workspace);
        $this->post("/bookings/{$cancelled->id}/cancel", [])->assertSessionHas('success');

        $this->confirmCall($token, $cancelled->id)->assertStatus(409)->assertJsonPath('reason', 'state');
        $this->assertNull($cancelled->fresh()->customer_confirmed_at);
        $this->assertSame(Appointment::CANCELLED, $cancelled->fresh()->status);

        $soon = $this->book('2026-10-13T11:00:00Z', '+573001112233', 'chat', null, $conversation->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 11:05:00', 'UTC'));
        $this->confirmCall($token, $soon->id)->assertStatus(409);
    }

    public function test_moving_the_appointment_clears_the_customers_confirmation(): void
    {
        [, $token, $conversation] = $this->assistant();
        $appointment = $this->book('2026-10-13T10:00:00Z', '+573001112233', 'chat', null, $conversation->id);
        $this->confirmCall($token, $appointment->id)->assertOk();

        $this->actAsMember($this->workspace);
        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T12:00:00Z'])->assertSessionHas('success');

        $this->assertNull($appointment->fresh()->customer_confirmed_at, 'an answer about another time does not carry over');
        $this->confirmCall($token, $appointment->id)->assertOk()->assertJsonPath('replayed', false);
    }

    public function test_only_the_owner_conversation_and_workspace_can_confirm(): void
    {
        [, $token, $conversation] = $this->assistant();
        $appointment = $this->book('2026-10-13T10:00:00Z', '+573001112233', 'chat', null, $conversation->id);
        [, $otherToken, $otherConversation] = $this->assistant($this->other, '573009998888');
        $this->contractModules($this->other, self::MODULES);

        $this->confirmCall($otherToken, $appointment->id, '573009998888')->assertNotFound();
        $this->assertNull($appointment->fresh()->customer_confirmed_at);

        // Another contact of the SAME chatbot, naming the appointment of someone else.
        $this->workspace->chatbots()->first()->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573007776666', 'contact_name' => 'Otra']);
        $this->confirmCall($token, $appointment->id, '573007776666')->assertNotFound();
        $this->assertNull($appointment->fresh()->customer_confirmed_at);
        $this->assertNotNull($otherConversation->id);
    }

    public function test_the_customer_confirms_with_their_own_link_and_staff_can_register_it(): void
    {
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();
        $token = $this->postJson("/api/booking/{$key}/appointments", ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertCreated()->json('token');

        $this->getJson("/api/booking/{$key}/appointments/{$token}")->assertJsonPath('appointment.canConfirm', true)->assertJsonPath('appointment.customerConfirmed', false);
        $this->postJson("/api/booking/{$key}/appointments/{$token}/confirm")->assertOk()->assertJsonPath('appointment.customerConfirmed', true)->assertJsonPath('appointment.canConfirm', false)->assertJsonPath('replayed', false);
        $this->postJson("/api/booking/{$key}/appointments/{$token}/confirm")->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame('link', Appointment::firstOrFail()->customer_confirmation_source);

        $this->postJson("/api/booking/{$key}/appointments/{$token}/cancel")->assertOk();
        $this->postJson("/api/booking/{$key}/appointments/{$token}/confirm")->assertStatus(409);

        $staff = $this->book('2026-10-13T12:00:00Z', '+573001112255');
        $this->actAsMember($this->workspace);
        $this->post("/bookings/{$staff->id}/customer-confirmation")->assertSessionHas('success');
        $this->assertSame('staff', $staff->fresh()->customer_confirmation_source);
        $this->get('/bookings?from=2026-10-13&days=1')->assertInertia(fn ($page) => $page->where('appointments.1.customerConfirmed', true)->where('appointments.1.confirmationSource', 'staff'));

        $this->actAsMember($this->workspace, 'cliente');
        $this->post("/bookings/{$staff->id}/customer-confirmation")->assertForbidden();
    }

    public function test_the_assistant_and_the_agenda_see_the_state_of_each_message_without_text_or_phone(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        [, $token, $conversation] = $this->assistant();
        $appointment = $this->book('2026-10-13T10:00:00Z', '+573001112233', 'chat', null, $conversation->id);

        $listed = $this->withToken($token)->getJson('/api/agent/booking/appointments?channel=whatsapp&contact_id=573001112233')->assertOk();
        $this->assertSame([['kind' => 'confirmed', 'offset' => 0, 'status' => 'sent', 'statusLabel' => 'Aceptado por WhatsApp', 'scheduledFor' => now()->utc()->toIso8601ZuluString()]], $listed->json('appointments.0.notifications'));
        $this->assertStringNotContainsString('573001112233', json_encode($listed->json('appointments.0.notifications')));

        $this->actAsMember($this->workspace);
        $this->get('/bookings?from=2026-10-13&days=1')->assertInertia(fn ($page) => $page->where('appointments.0.notifications.0.status', 'sent')->where('appointments.0.notifications.0.mode', 'template'));
        $this->assertNotNull($appointment->id);
    }

    // --- operation ------------------------------------------------------------------------------------------------------------------------------

    public function test_the_scheduler_runs_the_command_every_minute_and_the_commands_list_and_diagnose(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'appointments:notify'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $this->book();
        Artisan::call('appointments:notifications');
        $listing = Artisan::output();
        $this->assertStringContainsString('confirmed', $listing);
        $this->assertStringNotContainsString('573001112233', $listing);
        $this->assertStringNotContainsString('Laura', $listing);

        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('Confirmaciones y recordatorios de citas', Artisan::output());

        $this->configure(['reminders_enabled' => true], $this->other);
        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString("Workspace {$this->other->id}: tiene avisos activos pero no puede enviarlos (no_whatsapp)", Artisan::output());
    }

    public function test_a_failing_workspace_never_stops_the_others_and_the_command_reports_it_then_recovers_without_duplicates(): void
    {
        $service = $this->makeService($this->other, ['name' => 'Corte', 'duration_minutes' => 30]);
        $this->makeProfessional($this->other, [$service], 'Ana');
        $this->account($this->other, 'WhatsApp', true, '444555666');
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => $this->template()]);
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => $this->template()], $this->other);
        $clinic = $this->book();
        $salon = $this->book('2026-10-13T10:00:00Z', '+573009998877', 'admin', $this->other);

        // The data of ONE Workspace makes its step fail (as a corrupt row or a bug could): the other Workspace must still get its reminder.
        $broken = true;
        Appointment::retrieved(function (Appointment $appointment) use (&$broken) {
            if ($broken && $appointment->workspace_id === $this->workspace->id) {
                throw new \RuntimeException('corrupt row');
            }
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:30', 'UTC'));

        $this->assertSame(1, Artisan::call('appointments:notify'), 'a step failed: the scheduler must see an error');
        $this->assertStringContainsString('Pasos con error: 1', Artisan::output());
        $this->assertCount(1, $this->sent(), 'the other Workspace was not held back by it');
        $this->assertSame(['sent'], $this->notifications($salon)->pluck('status')->all());
        $this->assertSame([], $this->notifications($clinic)->all(), 'the failed Workspace planned nothing: no half-written state');

        // The cause goes away: the next tick plans and sends the missed reminder once, and never repeats the one already sent.
        $broken = false;
        $this->assertSame(0, Artisan::call('appointments:notify'));
        $this->assertSame(0, Artisan::call('appointments:notify'));
        $this->assertCount(2, $this->sent());
        $this->assertSame(['sent'], $this->notifications($clinic)->pluck('status')->all());
        $this->assertSame(1, $this->notifications($salon)->count());
    }

    public function test_the_database_keeps_the_notification_history_and_refuses_cross_workspace_links(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $appointment = $this->book();

        try {
            DB::table('appointment_notifications')->insert(['workspace_id' => $this->other->id, 'appointment_id' => $appointment->id, 'kind' => 'reminder', 'offset_minutes' => 60, 'version' => 0, 'channel' => 'whatsapp', 'scheduled_for' => now(), 'status' => 'scheduled', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('A notification of another Workspace was linked to this appointment.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('appointments')->where('id', $appointment->id)->delete();
            $this->fail('An appointment with notifications was deleted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_before_the_migration_nothing_breaks_and_nothing_is_sent(): void
    {
        $this->configure(['notify_confirmed' => true]);
        (require database_path('migrations/2026_10_19_100000_create_appointment_notifications.php'))->down();
        DB::table('migrations')->where('migration', '2026_10_19_100000_create_appointment_notifications')->delete();

        $this->assertNotNull($this->book(), 'booking still works');
        Artisan::call('appointments:notify');
        $this->assertSame([], $this->sent());

        $this->actAsMember($this->workspace);
        $this->get('/bookings')->assertOk()->assertInertia(fn ($page) => $page->where('settings.notifications.ready', false)->where('settings.notifications.canSend', false));
        $this->put('/bookings/settings/notifications', ['notify_received' => false, 'notify_confirmed' => false, 'reminders_enabled' => false])->assertSessionHas('error');

        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('migración(es) pendiente(s)', Artisan::output());
    }

    public function test_the_migration_keeps_every_existing_booking_and_setting(): void
    {
        $this->configure(['requires_confirmation' => true, 'min_notice_minutes' => 30]);
        $appointment = $this->book();
        $migration = require database_path('migrations/2026_10_19_100000_create_appointment_notifications.php');

        $migration->down();
        $migration->up();

        $kept = Appointment::findOrFail($appointment->id);
        $this->assertSame(['Consulta', null], [$kept->service_name, $kept->customer_confirmed_at]);
        $setting = $this->workspace->bookingSetting()->first();
        $this->assertSame([true, 30, false, false, false, null], [$setting->requires_confirmation, $setting->min_notice_minutes, $setting->notify_received, $setting->notify_confirmed, $setting->reminders_enabled, $setting->reminder_offsets]);
        $this->assertSame(0, AppointmentNotification::count(), 'nothing is planned or invented for what already exists');
    }

    public function test_a_linked_card_decides_the_number_and_an_anonymized_one_stops_the_message_while_an_unlinked_appointment_keeps_its_own(): void
    {
        $this->configure(['notify_confirmed' => true, 'notify_messages' => $this->template('confirmed')]);
        $card = $this->workspace->customers()->create(['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+573009990000', 'email' => 'l@example.com', 'marketing_consent' => 'unknown']);

        // Linked: the card's number (corrected after the booking) is where it goes.
        $linked = $this->book();
        $linked->customer->forceFill(['customer_id' => $card->id])->save();
        $row = $this->notifications($linked)->sole();
        $row->update(['status' => 'scheduled']);
        $this->openWindow('573009990000');
        $before = count($this->sent()); // booking by the team already planned and sent one: only what this explicit send adds is measured
        app(AppointmentNotifier::class)->send($row->id);
        $this->assertSame('573009990000', $this->sent()[$before]['to'], 'the central card wins over the number the appointment was booked with');

        // Not linked (every older appointment): exactly as before, with the number it was booked with.
        $unlinked = $this->book('2026-10-13T12:00:00Z', '+573005550000');
        $row2 = $this->notifications($unlinked)->sole();
        $row2->update(['status' => 'scheduled']);
        $this->openWindow('573005550000');
        $before = count($this->sent());
        app(AppointmentNotifier::class)->send($row2->id);
        $this->assertSame('573005550000', $this->sent()[$before]['to']);

        // Anonymized card: the person was erased, nothing goes to the number kept in the old appointment.
        $erased = $this->book('2026-10-13T14:00:00Z', '+573001112233');
        $erased->customer->forceFill(['customer_id' => $card->id])->save();
        $card->forceFill(['anonymized_at' => now()])->save();
        $row3 = $this->notifications($erased)->sole();
        $row3->update(['status' => 'scheduled']);
        $before = count($this->sent());
        app(AppointmentNotifier::class)->send($row3->id);
        $this->assertSame([AppointmentNotification::SKIPPED, 'customer_anonymized'], [$row3->fresh()->status, $row3->fresh()->reason]);
        $this->assertCount($before, $this->sent(), 'nothing new went out');
    }
    // --- the buttons of a reminder: Confirmar, Reprogramar, Cancelar ----------------------------------------------------------------------

    /** A reminder with buttons, already sent through the fake Meta; returns [appointment, notification]. @return array{0: Appointment, 1: AppointmentNotification} */
    private function reminderWithButtons(bool $buttons = true): array
    {
        $template = ['name' => 'recordatorio_cita', 'language' => 'es', 'params' => ['nombre', 'fecha']] + ($buttons ? ['buttons' => true] : []);
        $this->configure(['reminders_enabled' => true, 'reminder_offsets' => [1440], 'notify_messages' => ['reminder' => ['template' => $template]]]);
        $bot = $this->workspace->chatbots()->firstOrCreate(['name' => 'Asistente'], ['instructions' => 'x']);
        $bot->channels()->firstOrCreate(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp'], ['integration_id' => $this->whatsapp->id, 'is_active' => true]);
        $appointment = $this->book();
        $this->assertSame(1, app(AppointmentNotifier::class)->plan($appointment, 'reminder', 1440, now()));
        $notification = $this->notifications($appointment)->sole();
        app(AppointmentNotifier::class)->send($notification->id);

        return [$appointment->fresh(), $notification->fresh()];
    }

    /** What the customer's phone sends to Meta's webhook when a button of the template is pressed, signed like the real thing. */
    private function pressButton(string $payload, string $wamid, string $from = '573001112233', string $phoneNumberId = '111222333', string $kind = 'button')
    {
        config(['services.meta.app_secret' => 'meta-app-SECRET', 'services.meta.webhook_verify_token' => 'verify-me']);
        $message = ['from' => $from, 'id' => $wamid, 'timestamp' => (string) now()->timestamp] + ($kind === 'button'
            ? ['type' => 'button', 'button' => ['payload' => $payload, 'text' => 'Botón']]
            : ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $payload, 'title' => 'Botón']]]);
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $phoneNumberId], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Laura']]], 'messages' => [$message]]]]]]]);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-app-SECRET')], $raw);
    }

    public function test_a_reminder_template_with_buttons_carries_three_signed_quick_replies(): void
    {
        [, $notification] = $this->reminderWithButtons();
        $body = $this->sent()[0];
        $buttons = collect($body['template']['components'])->where('type', 'button')->values();

        $this->assertCount(3, $buttons);
        $this->assertSame(['0', '1', '2'], $buttons->pluck('index')->all());
        $this->assertSame(['quick_reply'], $buttons->pluck('sub_type')->unique()->all());
        $payloads = $buttons->map(fn ($b) => $b['parameters'][0]['payload'])->all();
        $this->assertSame(['confirm', 'reschedule', 'cancel'], array_map(fn ($p) => AppointmentReplies::parse($p)['action'], $payloads));
        $this->assertSame([$notification->id], array_unique(array_map(fn ($p) => AppointmentReplies::parse($p)['notification'], $payloads)));
        $this->assertNull(AppointmentReplies::parse(substr($payloads[0], 0, -1).(str_ends_with($payloads[0], '0') ? '1' : '0')), 'an edited payload is not valid');
        $this->assertNull(AppointmentReplies::parse('avr:c:'.($notification->id + 1).':'.explode(':', $payloads[0])[3]), 'a signature only fits its own notification');
    }

    public function test_a_reminder_without_the_buttons_flag_sends_no_buttons(): void
    {
        $this->reminderWithButtons(false);

        $this->assertSame([], collect($this->sent()[0]['template']['components'])->where('type', 'button')->all());
    }

    public function test_confirm_registers_the_answer_once_even_if_meta_repeats_the_event(): void
    {
        [$appointment, $notification] = $this->reminderWithButtons();
        $payload = AppointmentReplies::payload($notification->id, 'confirm');

        $this->pressButton($payload, 'wamid.BTN1')->assertOk();
        $this->pressButton($payload, 'wamid.BTN1')->assertOk(); // Meta retries
        $this->pressButton($payload, 'wamid.BTN2')->assertOk(); // the customer taps again on another message

        $fresh = $appointment->fresh();
        $this->assertNotNull($fresh->customer_confirmed_at);
        $this->assertSame('whatsapp', $fresh->customer_confirmation_source);
        $this->assertSame(Appointment::CONFIRMED, $fresh->status);
        $this->assertSame([['confirm', 'applied'], ['confirm', 'already_done']], AppointmentReply::orderBy('id')->get()->map(fn ($r) => [$r->action, $r->result])->all());

        $conversation = Conversation::where('workspace_id', $this->workspace->id)->where('contact_id', '573001112233')->sole();
        $this->assertSame(2, $conversation->messages()->where('direction', 'in')->count(), 'the two real taps; the retry is not stored twice');
        $this->assertStringContainsString('Confirmo mi cita de Consulta', $conversation->messages()->where('external_id', 'wamid.BTN1')->value('body'));
        $this->assertSame('Confirmó · Aplicada', app(AppointmentNotifier::class)->summary($appointment->fresh()->load('notifications.replies'), true)[0]['answer']);
    }

    public function test_cancel_closes_the_appointment_and_later_buttons_find_it_closed(): void
    {
        [$appointment, $notification] = $this->reminderWithButtons();

        $this->pressButton(AppointmentReplies::payload($notification->id, 'cancel'), 'wamid.BTN1')->assertOk();
        $this->assertSame(Appointment::CANCELLED, $appointment->fresh()->status);
        $this->assertNotNull($appointment->fresh()->cancelled_at);

        $this->pressButton(AppointmentReplies::payload($notification->id, 'confirm'), 'wamid.BTN2')->assertOk();
        $this->assertSame(Appointment::CANCELLED, $appointment->fresh()->status, 'a confirmation never revives a cancelled appointment');
        $this->assertNull($appointment->fresh()->customer_confirmed_at);
        $this->assertSame(['applied', 'closed'], AppointmentReply::orderBy('id')->pluck('result')->all());
    }

    public function test_reschedule_changes_nothing_in_the_agenda_but_ties_the_appointment_to_the_conversation_for_the_ai(): void
    {
        [$appointment, $notification] = $this->reminderWithButtons();
        $this->assertNull($appointment->conversation_id);

        $this->pressButton(AppointmentReplies::payload($notification->id, 'reschedule'), 'wamid.BTN1', kind: 'interactive')->assertOk();

        $fresh = $appointment->fresh();
        $conversation = Conversation::where('workspace_id', $this->workspace->id)->where('contact_id', '573001112233')->sole();
        $this->assertSame($conversation->id, $fresh->conversation_id, 'the booking tools only see the appointments of the conversation');
        $this->assertSame(Appointment::CONFIRMED, $fresh->status);
        $this->assertEquals($appointment->starts_at, $fresh->starts_at);
        $this->assertSame('reschedule', AppointmentReply::sole()->action);
        $this->assertStringContainsString('Quiero reprogramar mi cita de Consulta', $conversation->messages()->sole()->body);
    }

    public function test_an_answer_from_another_number_or_a_forged_or_foreign_payload_changes_nothing(): void
    {
        [$appointment, $notification] = $this->reminderWithButtons();

        $this->pressButton(AppointmentReplies::payload($notification->id, 'cancel'), 'wamid.BTN1', from: '573009998877')->assertOk();
        $this->assertSame(Appointment::CONFIRMED, $appointment->fresh()->status);
        $this->assertSame('not_authorized', AppointmentReply::sole()->result);

        $this->pressButton('avr:x:'.$notification->id.':'.str_repeat('a', 24), 'wamid.BTN2')->assertOk();
        $this->assertSame(1, AppointmentReply::count(), 'a forged payload is not even recorded');

        // A notification of ANOTHER Workspace, signed by Ava, pressed through this Workspace's number: not found here.
        $this->makeService($this->other, ['name' => 'Corte', 'duration_minutes' => 30]);
        $this->makeProfessional($this->other, [$this->other->services()->firstOrFail()], 'Otro');
        $foreign = $this->book('2026-10-13T10:00:00Z', '+573001112233', 'admin', $this->other);
        $row = AppointmentNotification::create(['workspace_id' => $this->other->id, 'appointment_id' => $foreign->id, 'kind' => 'reminder', 'offset_minutes' => 60, 'version' => 0, 'channel' => 'whatsapp', 'scheduled_for' => now(), 'status' => 'sent', 'attempts' => 1]);
        $this->pressButton(AppointmentReplies::payload($row->id, 'cancel'), 'wamid.BTN3')->assertOk();
        $this->assertSame(Appointment::CONFIRMED, $foreign->fresh()->status);
        $this->assertSame(1, AppointmentReply::count());
    }

    public function test_a_button_of_an_old_version_of_the_appointment_is_expired(): void
    {
        [$appointment, $notification] = $this->reminderWithButtons();
        $appointment->forceFill(['reschedule_count' => 1])->save(); // moved after the reminder went out

        $this->pressButton(AppointmentReplies::payload($notification->id, 'confirm'), 'wamid.BTN1')->assertOk();

        $this->assertNull($appointment->fresh()->customer_confirmed_at);
        $this->assertSame('expired', AppointmentReply::sole()->result);
    }

    public function test_a_button_that_is_not_ours_is_just_a_message_of_the_contact(): void
    {
        $this->reminderWithButtons();

        $this->pressButton('promo-si', 'wamid.BTN1')->assertOk();

        $this->assertSame(0, AppointmentReply::count());
        $this->assertSame('Botón', Conversation::where('contact_id', '573001112233')->sole()->messages()->sole()->body);
    }

    public function test_confirm_and_cancel_get_one_acknowledgement_each_and_a_repeated_event_gets_none(): void
    {
        [$appointment, $notification] = $this->reminderWithButtons();
        $sentBefore = count($this->sent());
        $ack = fn () => Conversation::where('contact_id', '573001112233')->firstOrFail()->messages()->where('sender', 'system')->orderBy('id')->get();

        $this->pressButton(AppointmentReplies::payload($notification->id, 'confirm'), 'wamid.ACK1')->assertOk();
        $this->pressButton(AppointmentReplies::payload($notification->id, 'confirm'), 'wamid.ACK1')->assertOk(); // Meta retries
        $this->assertCount(1, $ack());
        $this->assertStringContainsString('Quedó confirmada', $ack()[0]->body);
        $this->assertSame('sent', $ack()[0]->status);
        $this->assertCount($sentBefore + 1, $this->sent(), 'one message left for Meta, not two');

        $this->pressButton(AppointmentReplies::payload($notification->id, 'cancel'), 'wamid.ACK2')->assertOk();
        $this->assertCount(2, $ack());
        $this->assertStringContainsString('cancelamos', $ack()[1]->body);

        // The appointment is closed now: another tap is answered, a sender that is not the customer is not.
        $this->pressButton(AppointmentReplies::payload($notification->id, 'confirm'), 'wamid.ACK3')->assertOk();
        $this->assertStringContainsString('ya no está activa', $ack()[2]->body);
        $this->pressButton(AppointmentReplies::payload($notification->id, 'confirm'), 'wamid.ACK4', from: '573009998877')->assertOk();
        $this->assertCount(3, $ack());
    }

    public function test_reschedule_gets_no_acknowledgement_because_the_ai_answers(): void
    {
        [, $notification] = $this->reminderWithButtons();

        $this->pressButton(AppointmentReplies::payload($notification->id, 'reschedule'), 'wamid.ACK5')->assertOk();

        $this->assertSame(0, Conversation::where('contact_id', '573001112233')->firstOrFail()->messages()->where('sender', 'system')->count());
    }
}
