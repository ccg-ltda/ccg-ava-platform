<?php

namespace Tests\Feature;

use App\Mail\CatalogReminderMail;
use App\Models\CatalogReminder;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CatalogReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Who receives a reminder of the Catálogo: the creator picks the recipients among the ENABLED members of the SAME Workspace whose role may read the reminders;
 * the choice is validated on the server, persisted, listed, used by the real sender (one email per person, to that person's address) and re-checked when it
 * sends. A retry never repeats the email to somebody who already got it. No real mail is sent (the Mail facade is faked).
 */
class CatalogReminderRecipientsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $ws;

    private Workspace $other;

    private User $ana;

    private User $bruno;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->ws = $this->bookingWorkspace('TIENDA', ['dashboard', 'product_catalog']);
        $this->other = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $this->ana = $this->member($this->ws, 'admin');
        $this->bruno = $this->member($this->ws, 'admin');
        $this->carla = $this->member($this->ws, 'admin');
        Mail::fake();
        $this->actingAs($this->ana)->withSession(['workspace_id' => $this->ws->id]);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'subject' => 'Alistar pedido', 'body' => 'Revisar el pedido de Laura.',
            'scheduled_at' => now()->setTimezone($this->ws->settingsOrDefault()->timezone)->addHours(2)->format('Y-m-d\TH:i'),
        ];
    }

    private function create(array $recipients): CatalogReminder
    {
        $this->post('/catalog/reminders', $this->payload(['recipient_ids' => $recipients]))->assertSessionHasNoErrors();

        return CatalogReminder::latest('id')->firstOrFail();
    }

    private function due(CatalogReminder $reminder): void
    {
        CatalogReminder::whereKey($reminder->id)->update(['status' => CatalogReminder::QUEUED, 'scheduled_at' => now()->subMinute()]);
    }

    public function test_the_recipients_are_chosen_persisted_and_listed_and_the_creator_is_not_added_behind_their_back(): void
    {
        $reminder = $this->create([$this->bruno->id, $this->carla->id]);

        $this->assertEqualsCanonicalizing([$this->bruno->id, $this->carla->id], $reminder->recipients()->pluck('users.id')->all());
        $this->assertSame($this->ana->id, $reminder->created_by);
        $this->assertFalse($reminder->recipients()->whereKey($this->ana->id)->exists(), 'the creator is a recipient only if they choose themselves');

        $page = $this->get('/catalog/reminders')->assertOk()->viewData('page')['props'];
        $row = $page['reminders'][0];
        $this->assertEqualsCanonicalizing([$this->bruno->email, $this->carla->email], collect($row['recipients'])->pluck('email')->all());
        $this->assertEqualsCanonicalizing([$this->bruno->id, $this->carla->id], $row['recipientIds']);
        $this->assertEqualsCanonicalizing([$this->ana->id, $this->bruno->id, $this->carla->id], collect($page['recipientOptions'])->pluck('id')->all(), 'the options are the members of this Workspace');
    }

    public function test_the_options_and_the_validation_only_accept_enabled_members_of_this_workspace_with_access(): void
    {
        $stranger = $this->member($this->other, 'admin');
        $disabled = $this->member($this->ws, 'admin');
        $disabled->forceFill(['is_active' => false])->save();
        Role::findOrCreate('sin-acceso', 'web')->syncPermissions(['view-dashboard']);
        $blind = $this->member($this->ws, 'sin-acceso');
        $noMail = User::factory()->create(['email' => 'sin-correo@example.com']);
        $this->ws->users()->attach($noMail->id, ['role' => 'admin']);

        $options = collect($this->get('/catalog/reminders')->viewData('page')['props']['recipientOptions'])->pluck('id')->all();
        foreach ([$stranger, $disabled, $blind] as $excluded) {
            $this->assertNotContains($excluded->id, $options);
        }
        $this->assertContains($noMail->id, $options);

        foreach ([$stranger, $disabled, $blind] as $excluded) {
            $this->post('/catalog/reminders', $this->payload(['recipient_ids' => [$this->bruno->id, $excluded->id]]))->assertSessionHasErrors('recipient_ids');
        }

        $this->post('/catalog/reminders', $this->payload(['recipient_ids' => []]))->assertSessionHasErrors('recipient_ids');
        $this->post('/catalog/reminders', $this->payload(['recipient_ids' => ['abc']]))->assertSessionHasErrors('recipient_ids');
        $this->assertSame(0, CatalogReminder::count(), 'nothing was saved by a refused form');
    }

    public function test_a_form_that_names_nobody_keeps_the_creator_as_the_recipient(): void
    {
        $this->post('/catalog/reminders', $this->payload())->assertSessionHasNoErrors();
        $reminder = CatalogReminder::firstOrFail();

        $this->assertSame([$this->ana->id], $reminder->recipients()->pluck('users.id')->all());
    }

    public function test_the_email_goes_to_each_chosen_person_and_only_to_them_once(): void
    {
        $reminder = $this->create([$this->bruno->id, $this->carla->id]);
        $this->due($reminder);

        app(CatalogReminders::class)->send($reminder->id);
        app(CatalogReminders::class)->send($reminder->id); // a repeated job does nothing

        Mail::assertSent(CatalogReminderMail::class, 2);
        Mail::assertSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->bruno->email) && count($mail->to) === 1);
        Mail::assertSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->carla->email) && count($mail->to) === 1);
        Mail::assertNotSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->ana->email));

        $sent = $reminder->fresh();
        $this->assertSame([CatalogReminder::SENT, '2 destinatarios'], [$sent->status, $sent->sent_to]);
        $this->assertSame(2, $reminder->recipients()->wherePivotNotNull('sent_at')->count());
    }

    public function test_the_mail_says_who_scheduled_it_when_the_recipient_is_somebody_else(): void
    {
        $reminder = $this->create([$this->bruno->id, $this->ana->id]);
        $this->due($reminder);

        app(CatalogReminders::class)->send($reminder->id);

        Mail::assertSent(CatalogReminderMail::class, function ($mail) {
            $rendered = $mail->render();

            return $mail->hasTo($this->bruno->email) ? str_contains($rendered, 'por '.$this->ana->name) : str_contains($rendered, 'por ti');
        });
    }

    public function test_a_person_who_can_no_longer_receive_it_is_skipped_at_send_time_and_the_others_still_get_it(): void
    {
        $reminder = $this->create([$this->bruno->id, $this->carla->id]);
        $this->carla->forceFill(['is_active' => false])->save();
        $this->due($reminder);

        app(CatalogReminders::class)->send($reminder->id);

        Mail::assertSent(CatalogReminderMail::class, 1);
        Mail::assertSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->bruno->email));
        $sent = $reminder->fresh();
        $this->assertSame([CatalogReminder::SENT, $this->bruno->email, 'partial_recipients'], [$sent->status, $sent->sent_to, $sent->reason]);
        $this->assertSame('recipient_inactive', $reminder->recipients()->whereKey($this->carla->id)->first()->pivot->skip_reason);
    }

    public function test_when_nobody_can_receive_it_the_reminder_is_skipped_and_says_so(): void
    {
        $reminder = $this->create([$this->bruno->id]);
        $this->ws->users()->detach($this->bruno->id);
        $this->due($reminder);

        app(CatalogReminders::class)->send($reminder->id);

        Mail::assertNothingSent();
        $this->assertSame([CatalogReminder::SKIPPED, 'no_valid_recipient'], [$reminder->fresh()->status, $reminder->fresh()->reason]);
    }

    public function test_a_retry_after_a_partial_failure_never_repeats_the_email_to_who_already_received_it(): void
    {
        $reminder = $this->create([$this->bruno->id, $this->carla->id]);
        $this->due($reminder);
        // The first attempt reaches Bruno and Carla's delivery fails afterwards; the reminder waits for another attempt.
        $reminder->recipients()->updateExistingPivot($this->bruno->id, ['sent_at' => now(), 'sent_to' => $this->bruno->email]);
        CatalogReminder::whereKey($reminder->id)->update(['attempts' => 1]);

        app(CatalogReminders::class)->send($reminder->id);

        Mail::assertSent(CatalogReminderMail::class, 1);
        Mail::assertSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->carla->email));
        Mail::assertNotSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->bruno->email));
        $this->assertSame(CatalogReminder::SENT, $reminder->fresh()->status);
    }

    public function test_editing_changes_the_recipients_only_for_the_creator_and_keeps_the_ones_not_named(): void
    {
        $reminder = $this->create([$this->bruno->id]);

        $this->put("/catalog/reminders/{$reminder->id}", $this->payload(['recipient_ids' => [$this->carla->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$this->carla->id], $reminder->recipients()->pluck('users.id')->all());

        $this->put("/catalog/reminders/{$reminder->id}", $this->payload(['subject' => 'Otro asunto']))->assertSessionHasNoErrors();
        $this->assertSame([$this->carla->id], $reminder->recipients()->pluck('users.id')->all(), 'an edit that names nobody keeps the recipients');

        $this->actingAs($this->bruno)->withSession(['workspace_id' => $this->ws->id]);
        $this->put("/catalog/reminders/{$reminder->id}", $this->payload(['recipient_ids' => [$this->bruno->id]]))->assertForbidden();
        $this->assertSame([$this->carla->id], $reminder->recipients()->pluck('users.id')->all());
    }

    public function test_a_reminder_of_another_workspace_is_not_reachable_and_a_foreign_recipient_never_gets_mail(): void
    {
        $reminder = $this->create([$this->bruno->id]);
        $foreign = $this->member($this->other, 'admin');
        $this->actingAs($foreign)->withSession(['workspace_id' => $this->other->id]);

        $this->put("/catalog/reminders/{$reminder->id}", $this->payload(['recipient_ids' => [$foreign->id]]))->assertNotFound();
        $this->assertSame([$this->bruno->id], $reminder->recipients()->pluck('users.id')->all());

        // Even a row forged straight in the database for a foreign person is refused when sending.
        $reminder->recipients()->attach($foreign->id, ['workspace_id' => $this->ws->id]);
        $this->due($reminder);
        app(CatalogReminders::class)->send($reminder->id);

        Mail::assertNotSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($foreign->email));
        Mail::assertSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->bruno->email));
    }
}
