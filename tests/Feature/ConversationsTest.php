<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** What the automation reports about a channel (messages, delivery states) and how the Workspace reads it. */
class ConversationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing may reach the network, except the local Inertia SSR probes (the Vite dev server or the SSR process).
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function actAs(string $role = 'admin', bool $superuser = false, ?Workspace $in = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        if ($in) {
            $in->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
            $this->withSession(['workspace_id' => $in->id]);
        }

        return $user;
    }

    /** A chatbot with its WhatsApp channel switched on; returns [chatbot, token]. */
    private function liveBot(Workspace $workspace, string $name = 'Asistente', bool $channel = true): array
    {
        $bot = $workspace->chatbots()->create(['name' => $name]);

        if ($channel) {
            $integration = $workspace->integrations()->firstOrCreate(['name' => 'WhatsApp'], [
                'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '100200300'], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN'],
            ]);
            $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        }

        return [$bot, app(AgentAccess::class)->generate($bot)];
    }

    private function report(string $token, array $data = [])
    {
        return $this->withToken($token)->postJson('/api/agent/messages', $data + [
            'channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'Hola',
        ]);
    }

    // --- reporting messages (the automation) -----------------------------------------------------------------

    public function test_reporting_needs_a_valid_agent_token(): void
    {
        $this->postJson('/api/agent/messages', ['channel' => 'whatsapp'])->assertUnauthorized();
        $this->withToken('ava_'.str_repeat('x', 40))->postJson('/api/agent/messages', [])->assertUnauthorized();
        $this->withToken('ava_'.str_repeat('x', 40))->postJson('/api/agent/messages/status', [])->assertUnauthorized();
        $this->assertSame(0, Message::count());
    }

    public function test_a_message_creates_the_conversation_and_is_stored_inside_the_chatbots_workspace(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $token] = $this->liveBot($workspace);

        $this->report($token, ['contact_name' => 'María Pérez', 'external_id' => 'wamid.1', 'timestamp' => '1790000000', 'body' => 'Hola, ¿tienen envíos?'])
            ->assertCreated()->assertJsonPath('created', true);

        $conversation = Conversation::firstOrFail();
        $this->assertSame([$bot->id, $workspace->id, 'whatsapp', '573001112233', 'María Pérez'], [$conversation->chatbot_id, $conversation->workspace_id, $conversation->channel, $conversation->contact_id, $conversation->contact_name]);

        $message = Message::firstOrFail();
        $this->assertSame([$workspace->id, 'in', 'text', 'Hola, ¿tienen envíos?', 'wamid.1', null], [$message->workspace_id, $message->direction, $message->type, $message->body, $message->external_id, $message->status]);
        $this->assertSame(1790000000, $message->sent_at->timestamp);
        $this->assertSame(1790000000, $conversation->last_message_at->timestamp);
    }

    public function test_the_same_contact_keeps_one_conversation_and_a_retry_is_not_stored_twice(): void
    {
        [, $token] = $this->liveBot($this->workspace('WS_A'));

        $this->report($token, ['external_id' => 'wamid.1'])->assertCreated();
        $this->report($token, ['external_id' => 'wamid.1'])->assertOk()->assertJsonPath('created', false);
        $this->report($token, ['external_id' => 'wamid.2', 'direction' => 'out', 'body' => 'Sí, enviamos.', 'status' => 'sent'])->assertCreated();
        $this->report($token, ['contact_id' => '573009998877', 'external_id' => 'wamid.3'])->assertCreated();

        $this->assertSame(2, Conversation::count());
        $this->assertSame(3, Message::count());
        $this->assertSame('sent', Message::where('external_id', 'wamid.2')->value('status'));
    }

    public function test_delivery_states_update_the_outgoing_message_and_never_an_incoming_one(): void
    {
        [, $token] = $this->liveBot($this->workspace('WS_A'));
        $this->report($token, ['external_id' => 'wamid.in']);
        $this->report($token, ['external_id' => 'wamid.out', 'direction' => 'out', 'status' => 'sent']);
        $status = fn (array $data) => $this->withToken($token)->postJson('/api/agent/messages/status', $data + ['channel' => 'whatsapp', 'contact_id' => '573001112233']);

        $status(['external_id' => 'wamid.out', 'status' => 'delivered'])->assertOk();
        $this->assertSame('delivered', Message::where('external_id', 'wamid.out')->value('status'));
        $status(['external_id' => 'wamid.out', 'status' => 'read'])->assertOk();
        $this->assertSame('read', Message::where('external_id', 'wamid.out')->value('status'));

        $status(['external_id' => 'wamid.in', 'status' => 'read'])->assertNotFound();
        $status(['external_id' => 'unknown', 'status' => 'read'])->assertNotFound();
        $status(['external_id' => 'wamid.out', 'status' => 'bogus'])->assertUnprocessable();
        $status(['external_id' => 'wamid.out', 'status' => 'read', 'contact_id' => '573000000000'])->assertNotFound();
        $this->assertNull(Message::where('external_id', 'wamid.in')->value('status'));
    }

    public function test_an_incoming_message_never_carries_a_delivery_state(): void
    {
        [, $token] = $this->liveBot($this->workspace('WS_A'));

        $this->report($token, ['status' => 'read'])->assertCreated();

        $this->assertNull(Message::firstOrFail()->status);
    }

    public function test_only_a_channel_the_chatbot_has_switched_on_accepts_messages(): void
    {
        $workspace = $this->workspace('WS_A');
        [, $without] = $this->liveBot($workspace, 'Sin canal', channel: false);

        $this->report($without)->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->report($without, ['channel' => 'web'])->assertUnprocessable();
        $this->report($without, ['channel' => 'instagram'])->assertUnprocessable();
        $this->assertSame(0, Conversation::count());

        [$bot, $token] = $this->liveBot($workspace, 'Con canal');
        $bot->channels()->update(['is_active' => false, 'integration_id' => null]);
        $this->report($token)->assertUnprocessable()->assertJsonValidationErrors('channel');
    }

    public function test_the_report_is_validated(): void
    {
        [, $token] = $this->liveBot($this->workspace('WS_A'));
        $bad = [
            ['contact_id' => 'a b'], ['contact_id' => 'x'], ['contact_id' => ''], ['direction' => 'sideways'], ['type' => 'telepathy'],
            ['body' => str_repeat('a', 4097)], ['media_mime' => 'not a mime'], ['status' => 'seen'], ['timestamp' => 'not a date'], ['timestamp' => ['x']],
            ['contact_name' => str_repeat('n', 101)], ['external_id' => str_repeat('e', 129)],
        ];

        foreach ($bad as $data) {
            $this->report($token, $data)->assertUnprocessable();
        }

        $this->assertSame(0, Message::count());
    }

    public function test_media_messages_keep_their_type_and_text_and_the_time_is_never_in_the_future(): void
    {
        [, $token] = $this->liveBot($this->workspace('WS_A'));

        $this->report($token, ['type' => 'audio', 'media_mime' => 'audio/ogg', 'body' => 'Transcripción: quiero comprar', 'external_id' => 'wamid.a'])->assertCreated();
        $this->report($token, ['external_id' => 'wamid.f', 'timestamp' => now()->addDays(3)->toIso8601String()])->assertCreated();

        $audio = Message::where('external_id', 'wamid.a')->firstOrFail();
        $this->assertSame(['audio', 'audio/ogg', 'Transcripción: quiero comprar'], [$audio->type, $audio->media_mime, $audio->body]);
        $this->assertTrue(Message::where('external_id', 'wamid.f')->firstOrFail()->sent_at->lessThanOrEqualTo(now()));
    }

    public function test_the_caller_cannot_aim_a_message_at_another_workspace_or_chatbot(): void
    {
        $a = $this->workspace('WS_A');
        $b = $this->workspace('WS_B');
        [$botA, $tokenA] = $this->liveBot($a, 'Bot A');
        [$botB] = $this->liveBot($b, 'Bot B');

        $this->report($tokenA, ['workspace_id' => $b->id, 'chatbot_id' => $botB->id, 'workspace' => 'WS_B', 'body' => 'para A'])->assertCreated();

        $this->assertSame([$botA->id, $a->id], [Conversation::firstOrFail()->chatbot_id, Conversation::firstOrFail()->workspace_id]);
        $this->assertSame(0, $botB->conversations()->count());
        $this->assertSame(0, Message::where('workspace_id', $b->id)->count());
    }

    public function test_a_chatbot_cannot_update_the_states_of_another_chatbots_messages(): void
    {
        $a = $this->workspace('WS_A');
        $b = $this->workspace('WS_B');
        [, $tokenA] = $this->liveBot($a, 'Bot A');
        [, $tokenB] = $this->liveBot($b, 'Bot B');
        $this->report($tokenA, ['external_id' => 'wamid.shared', 'direction' => 'out', 'status' => 'sent']);

        $this->withToken($tokenB)->postJson('/api/agent/messages/status', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'external_id' => 'wamid.shared', 'status' => 'failed'])->assertNotFound();

        $this->assertSame('sent', Message::where('external_id', 'wamid.shared')->value('status'));
    }

    public function test_the_same_contact_number_and_message_id_in_two_workspaces_stay_separate(): void
    {
        [$botA, $tokenA] = $this->liveBot($this->workspace('WS_A'), 'Bot A');
        [$botB, $tokenB] = $this->liveBot($this->workspace('WS_B'), 'Bot B');

        $this->report($tokenA, ['external_id' => 'wamid.1', 'body' => 'mensaje de A'])->assertCreated();
        $this->report($tokenB, ['external_id' => 'wamid.1', 'body' => 'mensaje de B'])->assertCreated();

        $this->assertSame(['mensaje de A'], Message::where('workspace_id', $botA->workspace_id)->pluck('body')->all());
        $this->assertSame(['mensaje de B'], Message::where('workspace_id', $botB->workspace_id)->pluck('body')->all());
    }

    public function test_the_database_refuses_a_conversation_or_message_across_workspaces(): void
    {
        $a = $this->workspace('WS_A');
        $b = $this->workspace('WS_B');
        [$botA] = $this->liveBot($a, 'Bot A');
        [$botB] = $this->liveBot($b, 'Bot B');
        $conversationB = $botB->conversations()->create(['workspace_id' => $b->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233']);

        foreach ([
            fn () => $botA->conversations()->create(['workspace_id' => $b->id, 'channel' => 'whatsapp', 'contact_id' => '573009990000']),
            fn () => $conversationB->messages()->create(['workspace_id' => $a->id, 'direction' => 'in', 'type' => 'text', 'sent_at' => now()]),
        ] as $write) {
            try {
                $write();
                $this->fail('The database accepted a row across Workspaces.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());
            }
        }

        $this->assertNotNull($conversationB->messages()->create(['workspace_id' => $b->id, 'direction' => 'in', 'type' => 'text', 'sent_at' => now()])->id);
    }

    public function test_reading_the_configuration_or_reporting_records_when_n8n_was_last_seen_without_touching_the_chatbot(): void
    {
        [$bot, $token] = $this->liveBot($this->workspace('WS_A'));
        $updated = Chatbot::find($bot->id)->updated_at;
        $this->assertNull(Chatbot::find($bot->id)->agent_last_seen_at);

        $this->travel(5)->seconds();
        $this->withToken($token)->getJson('/api/agent/config')->assertOk();

        $fresh = Chatbot::find($bot->id);
        $this->assertNotNull($fresh->agent_last_seen_at);
        $this->assertTrue($updated->equalTo($fresh->updated_at), 'reading is not a change of the chatbot');
    }

    // --- reading the conversations (the Workspace) -----------------------------------------------------------

    private function seedConversation(Chatbot $bot, string $contact, array $messages, ?string $name = null): Conversation
    {
        $conversation = $bot->conversations()->create(['workspace_id' => $bot->workspace_id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => $name, 'last_message_at' => end($messages)[3] ?? now()]);

        foreach ($messages as [$direction, $type, $body, $at]) {
            $conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'direction' => $direction, 'type' => $type, 'body' => $body, 'sent_at' => $at]);
        }

        return $conversation;
    }

    private function url(Chatbot $bot, string $channel = 'whatsapp', string $query = ''): string
    {
        return "/chatbots/{$bot->id}/channels/{$channel}/conversations".$query;
    }

    public function test_reading_needs_the_conversations_permission(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);

        $this->actAs('cliente', in: $workspace);
        $this->get($this->url($bot))->assertForbidden();
        $this->actAs('supervisor', in: $workspace); // view-chatbots, but not the conversations of the contacts
        $this->get("/chatbots/{$bot->id}")->assertOk();
        $this->get($this->url($bot))->assertForbidden();
        $this->actAs('admin', in: $workspace);
        $this->get($this->url($bot))->assertOk();
    }

    public function test_the_page_lists_the_conversations_newest_first_with_a_preview(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $old = $this->seedConversation($bot, '573001000001', [['in', 'text', 'Hola', now()->subDays(2)]], 'Ana');
        $new = $this->seedConversation($bot, '573001000002', [['in', 'audio', 'Quiero comprar', now()->subHour()], ['out', 'text', 'Claro, te ayudo', now()->subMinutes(30)]]);
        $this->actAs(in: $workspace);

        $this->get($this->url($bot))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Conversations/Index')->where('channel.key', 'whatsapp')->where('channel.label', 'WhatsApp')->where('channel.state', 'active')
            ->where('chatbot.name', 'Asistente')->where('selected', null)
            ->where('conversations', fn ($rows) => collect($rows)->pluck('id')->all() === [$new->id, $old->id])
            ->where('conversations.0.preview', 'Tú: Claro, te ayudo')->where('conversations.1.preview', 'Hola')->where('conversations.1.contactName', 'Ana')
            ->where('reportEndpoint', url('/api/agent/messages'))->where('scope.readOnly', false));
    }

    public function test_a_selected_conversation_shows_its_messages_in_order_with_their_type_and_state(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->seedConversation($bot, '573001000001', [
            ['in', 'text', 'Hola', now()->subHours(3)],
            ['out', 'text', 'Buenas', now()->subHours(2)],
            ['in', 'audio', 'Transcripción de audio', now()->subHour()],
        ]);
        $conversation->messages()->where('direction', 'out')->update(['status' => 'delivered']);
        $this->actAs(in: $workspace);

        $this->get($this->url($bot, query: "?c={$conversation->id}"))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.id', $conversation->id)->where('selected.contactId', '573001000001')->where('selected.truncated', false)
            ->where('selected.items', fn ($items) => collect($items)->pluck('body')->all() === ['Hola', 'Buenas', 'Transcripción de audio']
                && collect($items)->pluck('direction')->all() === ['in', 'out', 'in']
                && collect($items)->pluck('type')->all() === ['text', 'text', 'audio']
                && $items[1]['status'] === 'delivered' && $items[0]['status'] === null));
    }

    public function test_a_very_long_conversation_shows_the_newest_messages_and_says_so(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001000001', 'last_message_at' => now()]);
        $rows = collect(range(1, 205))->map(fn ($n) => ['workspace_id' => $workspace->id, 'conversation_id' => $conversation->id, 'direction' => 'in', 'type' => 'text', 'body' => "m{$n}", 'sent_at' => now()->subMinutes(300 - $n), 'created_at' => now(), 'updated_at' => now()])->all();
        Message::insert($rows);
        $this->actAs(in: $workspace);

        $this->get($this->url($bot, query: "?c={$conversation->id}"))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.truncated', true)->has('selected.items', 200)->where('selected.items.0.body', 'm6')->where('selected.items.199.body', 'm205'));
    }

    public function test_the_search_filters_by_name_or_number_and_treats_wildcards_literally(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $this->seedConversation($bot, '573001000001', [['in', 'text', 'x', now()]], 'María');
        $this->seedConversation($bot, '573002000002', [['in', 'text', 'x', now()]], 'José');
        $this->actAs(in: $workspace);

        $ids = fn (string $q) => collect($this->get($this->url($bot, query: '?q='.urlencode($q)))->viewData('page')['props']['conversations'])->pluck('contactId')->all();

        $this->assertSame(['573001000001'], $ids('mar'));
        $this->assertSame(['573002000002'], $ids('2000002'));
        $this->assertSame([], $ids('%'));
        $this->assertSame([], $ids('_'));
        $this->assertCount(2, $ids(''));
    }

    // --- isolation on the reading side -----------------------------------------------------------------------

    public function test_another_workspaces_conversations_do_not_exist_from_here(): void
    {
        $a = $this->workspace('WS_A');
        $b = $this->workspace('WS_B');
        [$botA] = $this->liveBot($a, 'Bot A');
        [$botB] = $this->liveBot($b, 'Bot B');
        $own = $this->seedConversation($botA, '573001000001', [['in', 'text', 'mío', now()]]);
        $foreign = $this->seedConversation($botB, '573009999999', [['in', 'text', 'SECRETO DE B', now()]], 'Cliente de B');
        $this->actAs(in: $a);

        $page = $this->get($this->url($botA))->assertOk();
        $this->assertStringNotContainsString('SECRETO DE B', $page->getContent());
        $this->assertStringNotContainsString('573009999999', $page->getContent());

        $this->get($this->url($botB))->assertNotFound();
        $this->get($this->url($botA, query: "?c={$foreign->id}"))->assertNotFound();
        $this->get($this->url($botB, query: "?c={$foreign->id}"))->assertNotFound();
        $this->get($this->url($botA, query: "?c={$own->id}"))->assertOk();
    }

    public function test_a_conversation_of_another_chatbot_or_channel_of_the_same_workspace_is_not_reachable_through_this_one(): void
    {
        $workspace = $this->workspace('WS_A');
        [$one] = $this->liveBot($workspace, 'Uno');
        [$two] = $this->liveBot($workspace, 'Dos', channel: false);
        $other = $this->seedConversation($two, '573001000001', [['in', 'text', 'del otro chatbot', now()]]);
        $web = $one->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'contact_id' => 'visitor-1']);
        $this->actAs(in: $workspace);

        $this->get($this->url($one, query: "?c={$other->id}"))->assertNotFound();
        $this->get($this->url($one, query: "?c={$web->id}"))->assertNotFound();
        $this->get($this->url($one, query: '?c=abc'))->assertNotFound();
        $this->get($this->url($one, query: '?c[]=1'))->assertNotFound();
    }

    public function test_only_channels_that_keep_conversations_have_the_page(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $this->actAs(in: $workspace);

        $this->get($this->url($bot, 'web'))->assertNotFound();
        $this->get($this->url($bot, 'instagram'))->assertNotFound();
        $this->get($this->url($bot, 'nope'))->assertNotFound();
    }

    public function test_a_normal_user_cannot_look_at_another_workspaces_conversations_with_the_parameter(): void
    {
        $a = $this->workspace('WS_A');
        $b = $this->workspace('WS_B');
        [$botB] = $this->liveBot($b, 'Bot B');
        $conversation = $this->seedConversation($botB, '573009999999', [['in', 'text', 'SECRETO DE B', now()]]);
        $this->actAs(in: $a);

        $this->get($this->url($botB, query: "?workspace={$b->id}&c={$conversation->id}"))->assertForbidden();
        $this->actAs('admin', superuser: true, in: $a); // a superuser inside a client Workspace is limited to it
        $this->get($this->url($botB, query: "?workspace={$b->id}"))->assertForbidden();
    }

    public function test_the_administrator_of_the_platform_reads_one_other_workspace_without_being_able_to_change_it(): void
    {
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $dev = $this->workspace('DESARROLLO_DEV');
        $client = $this->workspace('EMPRESA_ABC');
        $other = $this->workspace('EMPRESA_XYZ');
        [$botClient] = $this->liveBot($client, 'Bot ABC');
        [$botOther] = $this->liveBot($other, 'Bot XYZ');
        $conversation = $this->seedConversation($botClient, '573001000001', [['in', 'text', 'hola ABC', now()]]);
        $this->seedConversation($botOther, '573002000002', [['in', 'text', 'hola XYZ', now()]]);
        $this->actAs('admin', superuser: true, in: $dev);

        $page = $this->get($this->url($botClient, query: "?workspace={$client->id}&c={$conversation->id}"));
        $page->assertInertia(fn (AssertableInertia $p) => $p
            ->where('scope.readOnly', true)->where('scope.workspace.code', 'EMPRESA_ABC')->where('canConfigure', false)
            ->where('selected.items.0.body', 'hola ABC')->has('conversations', 1));
        $this->assertStringNotContainsString('hola XYZ', $page->getContent());

        // The chatbot of ABC does not exist in the Workspace being looked at when the parameter names another one.
        $this->get($this->url($botClient, query: "?workspace={$other->id}"))->assertNotFound();
        $this->get($this->url($botClient))->assertNotFound();
    }

    public function test_the_page_never_carries_a_credential_or_the_agent_token(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $token] = $this->liveBot($workspace);
        $this->actAs(in: $workspace);

        $body = $this->get($this->url($bot))->assertOk()->getContent();

        foreach (['EAAB-SECRET-TOKEN', $token, 'access_token', 'agent_token_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    // --- what the other pages say about it -------------------------------------------------------------------

    public function test_the_chatbot_page_flags_which_channels_keep_conversations(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $this->actAs(in: $workspace);

        $channels = collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['channels'])->keyBy('key');

        $this->assertTrue($channels['whatsapp']['conversations']);
        $this->assertFalse($channels['web']['conversations']);
        $this->assertFalse($channels['instagram']['conversations']);
    }
}
