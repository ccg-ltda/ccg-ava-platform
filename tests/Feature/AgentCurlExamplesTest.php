<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\AgentCurlExamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The cURL commands shown in Integraciones are built from the real routes, accepted by the real API, and never contain a token. */
class AgentCurlExamplesTest extends TestCase
{
    use RefreshDatabase;

    private Chatbot $bot;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsWorkspaceMember(User::factory()->create(), 'admin');
        $this->bot = Workspace::orderBy('id')->first()->chatbots()->create(['name' => 'Ventas', 'instructions' => 'Sé breve']);
        $this->token = app(AgentAccess::class)->generate($this->bot);
        $workspace = $this->bot->workspace;
        $integration = $workspace->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '123456789'], 'secrets' => ['access_token' => 'x'],
        ]);
        $this->bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
    }

    /** @return array{method: string, url: string, body: ?array} what the command sends, read back from its text */
    private function parse(string $command): array
    {
        preg_match('/-X (\w+) "([^"]+)"/', $command, $request);
        preg_match("/-d '(.*)'/s", $command, $body);

        return ['method' => $request[1], 'url' => $request[2], 'body' => isset($body[1]) ? json_decode($body[1], true) : null];
    }

    public function test_the_commands_use_the_real_routes_and_a_placeholder_instead_of_any_token(): void
    {
        $examples = app(AgentCurlExamples::class)->all();

        $this->assertSame(['config', 'message', 'authorize', 'handoff', 'knowledge'], array_column($examples, 'key'));

        foreach ($examples as $example) {
            $this->assertStringContainsString('Authorization: Bearer $'.AgentCurlExamples::TOKEN_VARIABLE, $example['command']);
            $this->assertStringNotContainsString($this->token, $example['command']);
            $this->assertStringNotContainsString('ava_', $example['command']);
            $this->assertSame($example['url'], $this->parse($example['command'])['url']);
        }

        $this->assertSame(route('agent.config'), $examples[0]['url']);
    }

    public function test_the_page_carries_the_commands_but_never_the_stored_token(): void
    {
        $this->get('/integrations')->assertOk()->assertInertia(fn ($page) => $page
            ->where('automation.curl.tokenVariable', 'AVA_TOKEN')
            ->has('automation.curl.examples', 5))
            ->assertDontSee($this->token, false);
    }

    public function test_the_config_command_works_against_the_api_and_a_wrong_token_is_refused(): void
    {
        $config = $this->parse(app(AgentCurlExamples::class)->all()[0]['command']);

        $this->getJson($config['url'], ['Authorization' => 'Bearer '.$this->token])->assertOk()->assertJsonPath('chatbot.name', 'Ventas');
        $this->getJson($config['url'], ['Authorization' => 'Bearer ava_'.str_repeat('0', 40)])->assertUnauthorized();
        $this->getJson($config['url'])->assertUnauthorized();
    }

    public function test_the_bodies_of_the_other_commands_pass_the_validation_of_the_real_endpoints(): void
    {
        foreach (array_filter(array_slice(app(AgentCurlExamples::class)->all(), 1), fn ($example) => $example['method'] === 'POST') as $example) {
            $call = $this->parse($example['command']);
            $response = $this->postJson($call['url'], $call['body'], ['Authorization' => 'Bearer '.$this->token]);

            $this->assertNotSame(422, $response->getStatusCode(), "the body of «{$example['title']}» is rejected by the validation: ".$response->getContent());
            $this->assertNotSame(401, $response->getStatusCode());
        }
    }

    public function test_the_knowledge_command_is_accepted_by_the_real_endpoint_and_its_validation_matches_what_it_documents(): void
    {
        $example = collect(app(AgentCurlExamples::class)->all())->firstWhere('key', 'knowledge');
        $call = $this->parse($example['command']);

        $this->assertSame('GET', $call['method']);
        $this->getJson($call['url'], ['Authorization' => 'Bearer '.$this->token])->assertOk()->assertJsonStructure(['results', 'count', 'note']);
        $this->getJson(route('agent.knowledge.search'), ['Authorization' => 'Bearer '.$this->token])->assertUnprocessable();
        $this->getJson($call['url'], ['Authorization' => 'Bearer ava_'.str_repeat('0', 40)])->assertUnauthorized();
    }
}
