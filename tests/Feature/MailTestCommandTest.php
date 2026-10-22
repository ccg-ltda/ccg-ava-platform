<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SignupRequest;
use App\Models\SignupVerification;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MailAvailability;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * `mail:test` sends one message to an explicit address through the configured mailer and touches no business data. Nothing here sends real mail: the
 * mailer is faked (or made to fail), so these tests prove the command and the configuration rules, not that Google delivers anything.
 */
class MailTestCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'abcdwxyzabcdwxyz';

    /** A Google Workspace SMTP configuration as .env would give it (the credentials are test values, not real). */
    private function gmail(array $override = []): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => array_replace([
            'transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'envia@empresa.co', 'password' => self::PASSWORD,
        ], $override), 'mail.from.address' => 'envia@empresa.co']);
    }

    /** @return list<int> counts of everything the sign-up and the contracting create */
    private function business(): array
    {
        return [Organization::count(), Workspace::count(), User::count(), Subscription::count(), SignupRequest::count(), SignupVerification::count()];
    }

    public function test_it_needs_a_valid_explicit_recipient_and_sends_nothing_otherwise(): void
    {
        Mail::fake();
        $this->gmail();

        $this->assertSame(1, Artisan::call('mail:test', ['recipient' => 'no-es-un-correo']));
        Mail::assertNothingSent();
    }

    public function test_it_refuses_a_mailer_that_does_not_deliver_and_says_nothing_was_sent(): void
    {
        Mail::fake();

        // The testing mailer is `array`; `log` and the example sender count as not configured too.
        $this->assertSame(1, Artisan::call('mail:test', ['recipient' => 'yo@empresa.co']));
        $this->assertStringContainsString('no se envió nada', Artisan::output());

        config(['mail.default' => 'log']);
        $this->assertSame(1, Artisan::call('mail:test', ['recipient' => 'yo@empresa.co']));
        Mail::assertNothingSent();
    }

    public function test_it_sends_one_message_to_the_recipient_and_never_claims_it_reached_the_inbox(): void
    {
        Mail::fake();
        $this->gmail();
        $before = $this->business();

        $this->assertSame(0, Artisan::call('mail:test', ['recipient' => 'yo@empresa.co']));
        $output = Artisan::output();

        $this->assertStringContainsString('NO confirma', $output);
        $this->assertStringContainsString('smtp.gmail.com:587', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertSame($before, $this->business(), 'the test touches no business data');
    }

    public function test_an_smtp_failure_is_reported_with_its_cause_and_without_secrets(): void
    {
        $this->gmail();
        Mail::shouldReceive('raw')->once()->andThrow(new TransportException('Failed to authenticate on SMTP server with username "envia@empresa.co" and password "'.self::PASSWORD.'": 535 5.7.8 Username and Password not accepted.'));

        $this->assertSame(1, Artisan::call('mail:test', ['recipient' => 'yo@empresa.co']));
        $output = Artisan::output();

        $this->assertStringContainsString('autenticación rechazada', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertStringNotContainsString('envia@empresa.co', $output);
    }

    public function test_it_has_no_http_route(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase('mailtest', $route->uri());
            $this->assertStringNotContainsStringIgnoringCase('mail-test', $route->uri());
        }
    }

    public function test_a_smtp_transport_counts_as_real_only_with_a_remote_server_and_the_gmail_credentials(): void
    {
        $this->gmail();
        $this->assertTrue(MailAvailability::deliversRealMail());

        foreach ([['username' => ''], ['password' => null], ['host' => '127.0.0.1'], ['host' => 'localhost'], ['host' => '']] as $broken) {
            $this->gmail($broken);
            $this->assertFalse(MailAvailability::deliversRealMail(), json_encode(array_keys($broken)).' is not a working configuration');
        }

        $this->gmail(['host' => 'smtp.otro-proveedor.com', 'username' => null, 'password' => null]); // another provider may relay without credentials
        $this->assertTrue(MailAvailability::deliversRealMail());
    }

    public function test_the_smtp_mailer_has_a_timeout_and_the_example_documents_the_google_settings(): void
    {
        $this->assertNotNull(config('mail.mailers.smtp.timeout'), 'a server that does not answer must not hang the sign-up page');

        $example = file_get_contents(base_path('.env.example'));
        foreach (['MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'] as $variable) {
            $this->assertStringContainsString($variable.'=', $example);
        }
        $this->assertStringContainsString('smtp.gmail.com', $example);
    }

    public function test_the_sign_up_mails_its_code_through_the_configured_mailer_and_logs_neither_the_code_nor_the_address(): void
    {
        Mail::fake();
        $this->gmail();
        $this->seed(ModuleCatalogSeeder::class);
        $category = tap(ModuleCategory::where('key', 'health')->firstOrFail(), fn ($c) => $c->update(['is_public' => true]));
        Plan::create(['name' => 'Starter', 'module_category_id' => $category->id, 'price_monthly_cents' => 4990000, 'currency' => 'COP', 'is_public' => true])
            ->modules()->sync(Module::whereIn('key', ['dashboard'])->pluck('id'));
        Log::spy();

        $this->post('/registro/correo', ['email' => 'ana@empresa.co'])->assertSessionHasNoErrors();

        Mail::assertSent(SignupCodeMail::class, 1);
        $code = Mail::sent(SignupCodeMail::class)->last()->code;
        $this->assertNotSame($code, SignupVerification::firstOrFail()->code_hash);
        $this->assertSame([0, 0, 0, 0], [Organization::count(), Workspace::count(), User::count(), Subscription::count()], 'sending the code activates nothing');

        foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, [\Mockery::on(fn ($m) => str_contains((string) $m, $code) || str_contains((string) $m, 'ana@empresa.co')), \Mockery::any()]);
        }
    }
}
