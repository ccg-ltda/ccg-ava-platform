<?php

namespace Tests\Feature;

use App\Mail\ReminderMail;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\ReminderRule;
use App\Models\ReminderRuleEvent;
use App\Models\Service;
use App\Models\Workspace;
use App\Reminders\ReminderDispatcher;
use App\Reminders\ReminderRuleScheduler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Automatic reminders by service: a rule («30 days after a customer had service X») schedules one reminder per customer from the appointments Agenda marked as COMPLETED,
 * each with its own date. Covers the events that count and the ones that do not, consent and channel, no duplicates over repeated runs, invalidation when the origin or
 * the rule changes, the lifecycle of sending, permissions, the plan and the isolation between Workspaces. Mail is faked: nothing here reaches a network.
 */
class ReminderRulesTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'customers', 'reminders', 'service_catalog', 'professionals', 'bookings'];

    private Workspace $a;

    private Workspace $b;

    private Service $nails;

    private Service $hair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.from.address' => 'envia@empresa.co']);
        Mail::fake();
        Role::findOrCreate('lector_rec', 'web')->syncPermissions(['view-reminders']);
        Role::findOrCreate('creador_reglas', 'web')->syncPermissions(['view-reminders', 'create-reminder-rules']);
        Role::findOrCreate('editor_reglas', 'web')->syncPermissions(['view-reminders', 'edit-reminder-rules']);
        Role::findOrCreate('activador_reglas', 'web')->syncPermissions(['view-reminders', 'activate-reminder-rules']);

        $this->freezeAgendaTime();
        $this->a = $this->bookingWorkspace('RULE_A', self::MODULES);
        $this->b = $this->bookingWorkspace('RULE_B', self::MODULES);
        $this->nails = $this->makeService($this->a, ['name' => 'Mantenimiento de uñas']);
        $this->hair = $this->makeService($this->a, ['name' => 'Corte']);
    }

    private function customer(string $name, string $phone, array $attributes = [], ?Workspace $workspace = null): Customer
    {
        return ($workspace ?? $this->a)->customers()->create($attributes + ['first_name' => $name, 'last_name' => 'Prueba', 'email' => strtolower($name).'@example.com', 'phone' => $phone, 'marketing_consent' => 'granted', 'consent_note' => 'Formulario web']);
    }

    /** A booking of Agenda for the person who owns this customer card (matched by phone, as Agenda keeps it). */
    private function appointment(Customer $customer, Service $service, string $status, string $startsAt, ?Workspace $workspace = null): Appointment
    {
        $workspace ??= $this->a;
        $booked = $workspace->bookingCustomers()->firstOrCreate(['phone' => $customer->phone], ['name' => $customer->first_name, 'email' => $customer->email]);
        $start = $this->now()->modify($startsAt);

        return $workspace->appointments()->create([
            'service_id' => $service->id, 'customer_id' => $booked->id, 'service_name' => $service->name, 'professional_name' => 'Ana',
            'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocks_until' => $start->addHour(), 'status' => $status, 'provider' => 'internal', 'sync_status' => 'none',
            'channel' => 'admin', 'origin_channel' => 'admin', 'token_hash' => hash('sha256', uniqid('', true)), 'price_amount' => 25, 'price_currency' => 'COP',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Recordatorio de mantenimiento de uñas', 'service_ids' => [$this->nails->id], 'delay_amount' => 30, 'delay_unit' => 'days', 'send_time' => '10:00',
            'channel' => 'email', 'subject' => 'Es hora de tu mantenimiento', 'body' => 'Hola {{nombre}}, ya pasaron 30 días desde tu mantenimiento de uñas.',
        ];
    }

    private function rule(array $overrides = []): ReminderRule
    {
        $this->actAsMember($this->a);
        $this->post('/reminders/rules', $this->payload($overrides))->assertSessionHasNoErrors();

        return ReminderRule::where('workspace_id', $this->a->id)->latest('id')->firstOrFail();
    }

    /** A session older than the absolute limit is closed, so after travelling in time the person signs in again. */
    private function signInAgain(string $role = 'admin'): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actAsMember($this->a, $role);
    }

    private function scan(): array
    {
        return app(ReminderRuleScheduler::class)->scan();
    }

    // --- one reminder per customer, each with its own date --------------------------------------------------------------------------

    public function test_two_customers_served_on_different_days_each_get_their_own_reminder_date(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $laura = $this->customer('Laura', '+573001112233');
        $maria = $this->customer('Maria', '+573004445566');
        $this->appointment($laura, $this->nails, Appointment::COMPLETED, '+1 day');
        $this->appointment($maria, $this->nails, Appointment::COMPLETED, '+2 days');

        $this->assertSame(['scheduled' => 2, 'skipped' => 0, 'cancelled' => 0], $this->scan());

        $first = Reminder::where('customer_id', $laura->id)->firstOrFail();
        $second = Reminder::where('customer_id', $maria->id)->firstOrFail();
        $this->assertSame('2026-11-12 10:00:00', $first->send_at->toDateTimeString(), '30 days after the service of the first customer, at the rule hour');
        $this->assertSame('2026-11-13 10:00:00', $second->send_at->toDateTimeString(), 'a day later for the customer served a day later');
        $this->assertSame('rule', $first->source);
        $this->assertSame(['appointment', 'Recordatorio de mantenimiento de uñas'], [$first->related_type, $first->name]);
        $this->assertSame(2, ReminderRuleEvent::where('status', 'scheduled')->count());
    }

    public function test_only_completed_appointments_of_the_chosen_services_count(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $customer = $this->customer('Laura', '+573001112233');

        foreach ([Appointment::REQUESTED, Appointment::CONFIRMED, Appointment::CANCELLED, Appointment::NO_SHOW, Appointment::FAILED] as $status) {
            $this->appointment($customer, $this->nails, $status, '+1 day');
        }
        $this->appointment($customer, $this->hair, Appointment::COMPLETED, '+1 day'); // done, but another service

        $this->assertSame(['scheduled' => 0, 'skipped' => 0, 'cancelled' => 0], $this->scan());
        $this->assertSame(0, Reminder::count());
    }

    public function test_services_done_before_the_rule_was_switched_on_are_not_messaged(): void
    {
        $customer = $this->customer('Laura', '+573001112233');
        $this->appointment($customer, $this->nails, Appointment::COMPLETED, '-2 days');
        $this->rule();

        $this->assertSame(0, $this->scan()['scheduled']);
        $this->assertSame(0, Reminder::count());
    }

    // --- eligibility: consent, customer card, channel ----------------------------------------------------------------------------------

    public function test_a_customer_without_consent_is_skipped_and_scheduled_once_they_grant_it(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $customer = $this->customer('Laura', '+573001112233', ['marketing_consent' => 'unknown', 'consent_note' => null]);
        $this->appointment($customer, $this->nails, Appointment::COMPLETED, '+1 day');

        $this->assertSame(['scheduled' => 0, 'skipped' => 1, 'cancelled' => 0], $this->scan());
        $this->assertSame(0, Reminder::count());
        $this->assertSame('no_consent', ReminderRuleEvent::firstOrFail()->reason);

        $customer->update(['marketing_consent' => 'granted', 'consent_note' => 'Llamada']);

        $this->assertSame(1, $this->scan()['scheduled']);
        $this->assertSame(1, Reminder::count());
        $this->assertSame('scheduled', ReminderRuleEvent::firstOrFail()->status);
    }

    public function test_a_booking_without_a_customer_card_or_with_ambiguous_cards_is_skipped_with_its_reason(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $stranger = new Customer(['first_name' => 'Nadie', 'phone' => '+573009998877', 'email' => 'nadie@example.com']);
        $stranger->workspace_id = $this->a->id;
        $this->appointment($stranger, $this->nails, Appointment::COMPLETED, '+1 day'); // no card in Clientes

        $byPhone = $this->customer('Ana', '+573001110000', ['email' => 'ana@example.com']);
        $byEmail = $this->customer('Eva', '+573001110001', ['email' => 'eva@example.com']);
        $booked = $this->a->bookingCustomers()->create(['name' => 'Mezcla', 'phone' => $byPhone->phone, 'email' => $byEmail->email]);
        $start = $this->now()->modify('+1 day');
        $this->a->appointments()->create(['service_id' => $this->nails->id, 'customer_id' => $booked->id, 'service_name' => 'x', 'professional_name' => 'Ana', 'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocks_until' => $start->addHour(), 'status' => Appointment::COMPLETED, 'provider' => 'internal', 'sync_status' => 'none', 'channel' => 'admin', 'origin_channel' => 'admin', 'token_hash' => 'h']);

        $this->assertSame(2, $this->scan()['skipped']);
        $this->assertEqualsCanonicalizing(['no_customer', 'ambiguous_customer'], ReminderRuleEvent::pluck('reason')->all());
        $this->assertSame(0, Reminder::count());
    }

    public function test_an_unavailable_channel_skips_and_the_appointment_is_scheduled_when_it_becomes_available(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+1 day');

        config(['mail.default' => 'log']);
        $this->assertSame(1, $this->scan()['skipped']);
        $this->assertSame('channel_unavailable', ReminderRuleEvent::firstOrFail()->reason);
        $this->assertSame(0, Reminder::count());

        config(['mail.default' => 'smtp']);
        $this->assertSame(1, $this->scan()['scheduled']);
        $this->assertSame(1, Reminder::count());
    }

    public function test_a_rule_cannot_be_saved_with_a_channel_the_workspace_cannot_use(): void
    {
        $this->actAsMember($this->a);
        config(['mail.default' => 'log']);

        $this->post('/reminders/rules', $this->payload())->assertSessionHasErrors('channel');
        $this->assertSame(0, ReminderRule::count());
    }

    // --- no duplicates, rule switched off, invalidation -----------------------------------------------------------------------------------

    public function test_repeated_and_concurrent_runs_never_duplicate_a_reminder(): void
    {
        $rule = $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+1 day');

        $this->assertSame(1, $this->scan()['scheduled']);
        $this->assertSame(0, $this->scan()['scheduled']);
        Artisan::call('reminders:schedule-rules');

        $this->assertSame(1, Reminder::count());
        $this->assertSame(1, ReminderRuleEvent::count());

        // Even if the decision row were lost, the key of the reminder keeps it single.
        ReminderRuleEvent::query()->delete();
        $this->scan();
        $this->assertSame(1, Reminder::count());
        $this->assertSame($rule->id, Reminder::firstOrFail()->reminder_rule_id);
    }

    public function test_a_service_done_while_the_rule_is_off_is_not_scheduled_and_switching_it_off_cancels_what_was_pending(): void
    {
        $rule = $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $laura = $this->customer('Laura', '+573001112233');
        $this->appointment($laura, $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();
        $reminder = Reminder::firstOrFail();

        $this->signInAgain();
        $this->post("/reminders/rules/{$rule->id}/deactivate")->assertSessionHas('success');

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame(['cancelled', 'rule_inactive'], [$reminder->fresh()->status, $reminder->fresh()->reason]);

        $maria = $this->customer('Maria', '+573004445566');
        $this->appointment($maria, $this->nails, Appointment::COMPLETED, '+2 days');
        $this->assertSame(0, $this->scan()['scheduled']);

        $this->travelTo($this->now()->addDays(10));
        $this->signInAgain();
        $this->post("/reminders/rules/{$rule->id}/activate");
        $this->assertSame(0, $this->scan()['scheduled'], 'switching it on again never messages what was done while it was off');
        $this->assertSame(1, Reminder::count());
    }

    public function test_changing_the_appointment_after_scheduling_invalidates_the_reminder(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $laura = $this->customer('Laura', '+573001112233');
        $cancelled = $this->appointment($laura, $this->nails, Appointment::COMPLETED, '+1 day');
        $changed = $this->appointment($this->customer('Maria', '+573004445566'), $this->nails, Appointment::COMPLETED, '+2 days');
        $this->scan();

        $cancelled->update(['status' => Appointment::CANCELLED]);
        $changed->update(['service_id' => $this->hair->id]);

        $this->assertSame(2, $this->scan()['cancelled']);
        $this->assertEqualsCanonicalizing(['appointment_changed', 'rule_changed'], Reminder::pluck('reason')->all());
        $this->assertSame(0, Reminder::where('status', 'scheduled')->count());
        $this->assertSame(2, ReminderRuleEvent::where('status', 'cancelled')->count());
    }

    public function test_the_dispatcher_checks_the_origin_again_right_before_sending(): void
    {
        $rule = $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $laura = $this->customer('Laura', '+573001112233');
        $maria = $this->customer('Maria', '+573004445566');
        $ok = $this->appointment($laura, $this->nails, Appointment::COMPLETED, '+1 day');
        $gone = $this->appointment($maria, $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();
        Reminder::query()->update(['next_attempt_at' => now()->subMinute(), 'send_at' => now()->subMinute()]);

        $gone->update(['status' => Appointment::NO_SHOW]); // changed by hand after the scan
        app(ReminderDispatcher::class)->send(Reminder::where('customer_id', $laura->id)->value('id'));
        app(ReminderDispatcher::class)->send(Reminder::where('customer_id', $maria->id)->value('id'));

        $this->assertSame('sent', Reminder::where('customer_id', $laura->id)->value('status'));
        $this->assertSame(['cancelled', 'appointment_changed'], [Reminder::where('customer_id', $maria->id)->value('status'), Reminder::where('customer_id', $maria->id)->value('reason')]);
        Mail::assertSent(ReminderMail::class, 1);

        // A rule switched off after scheduling stops the next one too.
        $third = $this->customer('Sara', '+573007778899');
        $this->appointment($third, $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();
        $pending = Reminder::where('customer_id', $third->id)->firstOrFail();
        Reminder::whereKey($pending->id)->update(['next_attempt_at' => now()->subMinute(), 'send_at' => now()->subMinute()]);
        $rule->forceFill(['is_active' => false])->save();
        app(ReminderDispatcher::class)->send($pending->id);

        $this->assertSame(['cancelled', 'rule_inactive'], [$pending->fresh()->status, $pending->fresh()->reason]);
    }

    public function test_consent_revoked_before_sending_skips_it_and_a_send_failure_is_retried_with_history(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $laura = $this->customer('Laura', '+573001112233');
        $maria = $this->customer('Maria', '+573004445566');
        $this->appointment($laura, $this->nails, Appointment::COMPLETED, '+1 day');
        $this->appointment($maria, $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();
        Reminder::query()->update(['next_attempt_at' => now()->subMinute(), 'send_at' => now()->subMinute()]);

        $laura->update(['marketing_consent' => 'revoked']);
        app(ReminderDispatcher::class)->send(Reminder::where('customer_id', $laura->id)->value('id'));
        $this->assertSame(['skipped', 'no_consent'], [Reminder::where('customer_id', $laura->id)->value('status'), Reminder::where('customer_id', $laura->id)->value('reason')]);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $id = Reminder::where('customer_id', $maria->id)->value('id');
        app(ReminderDispatcher::class)->send($id);
        $failed = Reminder::findOrFail($id);
        $this->assertSame(['scheduled', 'send_failed', 1], [$failed->status, $failed->reason, $failed->attempts]);
        $this->assertSame('retry', $failed->attemptsLog()->latest('id')->value('outcome'));
    }

    public function test_the_database_itself_refuses_a_second_reminder_of_the_same_rule_for_the_same_appointment(): void
    {
        $rule = $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $appointment = $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();
        $first = Reminder::firstOrFail();

        // What a second worker would try at the same moment: another row for the same rule and appointment, with a different idempotency key.
        $this->expectException(UniqueConstraintViolationException::class);
        Reminder::query()->insert([
            'workspace_id' => $this->a->id, 'customer_id' => $first->customer_id, 'name' => 'x', 'related_type' => 'appointment', 'related_id' => $appointment->id, 'reminder_rule_id' => $rule->id,
            'channel' => 'email', 'subject' => 's', 'body' => 'b', 'send_at' => now(), 'timezone' => 'UTC', 'status' => 'scheduled', 'idempotency_key' => 'otro-worker', 'source' => 'rule', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_reminder_with_an_uncertain_result_is_never_queued_again_by_itself(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();
        $reminder = Reminder::firstOrFail();
        Reminder::whereKey($reminder->id)->update(['status' => Reminder::UNCERTAIN, 'reason' => 'interrupted', 'next_attempt_at' => null, 'send_at' => now()->subDay()]);

        Queue::fake();
        $result = app(ReminderDispatcher::class)->dispatchDue();
        $this->scan();

        $this->assertSame(0, $result['queued']);
        Queue::assertNothingPushed();
        $this->assertSame(Reminder::UNCERTAIN, $reminder->fresh()->status);
        $this->assertSame(1, Reminder::count(), 'the scan does not schedule a replacement for it either');
    }

    // --- editing the rule ---------------------------------------------------------------------------------------------------------------

    public function test_editing_a_rule_rewrites_what_it_already_scheduled_and_keeps_one_reminder_per_appointment(): void
    {
        $rule = $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();

        $this->signInAgain();
        $this->put("/reminders/rules/{$rule->id}", $this->payload(['delay_amount' => 2, 'delay_unit' => 'weeks', 'send_time' => '16:30', 'body' => 'Texto nuevo {{nombre}}', 'service_ids' => [$this->nails->id, $this->hair->id]]))->assertSessionHasNoErrors();

        $reminder = Reminder::firstOrFail();
        $this->assertSame(1, Reminder::count());
        $this->assertSame('2026-10-27 16:30:00', $reminder->send_at->toDateTimeString());
        $this->assertSame('Texto nuevo {{nombre}}', $reminder->body);
        $this->assertSame(2, $rule->services()->count());

        $this->put("/reminders/rules/{$rule->id}", $this->payload(['service_ids' => [$this->hair->id]]));
        $this->assertSame(['cancelled', 'rule_changed'], [$reminder->fresh()->status, $reminder->fresh()->reason]);
    }

    public function test_a_reminder_a_rule_scheduled_cannot_be_edited_by_hand_but_can_be_cancelled(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $laura = $this->customer('Laura', '+573001112233');
        $this->appointment($laura, $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();
        $reminder = Reminder::firstOrFail();

        $this->signInAgain();
        $this->put("/reminders/{$reminder->id}", ['customer_id' => $laura->id, 'name' => 'x', 'channel' => 'email', 'subject' => 's', 'body' => 'b', 'send_at' => now()->addDays(5)->format('Y-m-d\TH:i')])->assertSessionHasErrors('name');
        $this->post("/reminders/{$reminder->id}/cancel")->assertSessionHas('success');

        $this->assertSame('cancelled', $reminder->fresh()->status);
        $this->assertSame(0, $this->scan()['scheduled'], 'a cancelled one is not scheduled again');
    }

    // --- validation, permissions, plan, isolation ---------------------------------------------------------------------------------------

    public function test_the_rule_is_validated_in_the_server(): void
    {
        $this->actAsMember($this->a);

        $this->post('/reminders/rules', $this->payload(['service_ids' => []]))->assertSessionHasErrors('service_ids');
        $this->post('/reminders/rules', $this->payload(['delay_amount' => 0]))->assertSessionHasErrors('delay_amount');
        $this->post('/reminders/rules', $this->payload(['delay_amount' => 5000]))->assertSessionHasErrors('delay_amount');
        $this->post('/reminders/rules', $this->payload(['delay_unit' => 'years']))->assertSessionHasErrors('delay_unit');
        $this->post('/reminders/rules', $this->payload(['body' => 'Hola {{inventada}}']))->assertSessionHasErrors('body');
        $this->post('/reminders/rules', $this->payload(['subject' => '']))->assertSessionHasErrors('subject');
        $this->post('/reminders/rules', $this->payload(['channel' => 'whatsapp']))->assertSessionHasErrors();
        $this->assertSame(0, ReminderRule::count());
    }

    public function test_each_operation_has_its_own_permission(): void
    {
        $rule = $this->rule();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a, 'lector_rec');
        $this->get('/reminders')->assertOk();
        $this->getJson("/reminders/rules/{$rule->id}/events")->assertOk();
        $this->post('/reminders/rules', $this->payload())->assertForbidden();
        $this->put("/reminders/rules/{$rule->id}", $this->payload())->assertForbidden();
        $this->post("/reminders/rules/{$rule->id}/deactivate")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a, 'creador_reglas');
        $this->post('/reminders/rules', $this->payload(['name' => 'Otra']))->assertSessionHasNoErrors();
        $this->put("/reminders/rules/{$rule->id}", $this->payload())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a, 'editor_reglas');
        $this->put("/reminders/rules/{$rule->id}", $this->payload(['name' => 'Editada']))->assertSessionHasNoErrors();
        $this->post("/reminders/rules/{$rule->id}/deactivate")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a, 'activador_reglas');
        $this->post("/reminders/rules/{$rule->id}/deactivate")->assertSessionHas('success');
        $this->post('/reminders/rules', $this->payload())->assertForbidden();
        $this->assertSame('Editada', $rule->fresh()->name);
    }

    public function test_rules_need_agenda_in_the_plan_while_reminders_keep_working_without_it(): void
    {
        $rule = $this->rule();
        $this->contractModules($this->a, ['dashboard', 'customers', 'reminders']);
        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a);

        $this->get('/reminders')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('rules', null));
        $this->post('/reminders/rules', $this->payload())->assertForbidden();
        $this->put("/reminders/rules/{$rule->id}", $this->payload())->assertForbidden();
        $this->getJson("/reminders/rules/{$rule->id}/events")->assertForbidden();

        $this->travelTo($this->now()->addDays(3));
        $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+1 day');
        $this->assertSame(0, $this->scan()['scheduled'], 'without Agenda in the plan the scan does nothing');
    }

    public function test_a_rule_service_or_reminder_of_another_workspace_cannot_be_reached(): void
    {
        $foreignService = $this->makeService($this->b, ['name' => 'Ajeno']);
        $this->actAsMember($this->b);
        $this->post('/reminders/rules', $this->payload(['service_ids' => [$foreignService->id]]))->assertSessionHasNoErrors();
        $foreignRule = ReminderRule::where('workspace_id', $this->b->id)->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a);
        $this->post('/reminders/rules', $this->payload(['service_ids' => [$foreignService->id]]))->assertSessionHasErrors('service_ids');
        $this->post('/reminders/rules', $this->payload(['service_ids' => [$this->nails->id, $foreignService->id]]))->assertSessionHasErrors('service_ids');
        $this->put("/reminders/rules/{$foreignRule->id}", $this->payload())->assertNotFound();
        $this->post("/reminders/rules/{$foreignRule->id}/deactivate")->assertNotFound();
        $this->getJson("/reminders/rules/{$foreignRule->id}/events")->assertNotFound();
        $this->post('/reminders/rules', $this->payload(['workspace_id' => $this->b->id]));
        $this->assertSame(1, ReminderRule::where('workspace_id', $this->a->id)->count(), 'the workspace id the client sends is ignored');
        $this->assertSame(1, ReminderRule::where('workspace_id', $this->b->id)->count());
        $this->assertTrue($foreignRule->fresh()->is_active);
    }

    public function test_the_scan_of_one_workspace_never_schedules_for_another(): void
    {
        $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $outsider = $this->customer('Laura', '+573001112233', [], $this->b);
        $this->appointment($outsider, $this->makeService($this->b), Appointment::COMPLETED, '+1 day', $this->b);

        $this->assertSame(0, $this->scan()['scheduled']);
        $this->assertSame(0, Reminder::count());
    }

    // --- page, history, audit, Agenda untouched -----------------------------------------------------------------------------------------

    public function test_the_page_lists_the_rules_with_their_counts_and_the_history_of_what_they_decided(): void
    {
        $rule = $this->rule();
        $this->travelTo($this->now()->addDays(3));
        $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+1 day');
        $this->appointment($this->customer('Eva', '+573001110001', ['marketing_consent' => 'unknown', 'consent_note' => null]), $this->nails, Appointment::COMPLETED, '+1 day');
        $this->scan();

        $this->signInAgain();
        $this->get('/reminders')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rules.data', 1)->where('rules.data.0.name', $rule->name)->where('rules.data.0.stats.scheduled', 1)->where('rules.data.0.stats.skipped', 1)
            ->where('rules.data.0.services.0.name', 'Mantenimiento de uñas')->has('rules.services', 2));

        $events = $this->getJson("/reminders/rules/{$rule->id}/events")->assertOk()->json('events');
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(['scheduled', 'skipped'], array_column($events, 'status'));
        $this->assertStringContainsString('consentimiento', collect($events)->firstWhere('status', 'skipped')['reason']);
    }

    public function test_rule_changes_are_audited_and_agenda_notifications_are_untouched(): void
    {
        $rule = $this->rule();
        $this->put("/reminders/rules/{$rule->id}", $this->payload(['delay_amount' => 45]));
        $this->post("/reminders/rules/{$rule->id}/deactivate");

        $this->assertSame(['created', 'updated', 'updated'], AuditLog::where('resource_type', 'reminder_rule')->orderBy('id')->pluck('action')->all());

        $this->travelTo($this->now()->addDays(3));
        $this->signInAgain();
        $this->post("/reminders/rules/{$rule->id}/activate");
        $this->appointment($this->customer('Laura', '+573001112233'), $this->nails, Appointment::COMPLETED, '+3 days');
        $this->assertSame(1, $this->scan()['scheduled']);
        $this->assertSame(0, AppointmentNotification::count(), 'the rules never create an Agenda notification');
        $this->assertSame('Regla de recordatorio (automático)', AuditLog::where('resource_type', 'reminder')->value('user_name'));
    }
}
