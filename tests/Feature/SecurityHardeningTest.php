<?php

namespace Tests\Feature;

use App\Models\ApiCallLog;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use App\Services\OperationalAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Findings S6 to S13 of RECOMENDACIONES.md §68: a per-IP limit on failed logins, an explicit fillable list instead of `$guarded = []`, a general limit on the signed-in
 * application, one Wompi invalid-signature alert per window, no signed-URL storage routes, and a diagnosis warning for a wildcard proxy list.
 */
class SecurityHardeningTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('CLIENTE_A', ['dashboard']);
    }

    private function pickWorkspace(): void
    {
        $this->post('/pre-login', ['workspace_code' => 'CLIENTE_A']);
    }

    private function failedLogin(string $email, array $server = [])
    {
        return $this->withServerVariables($server)->post('/login', ['email' => $email, 'password' => 'wrong-password']);
    }

    // --- S6: login ---------------------------------------------------------------------------------------------------------------

    public function test_one_address_cannot_try_many_accounts_and_the_block_also_stops_a_correct_password(): void
    {
        $legit = $this->member($this->workspace);
        $this->pickWorkspace();

        $max = (int) config('auth.login_throttle.ip_max_attempts');

        // Many DIFFERENT accounts, one failure each: the per-account limit never triggers, the per-IP one must.
        for ($i = 1; $i <= $max; $i++) {
            $this->failedLogin("persona{$i}@example.test")->assertSessionHasErrors('email');
        }

        $blocked = $this->post('/login', ['email' => $legit->email, 'password' => 'password']);
        $blocked->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotSame(trans('auth.failed'), session('errors')->get('email')[0], 'the message must be the throttle one, not a plain failure');
    }

    public function test_another_address_is_not_blocked_by_the_first_one(): void
    {
        $legit = $this->member($this->workspace);
        $this->pickWorkspace();

        for ($i = 1; $i <= (int) config('auth.login_throttle.ip_max_attempts'); $i++) {
            $this->failedLogin("persona{$i}@example.test");
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->post('/login', ['email' => $legit->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($legit);
    }

    public function test_a_few_typos_behind_a_shared_address_never_lock_anyone_out(): void
    {
        $legit = $this->member($this->workspace);
        $this->pickWorkspace();

        for ($i = 1; $i <= 10; $i++) {
            $this->failedLogin("otra{$i}@example.test");
        }

        $this->post('/login', ['email' => $legit->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($legit);
    }

    public function test_a_successful_login_does_not_reset_the_address_limit(): void
    {
        $legit = $this->member($this->workspace);
        $this->pickWorkspace();
        $max = (int) config('auth.login_throttle.ip_max_attempts');

        for ($i = 1; $i < $max; $i++) {
            $this->failedLogin("persona{$i}@example.test");
        }

        $this->post('/login', ['email' => $legit->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticated();
        $this->post('/logout');
        $this->pickWorkspace();

        $this->failedLogin('ultima@example.test'); // the max-th failure from this address
        $this->post('/login', ['email' => $legit->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_forged_forwarded_for_header_cannot_dodge_the_address_limit_when_no_proxy_is_trusted(): void
    {
        $legit = $this->member($this->workspace);
        $this->pickWorkspace();

        // Every attempt claims a different client address in X-Forwarded-For; without a trusted proxy list the connection address is what counts.
        for ($i = 1; $i <= (int) config('auth.login_throttle.ip_max_attempts'); $i++) {
            $this->failedLogin("persona{$i}@example.test", ['HTTP_X_FORWARDED_FOR' => "198.51.100.{$i}"]);
        }

        $this->post('/login', ['email' => $legit->email, 'password' => 'password'], ['X-Forwarded-For' => '203.0.113.200'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_per_account_limit_still_applies(): void
    {
        $legit = $this->member($this->workspace);
        $this->pickWorkspace();

        for ($i = 1; $i <= (int) config('auth.login_throttle.account_max_attempts'); $i++) {
            $this->failedLogin($legit->email);
        }

        $this->post('/login', ['email' => $legit->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // --- S7: mass assignment -----------------------------------------------------------------------------------------------------

    public function test_the_models_that_used_an_empty_guard_now_reject_unlisted_attributes(): void
    {
        foreach ([ApiCallLog::class, KnowledgeDocument::class, KnowledgeChunk::class] as $class) {
            $model = new $class;
            $this->assertNotSame([], $model->getFillable(), "{$class} needs an explicit fillable list");
            $model->fill(['id' => 99, 'attempts' => 7, 'is_admin' => true, 'unknown_column' => 'x']);

            $this->assertNull($model->getAttribute('id'), "{$class}: id must not be mass assignable");
            $this->assertNull($model->getAttribute('unknown_column'));
        }

        $document = new KnowledgeDocument;
        $document->fill(['title' => 'Manual', 'status' => 'pending', 'chunks_count' => 500, 'attempts' => 9, 'error_code' => 'x']);

        $this->assertSame('Manual', $document->title);
        $this->assertNull($document->chunks_count, 'counters are written by KnowledgeBase queries only');
        $this->assertNull($document->attempts);
        $this->assertNull($document->error_code);
    }

    // --- S10: general limit on the signed-in application --------------------------------------------------------------------------

    public function test_every_route_behind_the_workspace_context_carries_the_general_limit(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (in_array('workspace', $middleware, true) && ! in_array('throttle:authenticated', $middleware, true)) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_the_general_limit_answers_429_with_retry_after_and_is_per_person(): void
    {
        config(['auth.authenticated_per_minute' => 3]);
        $first = $this->member($this->workspace);
        $second = $this->member($this->workspace);

        $this->actingAs($first)->withSession(['workspace_id' => $this->workspace->id]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/notifications')->assertOk();
        }

        $this->getJson('/notifications')->assertStatus(429)->assertHeader('Retry-After');

        // Someone else is not affected by the first person's bucket.
        $this->app['auth']->forgetGuards();
        $this->actingAs($second)->withSession(['workspace_id' => $this->workspace->id]);
        $this->getJson('/notifications')->assertOk();
    }

    public function test_the_audit_export_keeps_its_own_tighter_limit(): void
    {
        $limit = collect(Route::getRoutes()->getByName('audit.export')->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'throttle:'.'10'));

        $this->assertSame('throttle:10,1', $limit);
    }

    // --- S11: invalid Wompi signatures --------------------------------------------------------------------------------------------

    public function test_a_flood_of_invalid_wompi_signatures_raises_one_alert_and_still_answers_400(): void
    {
        $this->mock(OperationalAlert::class, fn ($mock) => $mock->shouldReceive('raise')->once()->with('wompi_event_invalid_signature'));

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/webhooks/wompi', ['event' => 'transaction.updated', 'signature' => ['checksum' => 'x', 'properties' => ['transaction.id']], 'timestamp' => 1])->assertStatus(400);
        }
    }

    // --- S12: no storage routes ---------------------------------------------------------------------------------------------------

    public function test_the_local_disk_registers_no_signed_url_storage_routes(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->get('/storage/anything.txt')->assertNotFound();
        $this->put('/storage/anything.txt')->assertNotFound();
    }

    // --- file routes keep refusing a path the visitor chooses ------------------------------------------------------------------------------

    public function test_a_manual_key_with_a_path_is_not_found_and_reads_nothing(): void
    {
        $this->actingAs($this->member($this->workspace))->withSession(['workspace_id' => $this->workspace->id]);

        foreach (['..%2F..%2F.env', '..%2F..%2Fcomposer.json', '%2Fetc%2Fpasswd', 'nope'] as $key) {
            $this->get("/help/manuals/{$key}")->assertNotFound();
        }
    }

    // --- S13: wildcard proxies ------------------------------------------------------------------------------------------------------------

    public function test_the_diagnosis_warns_about_a_wildcard_proxy_list_in_production_only_when_it_is_one(): void
    {
        $this->app['env'] = 'production';

        config(['app.trusted_proxies' => '*']);
        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('TRUSTED_PROXIES=*', Artisan::output());

        config(['app.trusted_proxies' => '10.0.0.0/8']);
        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('TRUSTED_PROXIES no es un comodín', Artisan::output());
    }
}
