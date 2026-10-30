<?php

namespace Tests\Feature;

use App\Mail\ReminderMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Reminder;
use App\Models\ReminderAttempt;
use App\Models\Workspace;
use App\Reminders\ReminderChannels;
use App\Reminders\ReminderDispatcher;
use App\Reminders\ReminderService;
use App\Services\AgentAccess;
use App\Support\ScheduledCommandMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Recordatorios (module `reminders`): commercial follow-ups to customers, independent of Agenda. Covers the plan and the permissions in the server, the isolation between
 * Workspaces, the channels that are REALLY available, idempotent scheduling, and the lifecycle of sending (claimed once, re-checked at send time, retries only when nothing
 * went out, uncertain outcomes never re-sent). Mail is faked: nothing here reaches a network.
 */
class RemindersTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'customers', 'reminders', 'assistants', 'conversations'];

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.from.address' => 'envia@empresa.co']);
        Mail::fake();
        Role::findOrCreate('lector_rec', 'web')->syncPermissions(['view-reminders']);
        Role::findOrCreate('programador_rec', 'web')->syncPermissions(['view-reminders', 'create-reminders']);
        Role::findOrCreate('sin_rec', 'web')->syncPermissions(['view-dashboard']);

        $this->a = $this->bookingWorkspace('REC_A', self::MODULES);
        $this->b = $this->bookingWorkspace('REC_B', self::MODULES);
    }

    private function customer(?Workspace $workspace = null, array $attributes = []): Customer
    {
        return ($workspace ?? $this->a)->customers()->create($attributes + ['first_name' => 'Laura', 'last_name' => 'Pérez', 'email' => 'laura@example.com', 'phone' => '+573001112233', 'marketing_consent' => 'granted', 'consent_note' => 'Formulario web']);
    }

    /** @return array<string, mixed> */
    private function payload(Customer $customer, array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $customer->id, 'name' => 'Volver a reservar', 'purpose' => 'Invitar a reservar de nuevo', 'channel' => 'email', 'subject' => 'Te esperamos', 'body' => 'Hola {{nombre}}, ¿reservamos de nuevo?',
            'send_at' => now('UTC')->addDays(15)->format('Y-m-d\TH:i'), 'timezone' => 'UTC', 'idempotency_key' => 'k-'.uniqid(),
        ];
    }

    private function schedule(Customer $customer, array $overrides = []): Reminder
    {
        return app(ReminderService::class)->create($customer->workspace, $this->payload($customer, $overrides), null)['reminder'];
    }

    private function due(Reminder $reminder): void
    {
        Reminder::whereKey($reminder->id)->update(['next_attempt_at' => now()->subMinute(), 'send_at' => now()->subMinute()]);
    }

    // --- plan, permissions, isolation ----------------------------------------------------------------------------------------------

    public function test_the_module_is_registered_but_no_plan_gets_it_automatically_and_agenda_is_not_required(): void
    {
        $this->assertArrayHasKey('reminders', config('modules.implemented'));
        $this->assertNotContains('reminders', Plan::where('name', config('modules.default_plan_name'))->first()?->modules()->pluck('key')->all() ?? []);

        // A Workspace with Recordatorios and Clientes but WITHOUT Agenda can use it.
        $this->assertNotContains('bookings', self::MODULES);
        $this->actAsMember($this->a);
        $this->get('/reminders')->assertOk();
    }

    public function test_a_workspace_without_the_module_is_refused_by_the_server_and_does_not_see_the_menu(): void
    {
        $this->contractModules($this->a, ['dashboard', 'customers']);
        $this->actAsMember($this->a);

        $this->get('/reminders')->assertForbidden();
        $this->postJson('/reminders', $this->payload($this->customer()))->assertForbidden();
        $this->assertSame(0, Reminder::count());
    }

    public function test_permissions_are_checked_independently_of_the_plan(): void
    {
        $customer = $this->customer();
        $reminder = $this->schedule($customer);

        $this->actAsMember($this->a, 'sin_rec');
        $this->get('/reminders')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a, 'lector_rec');
        $this->get('/reminders')->assertOk();
        $this->getJson("/reminders/{$reminder->id}/attempts")->assertOk();
        $this->postJson('/reminders', $this->payload($customer))->assertForbidden();
        $this->getJson('/reminders/customers')->assertForbidden();
        $this->putJson("/reminders/{$reminder->id}", $this->payload($customer))->assertForbidden();
        $this->postJson("/reminders/{$reminder->id}/cancel")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a, 'programador_rec');
        $this->postJson('/reminders', $this->payload($customer, ['name' => 'Otro']))->assertRedirect();
        $this->putJson("/reminders/{$reminder->id}", $this->payload($customer))->assertForbidden();
        $this->postJson("/reminders/{$reminder->id}/cancel")->assertForbidden();
        $this->assertSame('scheduled', $reminder->fresh()->status);
    }

    public function test_another_workspace_cannot_be_reached_by_changing_ids_or_sending_a_workspace_id(): void
    {
        $mine = $this->customer();
        $foreignCustomer = $this->customer($this->b);
        $foreign = $this->schedule($foreignCustomer);
        $this->actAsMember($this->a);

        $this->postJson('/reminders', $this->payload($foreignCustomer) + ['workspace_id' => $this->b->id])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->getJson("/reminders/{$foreign->id}/attempts")->assertNotFound();
        $this->postJson("/reminders/{$foreign->id}/cancel")->assertNotFound();
        $this->putJson("/reminders/{$foreign->id}", $this->payload($mine))->assertNotFound();
        $this->assertSame('scheduled', $foreign->fresh()->status);

        // A `workspace_id` in the body of a valid request is ignored: the reminder belongs to the session's Workspace.
        $this->post('/reminders', $this->payload($mine, ['name' => 'Mío']) + ['workspace_id' => $this->b->id])->assertSessionHasNoErrors();
        $this->assertSame($this->a->id, Reminder::where('name', 'Mío')->value('workspace_id'));

        // A related record of another Workspace looks exactly like a missing one.
        $order = $this->b->orders()->exists();
        $this->postJson('/reminders', $this->payload($mine, ['related_type' => 'order', 'related_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('related_id');
        $this->assertFalse($order);

        $this->get('/reminders')->assertInertia(fn (AssertableInertia $page) => $page->has('reminders.data', 1));
    }

    // --- channels --------------------------------------------------------------------------------------------------------------------

    public function test_only_channels_that_really_work_can_be_chosen(): void
    {
        $customer = $this->customer();
        $channels = collect(app(ReminderChannels::class)->offered($this->a))->keyBy('key');

        $this->assertTrue($channels['email']['available']);
        $this->assertFalse($channels['whatsapp']['available']);
        $this->assertArrayNotHasKey('web', $channels->all(), 'the web widget can not push a message to a visitor who is not connected');

        $this->actAsMember($this->a);
        $this->postJson('/reminders', $this->payload($customer, ['channel' => 'whatsapp', 'template_name' => 'seguimiento', 'template_language' => 'es']))->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->postJson('/reminders', $this->payload($customer, ['channel' => 'web']))->assertUnprocessable()->assertJsonValidationErrors('channel');

        config(['mail.default' => 'log']);
        $this->postJson('/reminders', $this->payload($customer))->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->assertSame(0, Reminder::count());
    }

    public function test_an_unverified_or_ambiguous_whatsapp_account_is_not_available(): void
    {
        $this->a->integrations()->create(['name' => 'WA', 'type' => 'whatsapp', 'is_active' => true, 'config' => [], 'last_test_ok' => false]);
        $this->contractModules($this->a, [...self::MODULES, 'integrations']);

        $this->assertSame('not_verified', app(ReminderChannels::class)->check($this->a->refresh(), 'whatsapp')['code']);

        $this->a->integrations()->update(['last_test_ok' => true]);
        $this->assertTrue(app(ReminderChannels::class)->check($this->a->refresh(), 'whatsapp')['ok']);

        $this->a->integrations()->create(['name' => 'WA 2', 'type' => 'whatsapp', 'is_active' => true, 'config' => [], 'last_test_ok' => true]);
        $this->assertSame('several_whatsapp', app(ReminderChannels::class)->check($this->a->refresh(), 'whatsapp')['code']);
    }

    // --- scheduling ---------------------------------------------------------------------------------------------------------------------

    public function test_scheduling_validates_the_time_the_recipient_and_the_text_and_is_audited_without_contact_data(): void
    {
        $customer = $this->customer();
        $this->actAsMember($this->a);

        $this->postJson('/reminders', $this->payload($customer, ['send_at' => now()->subHour()->format('Y-m-d\TH:i')]))->assertUnprocessable()->assertJsonValidationErrors('send_at');
        $this->postJson('/reminders', $this->payload($customer, ['send_at' => now()->addDays(400)->format('Y-m-d\TH:i')]))->assertUnprocessable()->assertJsonValidationErrors('send_at');
        $this->postJson('/reminders', $this->payload($customer, ['body' => 'Hola {{inexistente}}']))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson('/reminders', $this->payload($customer, ['subject' => '']))->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->postJson('/reminders', $this->payload($this->customer(null, ['first_name' => 'Sin', 'email' => null, 'phone' => '+573009998877', 'marketing_consent' => 'granted'])))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->postJson('/reminders', $this->payload($this->customer(null, ['first_name' => 'Nada', 'email' => 'nada@example.com', 'phone' => '+573004445566', 'marketing_consent' => 'unknown'])))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertSame(0, Reminder::count());

        $this->post('/reminders', $this->payload($customer))->assertSessionHasNoErrors();
        $reminder = Reminder::first();
        $this->assertSame(['scheduled', 'email'], [$reminder->status, $reminder->channel]);
        $this->assertTrue(AuditLog::where('resource_type', 'reminder')->where('action', 'created')->exists());
        $this->assertStringNotContainsString('laura@example.com', json_encode(AuditLog::where('resource_type', 'reminder')->get()->toArray()));
    }

    public function test_the_time_is_typed_in_the_workspace_timezone_and_stored_in_utc(): void
    {
        $this->a->settingsOrDefault()->update(['timezone' => 'America/Bogota']);
        $customer = $this->customer();
        $local = now('America/Bogota')->addDays(2)->setTime(10, 0);

        $reminder = $this->schedule($customer, ['send_at' => $local->format('Y-m-d\TH:i'), 'timezone' => null]);

        $this->assertSame('America/Bogota', $reminder->timezone);
        $this->assertSame($local->copy()->utc()->format('Y-m-d H:i'), $reminder->send_at->format('Y-m-d H:i'));
    }

    public function test_the_same_idempotency_key_returns_the_same_reminder(): void
    {
        $customer = $this->customer();
        $this->actAsMember($this->a);
        $payload = $this->payload($customer, ['idempotency_key' => 'una-sola-vez']);

        $this->post('/reminders', $payload)->assertSessionHasNoErrors();
        $this->post('/reminders', $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Reminder::count());
    }

    public function test_editing_and_cancelling_only_work_while_it_is_scheduled(): void
    {
        $customer = $this->customer();
        $reminder = $this->schedule($customer);
        $this->actAsMember($this->a);

        $this->put("/reminders/{$reminder->id}", $this->payload($customer, ['name' => 'Cambiado']))->assertSessionHasNoErrors();
        $this->assertSame('Cambiado', $reminder->fresh()->name);

        $this->post("/reminders/{$reminder->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', null], [$reminder->fresh()->status, $reminder->fresh()->next_attempt_at]);

        $this->post("/reminders/{$reminder->id}/cancel")->assertSessionHasErrors('reminder');
        $this->put("/reminders/{$reminder->id}", $this->payload($customer))->assertSessionHasErrors('name');
    }

    public function test_the_list_is_paginated_and_filtered_in_the_server(): void
    {
        $customer = $this->customer();
        foreach (range(1, 12) as $i) {
            $this->schedule($customer, ['name' => "Seguimiento {$i}"]);
        }
        $cancelled = $this->schedule($customer, ['name' => 'Para cancelar']);
        app(ReminderService::class)->cancel($cancelled);
        $this->actAsMember($this->a);

        $this->get('/reminders')->assertInertia(fn (AssertableInertia $page) => $page->where('reminders.meta.total', 13)->has('reminders.data', 10)->where('reminders.meta.last_page', 2));
        $this->get('/reminders?status=cancelled')->assertInertia(fn (AssertableInertia $page) => $page->where('reminders.meta.total', 1));
        $this->get('/reminders?q=cancelar')->assertInertia(fn (AssertableInertia $page) => $page->where('reminders.meta.total', 1));
        $this->get('/reminders?status=hacked')->assertSessionHasErrors('status');
    }

    // --- sending ------------------------------------------------------------------------------------------------------------------------

    public function test_a_due_reminder_is_sent_once_with_the_text_rendered_for_the_customer(): void
    {
        $reminder = $this->schedule($this->customer());
        $this->due($reminder);

        Artisan::call('reminders:dispatch');
        app(ReminderDispatcher::class)->send($reminder->id);
        app(ReminderDispatcher::class)->send($reminder->id); // a duplicated job

        Mail::assertSent(ReminderMail::class, 1);
        Mail::assertSent(ReminderMail::class, fn (ReminderMail $mail) => $mail->hasTo('laura@example.com') && str_contains($mail->text, 'Hola Laura'));
        $this->assertSame(['sent', 1], [$reminder->fresh()->status, $reminder->fresh()->attempts]);
        $this->assertSame(['sent'], ReminderAttempt::where('reminder_id', $reminder->id)->pluck('outcome')->all());
    }

    public function test_a_reminder_that_is_not_due_yet_or_was_cancelled_is_never_sent(): void
    {
        $future = $this->schedule($this->customer());
        $cancelled = $this->schedule($this->customer(null, ['first_name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '+573007778899']));
        $this->due($cancelled);
        app(ReminderService::class)->cancel($cancelled);

        app(ReminderDispatcher::class)->send($future->id);
        app(ReminderDispatcher::class)->send($cancelled->id);

        Mail::assertNothingSent();
        $this->assertSame(['scheduled', 'cancelled'], [$future->fresh()->status, $cancelled->fresh()->status]);
    }

    public function test_everything_is_checked_again_at_send_time(): void
    {
        $customer = $this->customer();
        $revoked = $this->schedule($customer);
        $this->due($revoked);
        $customer->forceFill(['marketing_consent' => 'revoked'])->save();
        app(ReminderDispatcher::class)->send($revoked->id);
        $this->assertSame(['skipped', 'no_consent'], [$revoked->fresh()->status, $revoked->fresh()->reason]);

        $other = $this->customer(null, ['first_name' => 'Eva', 'email' => 'eva@example.com', 'phone' => '+573001230000']);
        $planGone = $this->schedule($other);
        $this->due($planGone);
        $this->contractModules($this->a, ['dashboard', 'customers']);
        app(ReminderDispatcher::class)->send($planGone->id);
        $this->assertSame(['skipped', 'plan'], [$planGone->fresh()->status, $planGone->fresh()->reason]);

        $this->contractModules($this->a, self::MODULES);
        $inactive = $this->schedule($this->customer(null, ['first_name' => 'Ivo', 'email' => 'ivo@example.com', 'phone' => '+573001239999']));
        $this->due($inactive);
        $inactive->customer->forceFill(['is_active' => false])->save();
        app(ReminderDispatcher::class)->send($inactive->id);
        $this->assertSame(['skipped', 'customer_unavailable'], [$inactive->fresh()->status, $inactive->fresh()->reason]);

        Mail::assertNothingSent();
    }

    public function test_a_reminder_tied_to_a_cancelled_appointment_is_not_sent(): void
    {
        $this->contractModules($this->a, [...self::MODULES, 'service_catalog', 'professionals', 'bookings']);
        $this->freezeAgendaTime();
        $service = $this->makeService($this->a);
        $professional = $this->makeProfessional($this->a, [$service]);
        $booked = $this->appointments()->book($this->a, $service, $professional->id, $this->now()->addDay()->setTime(10, 0), ['name' => 'Laura', 'phone' => '573001112233'], 'admin');
        $appointment = $booked['appointment'];

        $reminder = $this->schedule($this->customer(), ['related_type' => 'appointment', 'related_id' => $appointment->id]);
        $this->due($reminder);
        $this->appointments()->cancel($appointment->fresh(), null, 'admin');

        app(ReminderDispatcher::class)->send($reminder->id);

        Mail::assertNothingSent();
        $this->assertSame(['cancelled', 'appointment_closed'], [$reminder->fresh()->status, $reminder->fresh()->reason]);
    }

    public function test_a_failure_where_nothing_went_out_is_retried_with_backoff_and_then_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'))->byDefault();
        $reminder = $this->schedule($this->customer());
        $this->due($reminder);

        app(ReminderDispatcher::class)->send($reminder->id);
        $first = $reminder->fresh();
        $this->assertSame(['scheduled', 1, 'send_failed'], [$first->status, $first->attempts, $first->reason]);
        $this->assertTrue($first->next_attempt_at->isFuture());

        foreach (range(2, 4) as $attempt) {
            Reminder::whereKey($reminder->id)->update(['next_attempt_at' => now()->subSecond()]);
            app(ReminderDispatcher::class)->send($reminder->id);
        }

        $this->assertSame(['failed', 4], [$reminder->fresh()->status, $reminder->fresh()->attempts]);
        $this->assertSame(['retry', 'retry', 'retry', 'failed'], ReminderAttempt::where('reminder_id', $reminder->id)->orderBy('id')->pluck('outcome')->all());
    }

    public function test_a_reminder_stuck_in_processing_becomes_uncertain_and_is_never_resent(): void
    {
        $reminder = $this->schedule($this->customer());
        Reminder::whereKey($reminder->id)->update(['status' => 'processing', 'attempts' => 1, 'updated_at' => now()->subHour()]);

        Artisan::call('reminders:dispatch');
        Artisan::call('reminders:dispatch');

        $this->assertSame(['uncertain', 'interrupted'], [$reminder->fresh()->status, $reminder->fresh()->reason]);
        Mail::assertNothingSent();
    }

    public function test_the_scheduler_runs_the_dispatcher_every_minute_and_it_is_watched(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'reminders:dispatch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertArrayHasKey('reminders-dispatch', ScheduledCommandMonitor::WATCHED);
    }

    // --- agent API ----------------------------------------------------------------------------------------------------------------------

    public function test_the_assistant_schedules_for_the_person_of_the_conversation_only_and_it_is_idempotent(): void
    {
        $bot = $this->a->chatbots()->create(['name' => 'Asistente']);
        $token = app(AgentAccess::class)->generate($bot);
        $customer = $this->customer();
        $customer->identities()->create(['workspace_id' => $this->a->id, 'channel' => 'whatsapp', 'external_id' => '573001112233']);
        $other = $this->customer(null, ['first_name' => 'Otra', 'email' => 'otra@example.com', 'phone' => '+573005550000']);
        $body = ['contact_channel' => 'whatsapp', 'contact_id' => '573001112233', 'name' => 'Volver', 'channel' => 'email', 'subject' => 'Hola', 'body' => 'Hola {{nombre}}', 'send_at' => now('UTC')->addDays(15)->format('Y-m-d\TH:i:s\Z')];

        $this->withToken($token)->postJson('/api/agent/reminders', $body)->assertStatus(422); // the key is required
        $first = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'wf-1'])->postJson('/api/agent/reminders', $body)->assertCreated();
        $again = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'wf-1'])->postJson('/api/agent/reminders', $body)->assertOk();
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertTrue($again->json('replayed'));
        $this->assertSame($customer->id, Reminder::first()->customer_id);
        $this->assertSame('api', Reminder::first()->source);

        // A contact that is not linked to any customer finds nothing, and the assistant can not name another customer.
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'wf-2'])->postJson('/api/agent/reminders', ['contact_id' => '573009999999'] + $body)->assertNotFound();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'wf-3'])->postJson('/api/agent/reminders', ['customer_id' => $other->id] + $body)->assertCreated();
        $this->assertSame(2, Reminder::count());
        $this->assertSame($customer->id, Reminder::orderByDesc('id')->first()->customer_id, 'a customer_id sent by the assistant is ignored');

        // It can cancel what came from the API, not what a person scheduled.
        $manual = $this->schedule($customer, ['name' => 'Manual']);
        $this->withToken($token)->postJson("/api/agent/reminders/{$manual->id}/cancel")->assertNotFound();
        $this->withToken($token)->postJson('/api/agent/reminders/'.$first->json('id').'/cancel')->assertOk();
    }

    public function test_the_agent_api_needs_the_module_in_the_plan(): void
    {
        $this->contractModules($this->a, ['dashboard', 'assistants', 'conversations', 'customers']);
        $token = app(AgentAccess::class)->generate($this->a->chatbots()->create(['name' => 'Asistente']));

        $this->withToken($token)->postJson('/api/agent/reminders', [])->assertForbidden();
    }
}
