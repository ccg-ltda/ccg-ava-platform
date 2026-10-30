<?php

namespace Tests\Feature;

use App\Models\AgentToken;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use App\Services\AgentAccess;
use App\Services\AgentTokenNotices;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S8 (RECOMENDACIONES.md §75): chatbot access tokens last 90 days, record their use, can be revoked one by one, and are replaced WITHOUT interrupting n8n: the old token
 * is retired only after the new one has been used. An expiry is announced beforehand and is never silent. Existing tokens are adopted with an explicit rule.
 */
class AgentTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private AgentAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->access = app(AgentAccess::class);
        AgentAccess::forgetReady();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function bot(Workspace $workspace, string $name = 'Ventas'): Chatbot
    {
        return $workspace->chatbots()->create(['name' => $name, 'instructions' => 'Sé breve']);
    }

    private function read(string $token)
    {
        return $this->withToken($token)->getJson('/api/agent/config');
    }

    private function notifications(Workspace $workspace, ?string $type = null)
    {
        return WorkspaceNotification::where('workspace_id', $workspace->id)->when($type, fn ($q) => $q->where('type', $type))->get();
    }

    // --- validity, use, revocation --------------------------------------------------------------------------------------------------

    public function test_a_new_token_lasts_90_days_and_only_its_hash_is_stored(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $plain = $this->access->generate($bot);

        $row = AgentToken::where('chatbot_id', $bot->id)->firstOrFail();
        $this->assertSame(hash('sha256', $plain), DB::table('agent_tokens')->where('id', $row->id)->value('token_hash'));
        $this->assertTrue($row->expires_at->between(now()->addDays(90)->subMinute(), now()->addDays(90)->addMinute()));
        $this->assertSame(substr($plain, -4), $row->hint);
        $this->assertStringNotContainsString($plain, json_encode(DB::table('agent_tokens')->get()));
        $this->assertArrayNotHasKey('token_hash', $row->toArray());
        // The mirror columns follow the current token (the interface and older code read them).
        $this->assertSame(hash('sha256', $plain), DB::table('chatbots')->where('id', $bot->id)->value('agent_token_hash'));
    }

    public function test_a_current_token_is_accepted_and_its_first_and_last_use_are_recorded(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $plain = $this->access->generate($bot);

        $this->assertNull(AgentToken::first()->first_used_at);
        $this->read($plain)->assertOk()->assertJsonPath('chatbot.id', $bot->id);

        $row = AgentToken::first();
        $this->assertNotNull($row->first_used_at);
        $this->assertNotNull($row->last_used_at);
    }

    public function test_an_expired_token_is_refused_with_a_clear_reason_and_the_team_is_told_once_a_day(): void
    {
        $ws = $this->workspace('A');
        $bot = $this->bot($ws);
        $plain = $this->access->generate($bot);

        Carbon::setTestNow(now()->addDays(91));

        for ($i = 0; $i < 3; $i++) {
            $this->read($plain)->assertStatus(401)->assertJsonPath('reason', 'token_expired')->assertJsonMissingPath('chatbot');
        }

        $notices = $this->notifications($ws, 'agent_token_refused');
        $this->assertCount(1, $notices, 'a token presented again and again raises ONE notice a day');
        $this->assertSame('manage-agent-tokens', $notices->first()->permission);
        $this->assertStringNotContainsString($plain, json_encode($notices->first()->toArray()));

        Carbon::setTestNow(now()->addDay());
        $this->read($plain)->assertStatus(401);
        $this->assertCount(2, $this->notifications($ws, 'agent_token_refused'), 'a new day, a new notice');
    }

    public function test_a_revoked_token_is_refused_with_its_reason_and_an_unknown_one_is_just_invalid(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $plain = $this->access->generate($bot);
        $this->access->revoke($bot);

        $this->read($plain)->assertStatus(401)->assertJsonPath('reason', 'token_revoked');
        $this->assertNull($bot->fresh()->agent_token_hash);
        $this->read('ava_'.str_repeat('x', 40))->assertStatus(401)->assertJsonPath('message', 'Token inválido.');
        $this->read('not-a-token')->assertStatus(401);
    }

    public function test_generate_is_the_immediate_replacement_every_older_token_stops_at_once(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $old = $this->access->generate($bot);
        $new = $this->access->generate($bot);

        $this->read($old)->assertStatus(401)->assertJsonPath('reason', 'token_revoked');
        $this->read($new)->assertOk();
    }

    // --- rotation without interruption --------------------------------------------------------------------------------------------------

    public function test_rotation_keeps_the_old_token_working_until_the_new_one_is_used_and_then_retires_it(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $old = $this->access->generate($bot);
        $this->read($old)->assertOk();

        $new = $this->access->rotate($bot, null);

        // Both work; nothing was cut off by issuing the replacement.
        $this->read($old)->assertOk();
        $this->assertNotNull(AgentToken::where('token_hash', hash('sha256', $old))->first()->superseded_at);
        $this->assertNull(AgentToken::where('token_hash', hash('sha256', $old))->first()->revoked_at);

        // The first successful use of the NEW token is the proof that n8n holds it: the old one is retired.
        $this->read($new)->assertOk();
        $retired = AgentToken::where('token_hash', hash('sha256', $old))->first();
        $this->assertNotNull($retired->revoked_at);
        $this->assertSame('rotated', $retired->revoked_reason);
        $this->read($old)->assertStatus(401)->assertJsonPath('reason', 'token_revoked');
        $this->read($new)->assertOk();
        $this->assertSame(hash('sha256', $new), DB::table('chatbots')->where('id', $bot->id)->value('agent_token_hash'));
    }

    public function test_a_replacement_that_is_never_used_does_not_retire_the_working_token_and_is_replaced_by_the_next_one(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $old = $this->access->generate($bot);
        $this->read($old)->assertOk();

        $first = $this->access->rotate($bot);
        $second = $this->access->rotate($bot); // the first replacement was never used: it cannot be in n8n, so it is replaced

        $this->read($old)->assertOk();
        $this->read($first)->assertStatus(401)->assertJsonPath('reason', 'token_revoked');
        $this->assertSame('replaced_unused', AgentToken::where('token_hash', hash('sha256', $first))->first()->revoked_reason);
        $this->read($second)->assertOk();
        $this->assertSame('rotated', AgentToken::where('token_hash', hash('sha256', $old))->first()->revoked_reason);
    }

    public function test_a_failed_use_of_the_replacement_never_retires_the_old_token(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $old = $this->access->generate($bot);
        $new = $this->access->rotate($bot);

        $bot->update(['is_active' => false]);
        $this->read($new)->assertForbidden(); // refused before the use counts

        $this->assertNull(AgentToken::where('token_hash', hash('sha256', $old))->first()->revoked_at);
        $this->assertNull(AgentToken::where('token_hash', hash('sha256', $new))->first()->first_used_at);

        $bot->update(['is_active' => true]);
        $this->read($old)->assertOk();
    }

    public function test_a_rotation_without_a_ledger_falls_back_to_the_immediate_replacement_and_nothing_expires(): void
    {
        $bot = $this->bot($this->workspace('A'));
        Schema::drop('agent_tokens');
        AgentAccess::forgetReady();

        $first = $this->access->generate($bot);
        $this->read($first)->assertOk();
        Carbon::setTestNow(now()->addDays(400));
        $this->read($first)->assertOk(); // no ledger, no expiry: the documented fallback of a database that is not migrated yet

        $second = $this->access->rotate($bot);
        $this->read($second)->assertOk();
        $this->read($first)->assertStatus(401);
        $this->assertFalse($this->access->status($bot)['ledger']);
    }

    // --- scope and permissions ------------------------------------------------------------------------------------------------------------

    public function test_the_database_refuses_a_token_row_that_pairs_a_chatbot_with_another_workspace(): void
    {
        $a = $this->workspace('A');
        $b = $this->workspace('B');
        $bot = $this->bot($a);

        $this->expectException(QueryException::class);

        DB::table('agent_tokens')->insert(['chatbot_id' => $bot->id, 'workspace_id' => $b->id, 'token_hash' => str_repeat('a', 64), 'created_at' => now(), 'expires_at' => now()->addDays(90)]);
    }

    public function test_a_token_never_reads_or_revokes_anything_of_another_workspace(): void
    {
        $a = $this->workspace('A');
        $b = $this->workspace('B');
        $botA = $this->bot($a, 'Bot A');
        $botB = $this->bot($b, 'Bot B');
        $tokenA = $this->access->generate($botA);
        $this->access->generate($botB);
        $rowA = AgentToken::where('chatbot_id', $botA->id)->first();

        $this->read($tokenA)->assertOk()->assertJsonPath('chatbot.id', $botA->id)->assertJsonPath('workspace.code', 'A');
        $this->assertFalse($this->access->revokeToken($botB, $rowA->id), 'a token id of another chatbot is not revocable through this one');
        $this->assertNull($rowA->fresh()->revoked_at);
        $this->assertSame($a->id, $rowA->workspace_id);
    }

    public function test_rotate_and_individual_revocation_need_the_token_permission_and_the_own_workspace(): void
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, 'admin');
        $ws = Workspace::orderBy('id')->first();
        $bot = $this->bot($ws);
        $other = $this->bot($this->workspace('OTRO'), 'Ajeno');
        $this->access->generate($other);
        $foreign = AgentToken::where('chatbot_id', $other->id)->first();
        $this->access->generate($bot);

        $this->post("/chatbots/{$bot->id}/agent-token/rotate")->assertRedirect();
        $this->assertSame(2, AgentToken::where('chatbot_id', $bot->id)->whereNull('revoked_at')->count());

        $old = AgentToken::where('chatbot_id', $bot->id)->orderBy('id')->first();
        $this->delete("/chatbots/{$bot->id}/agent-token/{$old->id}")->assertRedirect();
        $this->assertNotNull($old->fresh()->revoked_at);

        // Another Workspace's chatbot or token: not found, and nothing changes.
        $this->post("/chatbots/{$other->id}/agent-token/rotate")->assertNotFound();
        $this->delete("/chatbots/{$bot->id}/agent-token/{$foreign->id}")->assertRedirect();
        $this->assertNull($foreign->fresh()->revoked_at);

        $this->actingAsWorkspaceMember(User::factory()->create(), 'supervisor');
        $this->post("/chatbots/{$bot->id}/agent-token/rotate")->assertForbidden();
    }

    // --- notices --------------------------------------------------------------------------------------------------------------------------

    public function test_the_team_is_warned_at_the_right_moment_once_per_threshold_and_never_about_a_replaced_token(): void
    {
        $ws = $this->workspace('A');
        $bot = $this->bot($ws);
        $this->access->generate($bot);
        $row = AgentToken::first();
        $notices = app(AgentTokenNotices::class);

        $this->assertSame(0, $notices->sweep(), 'a fresh token is not announced');

        $row->update(['expires_at' => now()->addDays(20)]);
        $this->assertSame(1, $notices->sweep());
        $this->assertSame(0, $notices->sweep(), 'the same threshold is not announced twice');
        $this->assertSame('agent_token_expiring', $this->notifications($ws)->first()->type);

        $row->update(['expires_at' => now()->addDays(5)]); // found at 5 days: the 7-day notice, not three at once
        $this->assertSame(1, $notices->sweep());
        $row->update(['expires_at' => now()->addHours(30)]);
        $this->assertSame(1, $notices->sweep());
        $this->assertCount(3, $this->notifications($ws, 'agent_token_expiring'));

        $row->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $notices->sweep());
        $this->assertSame(0, $notices->sweep());
        $this->assertCount(1, $this->notifications($ws, 'agent_token_expired'));

        foreach ($this->notifications($ws) as $n) {
            $this->assertSame('manage-agent-tokens', $n->permission);
            $this->assertStringNotContainsString('ava_', $n->title.$n->body);
        }
    }

    public function test_replaced_inactive_and_revoked_tokens_are_not_announced(): void
    {
        $ws = $this->workspace('A');
        $bot = $this->bot($ws);
        $this->access->generate($bot);
        $this->access->rotate($bot); // the first one is now superseded
        AgentToken::query()->update(['expires_at' => now()->addDays(2)]);
        AgentToken::orderBy('id')->first()->update(['expires_at' => now()->addDays(2)]);

        $this->assertSame(1, app(AgentTokenNotices::class)->sweep(), 'only the current token of the chatbot is worth a warning');

        $bot->update(['is_active' => false]);
        AgentToken::query()->update(['expires_at' => now()->addHours(10)]);
        $this->assertSame(0, app(AgentTokenNotices::class)->sweep());

        $bot->update(['is_active' => true]);
        $this->access->revoke($bot);
        $this->assertSame(0, app(AgentTokenNotices::class)->sweep());
    }

    public function test_the_notification_command_runs_and_the_diagnosis_reports_expired_tokens(): void
    {
        $ws = $this->workspace('A');
        $bot = $this->bot($ws);
        $this->access->generate($bot);
        AgentToken::first()->update(['expires_at' => now()->subDay()]);

        $this->assertSame(0, Artisan::call('agent:tokens:notify'));
        $this->assertCount(1, $this->notifications($ws, 'agent_token_expired'));

        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('token(s) vencido(s)', Artisan::output());
    }

    // --- existing tokens ------------------------------------------------------------------------------------------------------------------

    public function test_existing_tokens_are_adopted_with_an_explicit_rule_and_nothing_is_revoked(): void
    {
        $oldBot = $this->bot($this->workspace('A'), 'Viejo');
        $youngBot = $this->bot(Workspace::firstWhere('code', 'A'), 'Joven');
        $none = $this->bot(Workspace::firstWhere('code', 'A'), 'Sin token');

        // Tokens issued BEFORE the ledger: only the mirror columns exist.
        DB::table('chatbots')->where('id', $oldBot->id)->update(['agent_token_hash' => hash('sha256', 'ava_old'), 'agent_token_hint' => 'ld', 'agent_token_created_at' => now()->subDays(200)]);
        DB::table('chatbots')->where('id', $youngBot->id)->update(['agent_token_hash' => hash('sha256', 'ava_young'), 'agent_token_hint' => 'ng', 'agent_token_created_at' => now()->subDays(10), 'agent_last_seen_at' => now()->subHour()]);

        $migration = require database_path('migrations/2026_11_14_100000_create_agent_tokens_table.php');
        $this->assertSame(2, $migration->adoptLegacy());
        $this->assertSame(0, $migration->adoptLegacy(), 'idempotent');

        $old = AgentToken::where('chatbot_id', $oldBot->id)->first();
        $young = AgentToken::where('chatbot_id', $youngBot->id)->first();

        // A token older than 90 days is not cut off by the deployment: it gets a 30-day window to be rotated (and is announced).
        $this->assertTrue($old->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
        // A younger one keeps what is left of its 90 days.
        $this->assertTrue($young->expires_at->between(now()->addDays(80)->subMinute(), now()->addDays(80)->addMinute()));
        $this->assertTrue($old->legacy && $young->legacy);
        $this->assertNull($old->revoked_at);
        $this->assertNull(AgentToken::where('chatbot_id', $none->id)->first());
        $this->assertNotNull($young->first_used_at);
    }

    public function test_a_token_that_is_only_in_the_mirror_columns_keeps_working_and_is_adopted_on_use(): void
    {
        $bot = $this->bot($this->workspace('A'));
        $plain = 'ava_'.str_repeat('q', 40);
        DB::table('chatbots')->where('id', $bot->id)->update(['agent_token_hash' => hash('sha256', $plain), 'agent_token_hint' => 'qqqq', 'agent_token_created_at' => now()->subDays(5)]);

        $this->read($plain)->assertOk();

        $row = AgentToken::where('chatbot_id', $bot->id)->first();
        $this->assertTrue($row->legacy);
        $this->assertTrue($row->expires_at->between(now()->addDays(85)->subMinute(), now()->addDays(85)->addMinute()));
    }

    // --- secrets ------------------------------------------------------------------------------------------------------------------------

    public function test_no_token_hash_or_hint_reaches_the_audit_log_and_the_token_is_shown_once(): void
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, 'admin');
        $bot = $this->bot(Workspace::orderBy('id')->first());

        $this->post("/chatbots/{$bot->id}/agent-token")->assertRedirect();
        $plain = session('agent_token');
        $this->post("/chatbots/{$bot->id}/agent-token/rotate")->assertRedirect();
        $replacement = session('agent_token');
        $this->assertNotSame($plain, $replacement);

        $dump = json_encode(AuditLog::all()->toArray());
        foreach ([$plain, $replacement, hash('sha256', $plain), hash('sha256', $replacement)] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertNotEmpty(AuditLog::where('resource_type', 'chatbot')->get()->filter(fn ($l) => str_contains(json_encode($l->changes), 'reemplazo')), 'the rotation is audited');

        // The replacement is shown on the very next page (once)...
        $this->assertStringContainsString($replacement, $this->get("/chatbots/{$bot->id}")->assertOk()->getContent());
        // ...and never again.
        $page = $this->get("/chatbots/{$bot->id}")->assertOk();
        $this->assertStringNotContainsString($replacement, $page->getContent());
        $this->assertStringNotContainsString($plain, $page->getContent());
        $props = $page->viewData('page')['props']['agentToken'];
        $this->assertTrue($props['lifecycle']['pendingRotation']);
        $this->assertSame(90, $props['lifecycle']['validityDays']);
        $this->assertCount(1, $props['lifecycle']['previous']);
    }
}
