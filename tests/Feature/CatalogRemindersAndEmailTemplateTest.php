<?php

namespace Tests\Feature;

use App\Jobs\SendCatalogReminder;
use App\Mail\CatalogReminderMail;
use App\Mail\SignupCodeMail;
use App\Models\AuditLog;
use App\Models\CatalogReminder;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ResetPasswordNotification;
use App\Services\CatalogReminders;
use App\Services\EmailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Block 5. Reminders of the Catálogo are scheduled emails to THEIR CREATOR: validated and owned on the server, handed to the queue once, sent by one worker,
 * retried a bounded number of times, never marked sent unless the mailer accepted them, and never resent by themselves when it is uncertain. The global HTML
 * template of the platform's emails is data: whitelisted, escaped, never executed, administered only by the platform inside Desarrollo_DEV, with a safe fallback.
 * No real mail is ever sent (the Mail facade is faked or mocked). SQLite can not prove the row-level locking PostgreSQL gives the claims: see RECOMENDACIONES §42.
 */
class CatalogRemindersAndEmailTemplateTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $ws;

    private Workspace $other;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->ws = $this->bookingWorkspace('TIENDA', ['dashboard', 'product_catalog', 'settings']);
        $this->other = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $this->ana = $this->member($this->ws, 'admin');
        Mail::fake();
    }

    private function as(User $user, ?Workspace $workspace = null): void
    {
        $this->actingAs($user)->withSession(['workspace_id' => ($workspace ?? $this->ws)->id]);
    }

    private function when(string $modify = '+2 hours', ?Workspace $workspace = null): string
    {
        return now()->setTimezone(($workspace ?? $this->ws)->settingsOrDefault()->timezone)->modify($modify)->format('Y-m-d\TH:i');
    }

    private function payload(array $override = []): array
    {
        return $override + ['subject' => 'RECORDATORIO ALISTAR PEDIDO', 'body' => "Alistar el pedido de Laura antes de las 2:00 p. m.\n\nVerificar existencias.", 'scheduled_at' => $this->when()];
    }

    private function reminder(array $attrs = [], ?User $creator = null): CatalogReminder
    {
        $reminder = new CatalogReminder(['subject' => 'Aviso', 'body' => 'Texto', 'scheduled_at' => now()->addHour()]);
        $reminder->workspace_id = $this->ws->id;
        $reminder->created_by = ($creator ?? $this->ana)->id;
        $reminder->status = CatalogReminder::SCHEDULED;
        $reminder->save();

        $attrs && CatalogReminder::whereKey($reminder->id)->update($attrs);

        return $reminder->fresh();
    }

    private function runWorker(CatalogReminder $reminder): void
    {
        (new SendCatalogReminder($reminder->id))->handle(app(CatalogReminders::class));
    }

    // --- creating ------------------------------------------------------------------------------------------------------------------

    public function test_a_member_schedules_a_reminder_for_themselves_and_the_server_owns_who_and_where(): void
    {
        $this->as($this->ana);
        $stranger = $this->member($this->other, 'admin');

        $this->post('/catalog/reminders', $this->payload([
            'created_by' => $stranger->id, 'workspace_id' => $this->other->id, 'recipient' => 'otro@example.com', 'email' => 'otro@example.com', 'status' => 'sent', 'attempts' => 9,
        ]))->assertSessionHasNoErrors();

        $reminder = CatalogReminder::firstOrFail();
        $this->assertSame([$this->ana->id, $this->ws->id, CatalogReminder::SCHEDULED, 0], [$reminder->created_by, $reminder->workspace_id, $reminder->status, $reminder->attempts]);
        $this->assertSame('RECORDATORIO ALISTAR PEDIDO', $reminder->subject);
        // The form time is the Workspace's local time: stored in UTC.
        $this->assertSame($this->when(), $reminder->scheduled_at->setTimezone($this->ws->settingsOrDefault()->timezone)->format('Y-m-d\TH:i'));
        $this->assertTrue(AuditLog::where('resource_type', 'catalog_reminder')->where('workspace_id', $this->ws->id)->where('user_id', $this->ana->id)->exists());
        $this->assertSame(0, CatalogReminder::where('workspace_id', $this->other->id)->count());
    }

    public function test_the_form_is_validated_on_the_server_with_clear_messages(): void
    {
        $this->as($this->ana);

        $this->post('/catalog/reminders', $this->payload(['scheduled_at' => $this->when('-1 hour')]))->assertSessionHasErrors(['scheduled_at' => 'La fecha y la hora deben ser futuras.']);
        $this->post('/catalog/reminders', $this->payload(['scheduled_at' => $this->when('+400 days')]))->assertSessionHasErrors('scheduled_at');
        $this->post('/catalog/reminders', $this->payload(['scheduled_at' => 'mañana']))->assertSessionHasErrors('scheduled_at');
        $this->post('/catalog/reminders', $this->payload(['subject' => '']))->assertSessionHasErrors('subject');
        $this->post('/catalog/reminders', $this->payload(['subject' => "Dos\nlíneas"]))->assertSessionHasErrors('subject');
        $this->post('/catalog/reminders', $this->payload(['subject' => str_repeat('a', 161)]))->assertSessionHasErrors('subject');
        $this->post('/catalog/reminders', $this->payload(['body' => '   ']))->assertSessionHasErrors('body');
        $this->post('/catalog/reminders', $this->payload(['body' => str_repeat('a', 5001)]))->assertSessionHasErrors('body');
        $this->assertSame(0, CatalogReminder::count());
    }

    // --- permissions and isolation -------------------------------------------------------------------------------------------------

    public function test_permissions_and_workspaces_are_enforced_on_every_route(): void
    {
        $mine = $this->reminder();
        $theirs = new CatalogReminder(['subject' => 'Ajeno', 'body' => 'x', 'scheduled_at' => now()->addHour()]);
        $theirs->forceFill(['workspace_id' => $this->other->id, 'created_by' => $this->member($this->other, 'admin')->id, 'status' => CatalogReminder::SCHEDULED])->save();

        // A role that reads the catalog but may not read reminders.
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-catalog']);
        $this->as($this->member($this->ws, 'lector'));
        $this->get('/catalog/reminders')->assertForbidden();

        // A role that reads reminders but may not manage them.
        Role::findOrCreate('lector2', 'web')->syncPermissions(['view-catalog', 'view-catalog-reminders']);
        $this->as($this->member($this->ws, 'lector2'));
        $this->get('/catalog/reminders')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false)->has('reminders', 1));
        $this->post('/catalog/reminders', $this->payload())->assertForbidden();
        $this->put("/catalog/reminders/{$mine->id}", $this->payload())->assertForbidden();
        $this->post("/catalog/reminders/{$mine->id}/cancel")->assertForbidden();

        // The module must be in the plan.
        $noModule = $this->bookingWorkspace('SINCAT', ['dashboard']);
        $this->as($this->member($noModule, 'admin'), $noModule);
        $this->get('/catalog/reminders')->assertForbidden();

        // Another Workspace: its ids do not exist here, the list never mixes them, and nothing changes there.
        $this->as($this->ana);
        $this->get('/catalog/reminders')->assertInertia(fn ($page) => $page->where('reminders', fn ($rows) => collect($rows)->pluck('subject')->all() === ['Aviso']));
        $this->put("/catalog/reminders/{$theirs->id}", $this->payload(['subject' => 'Hackeado']))->assertNotFound();
        $this->post("/catalog/reminders/{$theirs->id}/cancel")->assertNotFound();
        $this->post("/catalog/reminders/{$theirs->id}/retry")->assertNotFound();
        $this->assertSame(['Ajeno', CatalogReminder::SCHEDULED], [$theirs->fresh()->subject, $theirs->fresh()->status]);

        // Guests are sent to log in.
        auth()->logout();
        $this->flushSession();
        $this->get('/catalog/reminders')->assertRedirect();
    }

    public function test_only_the_creator_edits_and_anyone_who_manages_may_cancel_with_audit(): void
    {
        $reminder = $this->reminder();
        $beto = $this->member($this->ws, 'admin');

        $this->as($beto);
        $this->put("/catalog/reminders/{$reminder->id}", $this->payload(['subject' => 'Cambiado por otro']))->assertForbidden();
        $this->post("/catalog/reminders/{$reminder->id}/retry")->assertForbidden();
        $this->assertSame('Aviso', $reminder->fresh()->subject);

        $this->as($this->ana);
        $this->put("/catalog/reminders/{$reminder->id}", $this->payload(['subject' => 'Nuevo asunto', 'created_by' => $beto->id]))->assertSessionHasNoErrors();
        $this->assertSame([$this->ana->id, 'Nuevo asunto'], [$reminder->fresh()->created_by, $reminder->fresh()->subject]);

        $this->as($beto);
        $this->post("/catalog/reminders/{$reminder->id}/cancel")->assertSessionHasNoErrors();
        $fresh = $reminder->fresh();
        $this->assertSame([CatalogReminder::CANCELLED, $beto->id], [$fresh->status, $fresh->cancelled_by]);
        $this->assertTrue(AuditLog::where('resource_type', 'catalog_reminder')->where('user_id', $beto->id)->exists());

        // Cancelled is final: it can not be edited, cancelled again or retried.
        $this->as($this->ana);
        $this->put("/catalog/reminders/{$reminder->id}", $this->payload())->assertSessionHasErrors('status');
        $this->post("/catalog/reminders/{$reminder->id}/cancel")->assertSessionHasErrors('status');
        $this->post("/catalog/reminders/{$reminder->id}/retry")->assertSessionHasErrors('status');
        $this->assertSame(CatalogReminder::CANCELLED, $reminder->fresh()->status);
    }

    // --- the schedule: not before, once, by one worker ---------------------------------------------------------------------------

    public function test_nothing_is_sent_before_its_time_and_the_scheduler_queues_each_reminder_once(): void
    {
        Queue::fake();
        $reminder = $this->reminder(['scheduled_at' => now()->addMinutes(30)]);

        Artisan::call('catalog:send-reminders');
        Queue::assertNothingPushed();
        $this->assertSame(CatalogReminder::SCHEDULED, $reminder->fresh()->status);

        $this->travel(31)->minutes();
        Artisan::call('catalog:send-reminders');
        Artisan::call('catalog:send-reminders');
        Artisan::call('catalog:send-reminders');

        Queue::assertPushed(SendCatalogReminder::class, 1);
        $this->assertSame(CatalogReminder::QUEUED, $reminder->fresh()->status);
    }

    public function test_a_due_reminder_is_emailed_to_its_creator_only_once_and_marked_sent_after_the_mailer_accepts_it(): void
    {
        Queue::fake();
        $reminder = $this->reminder(['scheduled_at' => now()->subMinute()]);
        Artisan::call('catalog:send-reminders');

        $this->runWorker($reminder);
        $this->runWorker($reminder); // a repeated job (a second worker) does nothing

        Mail::assertSent(CatalogReminderMail::class, 1);
        Mail::assertSent(CatalogReminderMail::class, fn ($mail) => $mail->hasTo($this->ana->email) && count($mail->to) === 1 && $mail->envelope()->subject === 'Aviso' && $mail->reminder->is($reminder));
        $sent = $reminder->fresh();
        $this->assertSame([CatalogReminder::SENT, 1, $this->ana->email], [$sent->status, $sent->attempts, $sent->sent_to]);
        $this->assertNotNull($sent->sent_at);

        // The history survives: the sent one can not be edited or cancelled.
        $this->as($this->ana);
        $this->put("/catalog/reminders/{$reminder->id}", $this->payload())->assertSessionHasErrors('status');
        $this->post("/catalog/reminders/{$reminder->id}/cancel")->assertSessionHasErrors('status');
        $this->assertSame('Aviso', $sent->fresh()->subject);
        $this->assertTrue(AuditLog::where('resource_type', 'catalog_reminder')->where('actor', 'system')->exists());
    }

    public function test_two_workers_that_reach_the_same_queued_reminder_send_one_email(): void
    {
        $reminder = $this->reminder(['status' => CatalogReminder::QUEUED, 'scheduled_at' => now()->subMinute()]);

        app(CatalogReminders::class)->send($reminder->id);
        app(CatalogReminders::class)->send($reminder->id);

        Mail::assertSent(CatalogReminderMail::class, 1);
    }

    public function test_a_reminder_cancelled_after_it_was_queued_is_not_sent(): void
    {
        $reminder = $this->reminder(['status' => CatalogReminder::QUEUED, 'scheduled_at' => now()->subMinute()]);
        $this->as($this->ana);
        $this->post("/catalog/reminders/{$reminder->id}/cancel")->assertSessionHasNoErrors();

        $this->runWorker($reminder);

        Mail::assertNothingSent();
        $this->assertSame(CatalogReminder::CANCELLED, $reminder->fresh()->status);
    }

    // --- failures: bounded retries, never sent when the mailer refused ---------------------------------------------------------------

    public function test_a_failure_is_retried_a_bounded_number_of_times_and_never_marked_sent(): void
    {
        $reminder = $this->reminder(['status' => CatalogReminder::QUEUED, 'scheduled_at' => now()->subMinute()]);
        Mail::shouldReceive('to')->times(3)->andThrow(new \RuntimeException('SMTP no respondió para ana@example.com'));

        $this->runWorker($reminder);
        $first = $reminder->fresh();
        $this->assertSame([CatalogReminder::SCHEDULED, 1], [$first->status, $first->attempts]);
        $this->assertTrue($first->next_attempt_at->greaterThan(now()->addMinutes(4)));
        $this->assertStringNotContainsString('ana@example.com', (string) $first->reason, 'an address is masked in the stored reason');
        $this->assertStringContainsString('RuntimeException', (string) $first->reason);

        // Not before its next attempt.
        Queue::fake();
        Artisan::call('catalog:send-reminders');
        Queue::assertNothingPushed();

        $this->travel(6)->minutes();
        Artisan::call('catalog:send-reminders');
        Queue::assertPushed(SendCatalogReminder::class, 1);
        $this->runWorker($reminder);
        $this->assertSame([CatalogReminder::SCHEDULED, 2], [$reminder->fresh()->status, $reminder->fresh()->attempts]);

        $this->travel(31)->minutes();
        Artisan::call('catalog:send-reminders');
        $this->runWorker($reminder);

        $final = $reminder->fresh();
        $this->assertSame([CatalogReminder::FAILED, 3, null, null], [$final->status, $final->attempts, $final->sent_at, $final->next_attempt_at]);
        $this->assertGreaterThanOrEqual(3, AuditLog::where('resource_type', 'catalog_reminder')->where('outcome', 'failed')->count());

        // The creator can ask again, explicitly; and the reminder goes out when the mailer works.
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        Mail::fake();
        $this->as($this->ana);
        $this->post("/catalog/reminders/{$reminder->id}/retry")->assertSessionHasNoErrors();
        $this->assertSame([CatalogReminder::SCHEDULED, 0], [$reminder->fresh()->status, $reminder->fresh()->attempts]);
        Queue::fake();
        Artisan::call('catalog:send-reminders');
        $this->runWorker($reminder);
        $this->assertSame(CatalogReminder::SENT, $reminder->fresh()->status);
    }

    public function test_a_worker_that_died_mid_send_leaves_it_uncertain_and_it_is_never_resent_by_itself(): void
    {
        $sending = $this->reminder(['status' => CatalogReminder::SENDING, 'attempts' => 1, 'scheduled_at' => now()->subHour(), 'updated_at' => now()->subMinutes(30)]);
        $queued = $this->reminder(['status' => CatalogReminder::QUEUED, 'scheduled_at' => now()->subHour(), 'updated_at' => now()->subMinutes(30)]);
        $fresh = $this->reminder(['status' => CatalogReminder::SENDING, 'attempts' => 1, 'scheduled_at' => now()->subMinute(), 'updated_at' => now()->subMinute()]);

        Queue::fake();
        Artisan::call('catalog:send-reminders');

        $this->assertSame(CatalogReminder::UNCERTAIN, $sending->fresh()->status);
        $this->assertSame(CatalogReminder::QUEUED, $queued->fresh()->status, 'a lost queue goes back to waiting and is queued again in the same pass');
        $this->assertSame(CatalogReminder::SENDING, $fresh->fresh()->status, 'a recent one is left alone');
        Queue::assertPushed(SendCatalogReminder::class, fn ($job) => $job->reminderId === $queued->id);
        Queue::assertNotPushed(SendCatalogReminder::class, fn ($job) => $job->reminderId === $sending->id);

        // An uncertain one needs its creator's explicit decision.
        $this->as($this->ana);
        $this->post("/catalog/reminders/{$sending->id}/retry")->assertSessionHasNoErrors();
        $this->assertSame(CatalogReminder::SCHEDULED, $sending->fresh()->status);

        // And a crashed job is settled the same way.
        $crashed = $this->reminder(['status' => CatalogReminder::SENDING]);
        (new SendCatalogReminder($crashed->id))->failed(new \RuntimeException('killed'));
        $this->assertSame(CatalogReminder::UNCERTAIN, $crashed->fresh()->status);
    }

    public function test_a_reminder_that_can_not_be_delivered_as_written_is_skipped_and_says_why(): void
    {
        $gone = $this->member($this->ws, 'admin');
        $removed = $this->reminder(['status' => CatalogReminder::QUEUED], $gone);
        $this->ws->users()->detach($gone->id);
        $inactiveUser = $this->member($this->ws, 'admin');
        $inactive = $this->reminder(['status' => CatalogReminder::QUEUED], $inactiveUser);
        $inactiveUser->forceFill(['is_active' => false])->save();
        $late = $this->reminder(['status' => CatalogReminder::QUEUED, 'scheduled_at' => now()->subHours(30)]);

        foreach ([$removed, $inactive, $late] as $reminder) {
            $this->runWorker($reminder);
        }

        Mail::assertNothingSent();
        $this->assertSame([CatalogReminder::SKIPPED, 'creator_not_member'], [$removed->fresh()->status, $removed->fresh()->reason]);
        $this->assertSame([CatalogReminder::SKIPPED, 'creator_inactive'], [$inactive->fresh()->status, $inactive->fresh()->reason]);
        $this->assertSame([CatalogReminder::SKIPPED, 'expired'], [$late->fresh()->status, $late->fresh()->reason]);
    }

    public function test_without_a_real_mail_transport_it_is_not_marked_sent(): void
    {
        $reminder = $this->reminder(['status' => CatalogReminder::QUEUED, 'scheduled_at' => now()->subMinute()]);
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);

        $this->runWorker($reminder);

        Mail::assertNothingSent();
        $this->assertSame([CatalogReminder::FAILED, 'mail_not_configured'], [$reminder->fresh()->status, $reminder->fresh()->reason]);
    }

    public function test_the_reminder_email_escapes_what_the_member_wrote(): void
    {
        $reminder = $this->reminder(['subject' => 'Aviso <b>x</b>', 'body' => "Hola <script>alert(1)</script> & {{content}}\n\nSegundo párrafo"]);
        $mail = new CatalogReminderMail($reminder->load('workspace'));
        $html = $mail->render();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; {{content}}', $html, 'a placeholder inside the text stays text and is not substituted again');
        $this->assertStringContainsString('Segundo párrafo', $html);
        $this->assertStringContainsString('X-Ava-Reminder', implode(' ', array_keys($mail->headers()->text)));
    }

    // --- the global template ------------------------------------------------------------------------------------------------------

    private function platformAdmin(): User
    {
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        Role::findOrCreate('admin', 'web');
        $dev->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        return $user;
    }

    private function custom(string $marker = 'MARCA-PERSONALIZADA'): string
    {
        return '<html><body><table><tr><td><p>'.$marker.'</p>{{title}}{{content}}{{action}}{{footer}}</td></tr></table></body></html>';
    }

    public function test_only_the_platform_administration_in_the_administrative_workspace_reaches_the_template(): void
    {
        $this->platformAdmin();
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page->where('emailTemplate.isDefault', true)->where('emailTemplate.canEdit', true)->has('emailTemplate.placeholders.content'));

        // A client's administrator: no data in the page and 403 on every route, whatever they send.
        $this->as($this->ana);
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page->where('emailTemplate', null));
        $this->put('/settings/email-template', ['html' => $this->custom()])->assertForbidden();
        $this->post('/settings/email-template/preview', ['html' => $this->custom()])->assertForbidden();
        $this->post('/settings/email-template/restore')->assertForbidden();
        $this->assertSame(0, EmailTemplate::count());

        // A superuser acting inside a CLIENT Workspace is not the platform there either.
        $root = User::factory()->create();
        $root->forceFill(['is_superuser' => true])->save();
        $this->ws->users()->attach($root->id, ['role' => 'admin']);
        $this->as($root);
        $this->put('/settings/email-template', ['html' => $this->custom()])->assertForbidden();

        auth()->logout();
        $this->flushSession();
        $this->put('/settings/email-template', ['html' => $this->custom()])->assertRedirect();
    }

    public function test_the_platform_saves_previews_and_restores_the_template_for_real(): void
    {
        $admin = $this->platformAdmin();
        $templates = app(EmailTemplates::class);

        $this->put('/settings/email-template', ['html' => $this->custom()])->assertSessionHasNoErrors();
        $this->assertSame($this->custom(), EmailTemplate::where('key', 'global')->value('html'));
        $this->assertSame($admin->id, EmailTemplate::first()->updated_by);
        $this->get('/settings')->assertInertia(fn ($page) => $page->where('emailTemplate.isDefault', false)->where('emailTemplate.html', $this->custom()));
        $this->assertTrue(AuditLog::where('resource_type', 'email_template')->exists());

        // The preview uses fictitious data and sends nothing; it does not store.
        $before = EmailTemplate::first()->updated_at;
        $res = $this->postJson('/settings/email-template/preview', ['html' => $this->custom('OTRA-MARCA')])->assertOk();
        $this->assertStringContainsString('OTRA-MARCA', $res->json('html'));
        $this->assertStringContainsString('Vista previa del mensaje', $res->json('html'));
        $this->assertStringContainsString('123456', $res->json('html'));
        $this->assertStringContainsString('https://example.com/ejemplo', $res->json('html'));
        $this->assertSame([], $res->json('errors'));
        $this->assertSame($this->custom(), EmailTemplate::first()->html);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // Restore is a real replacement by the default the application maintains.
        $this->post('/settings/email-template/restore')->assertSessionHasNoErrors();
        $this->assertSame($templates->defaultHtml(), EmailTemplate::first()->html);
        $this->get('/settings')->assertInertia(fn ($page) => $page->where('emailTemplate.html', $templates->defaultHtml()));
        $this->assertGreaterThanOrEqual(2, AuditLog::where('resource_type', 'email_template')->count());
        $this->assertNotNull($before);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidTemplates(): array
    {
        $ok = '<html><body><p>{{content}}</p></body></html>';

        return [
            'script tag' => ['<html><body><script>alert(1)</script>{{content}}</body></html>', 'script'],
            'event handler' => ['<html><body><p onclick="x()">{{content}}</p></body></html>', 'onclick'],
            'javascript link' => ['<html><body><a href="javascript:alert(1)">x</a>{{content}}</body></html>', 'enlace'],
            'data link' => ['<html><body><a href="data:text/html;base64,AAAA">x</a>{{content}}</body></html>', 'enlace'],
            'unknown placeholder' => ['<html><body>{{secret}}{{content}}</body></html>', 'Marcador no permitido'],
            'blade echo' => ['<html><body>{{ $user->password }}{{content}}</body></html>', 'Marcador no permitido'],
            'blade raw' => ['<html><body>{!! $x !!}{{content}}</body></html>', 'Blade'],
            'blade directive' => ['<html><body>@php echo 1; @endphp {{content}}</body></html>', 'Blade'],
            'php tag' => ['<html><body><?php echo 1; ?>{{content}}</body></html>', 'PHP'],
            'config call' => ['<html><body>{{ config("app.key") }}{{content}}</body></html>', 'Marcador no permitido'],
            'style element' => ['<html><head><style>p{color:red}</style></head><body>{{content}}</body></html>', 'style'],
            'style url' => ['<html><body><p style="background:url(http://x/t.gif)">{{content}}</p></body></html>', 'estilo'],
            'image' => ['<html><body><img src="https://x/t.gif">{{content}}</body></html>', 'img'],
            'iframe' => ['<html><body><iframe src="https://x"></iframe>{{content}}</body></html>', 'iframe'],
            'form' => ['<html><body><form action="https://x">{{content}}</form></body></html>', 'form'],
            'meta refresh' => ['<html><head><meta http-equiv="refresh" content="0;url=https://x"></head><body>{{content}}</body></html>', 'meta'],
            'placeholder in attribute' => ['<html><body><a href="{{action}}">x</a>{{content}}</body></html>', 'atributo'],
            'comment' => ['<html><body><!-- hi -->{{content}}</body></html>', 'comentarios'],
            'missing content' => ['<html><body><p>{{title}}</p></body></html>', '{{content}}'],
            'stray braces' => ['<html><body>{{content}} }} {{</body></html>', 'llaves'],
            'empty' => ['   ', 'vacía'],
            'oversize' => [$ok.str_repeat('a', 61000), 'grande'],
        ];
    }

    #[DataProvider('invalidTemplates')]
    public function test_an_invalid_template_is_refused_on_save_and_on_preview_and_the_stored_one_is_kept(string $html, string $fragment): void
    {
        $this->platformAdmin();
        $this->put('/settings/email-template', ['html' => $this->custom()])->assertSessionHasNoErrors();

        $response = $this->put('/settings/email-template', ['html' => $html]);
        $response->assertSessionHasErrors('html');
        $this->assertSame($this->custom(), EmailTemplate::first()->html, 'what was stored does not change');

        // The same message the person sees, from the preview endpoint.
        $preview = $this->postJson('/settings/email-template/preview', ['html' => $html]);
        $messages = collect($preview->json('errors') ?? $preview->json('errors.html') ?? [])->flatten()->implode(' | ');
        $this->assertStringContainsString($fragment, $messages);
        $this->assertTrue($preview->status() === 422 || ($preview->status() === 200 && $preview->json('errors') !== [] && $preview->json('html') === null));
        Mail::assertNothingSent();
    }

    public function test_every_dynamic_value_is_escaped_and_only_safe_links_become_buttons(): void
    {
        $templates = app(EmailTemplates::class);

        $html = $templates->render('<script>alert("t")</script>', ['<img src=x onerror=alert(1)>', "línea 1\nlínea 2", ['code' => '<b>1</b>']], ['label' => '"><script>x</script>', 'url' => 'https://example.com/a?b=1&c=2']);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>1</b>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('línea 1<br>', str_replace("\n", '', $html));
        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $html);

        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', 'vbscript:x', 'ftp://example.com/x', '//example.com', 'https://user:pass@example.com/', 'https://example.com/"onmouseover="x', 'https://example.com/ x', '', 'mailto:a@b.co'] as $url) {
            $out = $templates->render('T', ['x'], ['label' => 'Abrir', 'url' => $url]);
            $this->assertStringNotContainsString('<a href', $out, "refused: {$url}");
        }

        $this->assertFalse($templates->safeUrl("https://example.com/\x00"));
    }

    public function test_a_template_is_never_executed_and_a_stored_one_that_is_not_valid_falls_back_to_the_default(): void
    {
        $templates = app(EmailTemplates::class);

        // Written straight to the database, bypassing the validation (an old rule, a manual edit): it must never reach an email.
        foreach (['<html><body>{{ 1+1 }} {{content}}</body></html>', '<html><body><?php echo "EJECUTADO"; ?>{{content}}</body></html>', '<html><body><script>x</script>{{content}}</body></html>', '@php echo "EJECUTADO"; @endphp {{content}}'] as $bad) {
            EmailTemplate::updateOrCreate(['key' => 'global'], ['html' => $bad]);

            $out = $templates->render('Título', ['Cuerpo']);
            $this->assertStringNotContainsString('EJECUTADO', $out);
            $this->assertStringNotContainsString('<script', $out);
            $this->assertStringNotContainsString('1+1', $out);
            $this->assertStringContainsString('Cuerpo', $out);
            $this->assertSame($templates->defaultHtml(), $templates->activeHtml());
            $this->assertTrue($templates->current()['storedInvalid']);
        }

        // Without the table (migration not applied) the default is used and nothing breaks.
        DB::statement('drop table email_templates');
        $this->assertStringContainsString('Cuerpo', $templates->render('Título', ['Cuerpo']));
    }

    // --- the existing emails use the template without losing their content --------------------------------------------------------------

    public function test_existing_emails_keep_their_content_and_use_the_global_template(): void
    {
        EmailTemplate::create(['key' => 'global', 'html' => $this->custom('MARCA-GLOBAL')]);

        $code = new SignupCodeMail('482913', 15);
        $this->assertSame('Tu código de verificación de Zonno Platform by CCG', $code->envelope()->subject);
        $html = $code->render();
        $this->assertStringContainsString('MARCA-GLOBAL', $html);
        $this->assertStringContainsString('482913', $html);
        $this->assertStringContainsString('15 minutos', $html);
        $text = (string) view('emails.signup-code', ['code' => '482913', 'minutes' => 15]);
        $this->assertStringContainsString('482913', $text);

        $user = User::factory()->create();
        $message = (new ResetPasswordNotification('tok123'))->toMail($user);
        $this->assertSame('Restablece tu contraseña', $message->subject);
        $this->assertStringContainsString('/reset-password/tok123', (string) $message->actionUrl);
        $rendered = (string) $message->render();
        $this->assertStringContainsString('MARCA-GLOBAL', $rendered);
        $this->assertStringContainsString('/reset-password/tok123', $rendered);
        $this->assertStringContainsString('60 minutos', $rendered);

        $reminder = $this->reminder();
        $this->assertStringContainsString('MARCA-GLOBAL', (new CatalogReminderMail($reminder->load('workspace')))->render());
    }

    public function test_the_brand_in_the_emails_comes_from_the_central_configuration(): void
    {
        config(['branding.name' => 'Zonno by CCG']);

        $html = app(EmailTemplates::class)->render('Hola', ['Texto']);
        $this->assertStringContainsString('Zonno by CCG', $html);
        $this->assertStringContainsString('Tu código de verificación de Zonno by CCG', (new SignupCodeMail('111111', 10))->render());
    }
}
