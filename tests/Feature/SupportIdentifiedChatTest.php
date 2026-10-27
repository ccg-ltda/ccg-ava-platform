<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SupportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The identified support chat: a signed-in member of a CLIENT Workspace writes to CCG's support and the conversation is theirs (named, continuous in any browser,
 * never shared with another client) — and nothing a browser sends can pick a Workspace, a person or a conversation. n8n and the AI are faked.
 */
class SupportIdentifiedChatTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://n8n.example.com/webhook/ccg-support';

    private Workspace $dev;

    private Workspace $client;

    private string $key;

    private string $clientKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        $this->seedRoleCatalog();

        $organization = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_X', 'name' => 'Cliente X']);

        $this->key = $this->liveWidget($this->dev, 'Soporte CCG');
        $this->clientKey = $this->liveWidget($this->client, 'Chat del cliente');
        config(['support.public_chat_key' => $this->key]);
    }

    private function member(Workspace $workspace, string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function signIn(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
    }

    private function liveWidget(Workspace $workspace, string $name): string
    {
        $workspace->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => self::HOOK, 'allowed_origins' => []], 'secrets' => ['webhook_secret' => 'SECRETO-DEL-WEBHOOK']]);
        $bot = $workspace->chatbots()->create(['name' => $name]);
        $this->signIn($this->member($workspace, 'admin'), $workspace)->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('success');
        auth()->logout();

        return ChatbotChannel::where('chatbot_id', $bot->id)->where('channel', 'web')->firstOrFail()->public_key;
    }

    private function context(User $user, Workspace $workspace): string
    {
        return app(SupportContext::class)->issue($user, $workspace);
    }

    private function sayAs(?string $token, string $session, string $text, ?string $key = null)
    {
        return $this->postJson('/api/widget/'.($key ?? $this->key).'/messages', ['session_id' => $session, 'message' => $text], $token ? [SupportContext::HEADER => $token] : []);
    }

    public function test_only_a_signed_in_member_of_a_client_workspace_gets_a_context_token(): void
    {
        $client = $this->member($this->client, 'admin');
        $staff = $this->member($this->dev, 'admin');

        $this->signIn($client, $this->client)->get('/profile')->assertInertia(fn ($page) => $page->where('supportChat.context', fn ($token) => is_string($token) && str_contains($token, '.')));
        $this->signIn($staff, $this->dev)->get('/profile')->assertInertia(fn ($page) => $page->where('supportChat.context', null));
        auth()->logout();
        $this->flushSession();
        $this->get('/pre-login')->assertInertia(fn ($page) => $page->where('supportChat.context', null));
    }

    public function test_the_conversation_belongs_to_the_person_carries_their_names_and_continues_in_another_browser(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'Hola'])]);
        $user = $this->member($this->client, 'admin');
        $token = $this->context($user, $this->client);

        $this->sayAs($token, 'browser-aaaaaa', 'Necesito ayuda con mis pedidos')->assertOk();
        $this->sayAs($token, 'browser-bbbbbb', 'Sigo aquí desde otro equipo')->assertOk();

        $conversation = Conversation::where('channel', 'web')->sole();
        $this->assertSame($this->dev->id, $conversation->workspace_id);
        $this->assertSame("c{$this->client->id}u{$user->id}", $conversation->contact_id);
        $this->assertSame("Cliente X · {$user->name}", $conversation->contact_name);
        $this->assertSame(2, $conversation->messages()->where('direction', 'in')->count());
    }

    public function test_two_clients_never_share_a_conversation_and_an_anonymous_visitor_keeps_the_old_behaviour(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'Hola'])]);
        $other = Workspace::create(['organization_id' => $this->client->organization_id, 'code' => 'CLIENTE_Y', 'name' => 'Cliente Y']);
        $a = $this->member($this->client, 'admin');
        $b = $this->member($other, 'admin');

        $this->sayAs($this->context($a, $this->client), 'same-session-1', 'Soy A')->assertOk();
        $this->sayAs($this->context($b, $other), 'same-session-1', 'Soy B')->assertOk();
        $this->sayAs(null, 'same-session-1', 'Soy anónimo')->assertOk();

        $this->assertSame(3, Conversation::where('channel', 'web')->count());
        $this->assertEqualsCanonicalizing(["c{$this->client->id}u{$a->id}", "c{$other->id}u{$b->id}", 'same-session-1'], Conversation::pluck('contact_id')->all());
    }

    public function test_the_reply_of_the_team_reaches_the_right_client_only(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'Hola'])]);
        $user = $this->member($this->client, 'admin');
        $token = $this->context($user, $this->client);
        $this->sayAs($token, 'browser-aaaaaa', 'Hola equipo')->assertOk();
        $conversation = Conversation::where('channel', 'web')->sole();
        $conversation->messages()->create(['workspace_id' => $this->dev->id, 'direction' => 'out', 'sender' => 'agent', 'type' => 'text', 'body' => 'Te ayudo con gusto', 'status' => 'sent', 'sent_at' => now()]);

        $anonymous = $this->getJson('/api/widget/'.$this->key.'/messages?session_id=browser-cccccc')->assertOk();
        $this->assertSame([], $anonymous->json('messages'), 'another session does not read it');

        $mine = $this->getJson('/api/widget/'.$this->key.'/messages?session_id=browser-bbbbbb', [SupportContext::HEADER => $token])->assertOk();
        $this->assertSame(['Te ayudo con gusto'], array_column($mine->json('messages'), 'text'));
    }

    public function test_a_forged_expired_or_stale_token_and_a_client_channel_are_treated_as_anonymous(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'Hola'])]);
        $user = $this->member($this->client, 'admin');
        $token = $this->context($user, $this->client);
        [$payload, $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['u' => $this->member($this->client, 'admin')->id, 'w' => $this->dev->id, 'e' => now()->timestamp + 3600])), '+/', '-_'), '=').'.'.$signature;

        $this->sayAs($forged, 'visitor-forged', 'x')->assertOk();
        $this->sayAs($payload.'.'.str_repeat('0', 64), 'visitor-badsig', 'x')->assertOk();
        $this->sayAs('basura', 'visitor-junk01', 'x')->assertOk();
        $this->sayAs($token, 'visitor-clientkey', 'x', $this->clientKey)->assertOk();

        $this->travel(13)->hours();
        $this->sayAs($token, 'visitor-expired', 'x')->assertOk();
        $this->travelBack();

        // The person leaves the Workspace: the token no longer names a member.
        $this->client->users()->detach($user->id);
        $this->sayAs($token, 'visitor-stale01', 'x')->assertOk();

        $this->assertEqualsCanonicalizing(['visitor-forged', 'visitor-badsig', 'visitor-junk01', 'visitor-clientkey', 'visitor-expired', 'visitor-stale01'], Conversation::pluck('contact_id')->all());
        $this->assertSame(0, Conversation::whereNotNull('contact_name')->count(), 'no anonymous conversation was given a name');
    }
}
