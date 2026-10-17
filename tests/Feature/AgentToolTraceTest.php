<?php

namespace Tests\Feature;

use App\Models\AgentToolCall;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Service;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\AgentToolTrace;
use App\Services\ExecutionAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The trace of the business tools the assistant runs through Ava. It must say what happened and never more: a call that arrived is not an operation that was
 * persisted, an order that exists is not a message that was sent, a retry is not a second operation, and nothing about the contact or any secret is kept.
 */
class AgentToolTraceTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings', 'product_catalog', 'orders'];

    private Workspace $workspace;

    private Chatbot $bot;

    private string $token;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('TIENDA', self::MODULES);
        $this->workspace->settingsOrDefault()->update(['currency' => 'COP']);
        [$this->bot, $this->token] = $this->bot($this->workspace);
        $this->conversation = $this->conversation('573001112233', $this->bot);
    }

    /** @return array{Chatbot, string} */
    private function bot(Workspace $workspace): array
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);

        return [$bot, app(AgentAccess::class)->generate($bot)];
    }

    private function conversation(string $contact, Chatbot $bot): Conversation
    {
        return $bot->conversations()->create(['workspace_id' => $bot->workspace_id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => 'Laura'])->refresh();
    }

    /** @return array{0: ChatbotExecution, 1: string} an execution as it is while the workflow runs, and the token Ava gives that workflow */
    private function running(Conversation $conversation): array
    {
        $message = $conversation->messages()->create(['workspace_id' => $conversation->workspace_id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Quiero pedir', 'external_id' => 'wamid.'.Str::random(8), 'sent_at' => now()]);
        $execution = ChatbotExecution::create([
            'correlation_id' => (string) Str::uuid(), 'workspace_id' => $conversation->workspace_id, 'chatbot_id' => $conversation->chatbot_id, 'conversation_id' => $conversation->id,
            'message_id' => $message->id, 'workflow_key' => 'support', 'n8n_workflow_id' => 'wf1', 'handling_version' => 0, 'status' => ChatbotExecution::RUNNING, 'attempts' => 1,
        ]);

        return [$execution, app(ExecutionAccess::class)->issue($execution)];
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null, array $headers = [])
    {
        return $this->withToken($token ?? $this->token)->json($method, "/api/agent{$path}", $data, $headers);
    }

    private function product(string $name = 'Hamburguesa', string $price = '18500.00', ?Workspace $workspace = null)
    {
        return ($workspace ?? $this->workspace)->products()->create(['name' => $name, 'price' => $price]);
    }

    private function service(): Service
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);

        return $service;
    }

    /** @return array<string, mixed> */
    private function order(int $productId, int $quantity = 1, array $extra = []): array
    {
        return $extra + ['delivery_method' => 'pickup', 'customer' => ['name' => 'Laura Pérez', 'phone' => '573001112233'], 'lines' => [['product_id' => $productId, 'quantity' => $quantity]]];
    }

    // --- what is traced -----------------------------------------------------------------------------------------------------------------

    public function test_a_persisted_order_is_traced_with_what_happened_and_nothing_more(): void
    {
        $burger = $this->product();
        [$execution, $run] = $this->running($this->conversation);

        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertCreated();

        $call = AgentToolCall::where('tool', 'orders.store')->firstOrFail();
        $order = Order::firstOrFail();
        $this->assertSame($this->workspace->id, $call->workspace_id);
        $this->assertSame($this->bot->id, $call->chatbot_id);
        $this->assertSame($this->conversation->id, $call->conversation_id);
        $this->assertSame($execution->correlation_id, $call->execution_ref);
        $this->assertSame(['write', 'execution', 'succeeded', 201], [$call->kind, $call->auth, $call->status, $call->http_status]);
        $this->assertSame(['order', $order->id, 'received', false], [$call->resource_type, $call->resource_id, $call->result_state, $call->replayed]);
        $this->assertSame($order->idempotency_key, $call->idempotency_ref);
        $this->assertNull($call->reason);
        $this->assertNotNull($call->finished_at);
        $this->assertNotNull($call->duration_ms);
    }

    public function test_the_trace_never_says_a_customer_was_told_when_nothing_was_sent(): void
    {
        $burger = $this->product();
        $this->conversation('573009998877', $this->bot);

        $this->api('POST', '/orders', $this->order($burger->id, 1, ['channel' => 'whatsapp', 'contact_id' => '573009998877', 'customer' => ['name' => 'Laura', 'phone' => '573009998877']]), null, ['Idempotency-Key' => 'a'])->assertCreated();

        $this->assertSame('unavailable', AgentToolCall::where('tool', 'orders.store')->firstOrFail()->notification, 'There is no channel for order notices: the trace says so instead of implying a message went out.');
        $this->assertSame([], array_values(array_diff(AgentToolCall::pluck('notification')->unique()->all(), ['none', 'planned', 'unavailable'])), 'The trace has no value that says "sent": delivery is recorded where it happens.');
    }

    public function test_a_retry_is_one_operation_and_one_honest_extra_row_marked_as_a_retry(): void
    {
        $burger = $this->product();
        [, $run] = $this->running($this->conversation);

        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertCreated();
        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertOk();

        $this->assertSame(1, Order::count());
        $rows = AgentToolCall::where('tool', 'orders.store')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([false, true], $rows->pluck('replayed')->all());
        $this->assertSame([Order::first()->id], $rows->pluck('resource_id')->unique()->values()->all(), 'Both calls point at the same single order.');
        $this->assertSame(['succeeded', 'succeeded'], $rows->pluck('status')->all());
    }

    public function test_refusals_are_traced_as_refusals_with_a_fixed_code_and_no_resource(): void
    {
        $burger = $this->product();
        [, $run] = $this->running($this->conversation);

        $this->api('POST', '/orders', $this->order($burger->id, 0), $run)->assertStatus(422);
        $this->api('POST', '/orders', $this->order(999999), $run)->assertStatus(422);
        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertCreated();
        $this->api('POST', '/orders', $this->order($burger->id, 5), $run)->assertStatus(409);
        $this->api('POST', '/orders', $this->order($burger->id), $this->token)->assertStatus(422); // chatbot token without channel/contact

        $rows = AgentToolCall::where('tool', 'orders.store')->orderBy('id')->get();
        $this->assertSame(['rejected', 'rejected', 'succeeded', 'rejected', 'rejected'], $rows->pluck('status')->all());
        $this->assertSame([422, 422, 201, 409, 422], $rows->pluck('http_status')->all());
        $this->assertSame(['invalid', 'invalid', null, 'idempotency_conflict', 'invalid'], $rows->pluck('reason')->all());
        $this->assertSame([null, null, 'order', null, null], $rows->pluck('resource_type')->all());
        $this->assertSame(1, Order::count());
    }

    public function test_a_missing_conversation_and_a_missing_key_are_traced_with_their_reason(): void
    {
        $burger = $this->product();

        $this->api('POST', '/orders', $this->order($burger->id, 1, ['channel' => 'whatsapp', 'contact_id' => '573000000000', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']]), null, ['Idempotency-Key' => 'a'])->assertNotFound();
        $this->api('POST', '/orders', $this->order($burger->id, 1, ['channel' => 'whatsapp', 'contact_id' => '573001112233']))->assertStatus(422);

        $rows = AgentToolCall::orderBy('id')->get();
        $this->assertSame(['not_found', 'idempotency_key_required'], $rows->pluck('reason')->all());
        $this->assertSame([null, $this->conversation->id], $rows->pluck('conversation_id')->all(), 'Only a conversation that exists is referenced.');
        $this->assertSame(0, Order::count());
    }

    public function test_reads_are_traced_as_reads_and_the_booking_tools_are_traced_too(): void
    {
        $this->product();
        $service = $this->service();
        [, $run] = $this->running($this->conversation);

        $this->api('GET', '/orders/products', [], $run)->assertOk();
        $this->api('GET', "/booking/availability?service_id={$service->id}&from=2026-10-13&days=1", [], $run)->assertOk();
        $this->api('POST', '/booking/appointments', ['service_id' => $service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233']], $run)->assertCreated();
        $this->api('POST', '/booking/appointments', ['service_id' => $service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Otra Persona', 'phone' => '+573009998877']], $this->bookToken())->assertStatus(409);

        $byTool = AgentToolCall::orderBy('id')->get()->keyBy('tool');
        $this->assertSame(['read', 'succeeded', null], [$byTool['orders.products']->kind, $byTool['orders.products']->status, $byTool['orders.products']->resource_type]);
        $this->assertSame(['read', 'succeeded'], [$byTool['booking.availability']->kind, $byTool['booking.availability']->status]);
        $book = AgentToolCall::where('tool', 'booking.book')->orderBy('id')->get();
        $this->assertSame(['succeeded', 'rejected'], $book->pluck('status')->all());
        $this->assertSame(['appointment', 'confirmed', 'none'], [$book[0]->resource_type, $book[0]->result_state, $book[0]->notification]);
        $this->assertNotNull($book[0]->resource_id);
        $this->assertSame('slot_unavailable', $book[1]->reason);
        $this->assertNull($book[1]->resource_id);
    }

    /** A second conversation's token for the same Workspace: books the same slot from another contact. */
    private function bookToken(): string
    {
        $other = $this->conversation('573009998877', $this->bot);
        [, $run] = $this->running($other);

        return $run;
    }

    public function test_a_call_the_platform_refuses_before_the_tool_is_not_traced(): void
    {
        $this->product();
        $this->contractModules($this->workspace, ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings']);

        $this->api('GET', '/orders/products')->assertForbidden();
        $this->withToken('ava_'.str_repeat('x', 40))->getJson('/api/agent/orders/products')->assertUnauthorized();
        $this->getJson('/api/agent/orders/products')->assertUnauthorized();
        $this->api('GET', '/config')->assertOk();

        $this->assertSame(0, AgentToolCall::count());
    }

    // --- what the trace never holds -----------------------------------------------------------------------------------------------------

    public function test_the_trace_holds_no_token_no_message_text_and_no_personal_data(): void
    {
        $burger = $this->product();
        [, $run] = $this->running($this->conversation);

        $this->api('POST', '/orders', $this->order($burger->id, 1, ['notes' => 'Sin cebolla', 'delivery_method' => 'delivery', 'delivery_address' => 'Calle 1 # 2-3 Bogotá', 'recipient_name' => 'Pedro Gómez']), $run, ['Idempotency-Key' => 'raw-key-should-not-appear'])->assertCreated();
        $this->api('GET', '/orders/products?q=hamburguesa', [], $this->token)->assertOk();

        $dump = json_encode(AgentToolCall::all()->map->getAttributes()->all());
        foreach ([$run, $this->token, 'avx_', 'ava_', 'Laura', 'Pérez', '573001112233', 'Calle 1', 'Pedro', 'Sin cebolla', 'Quiero pedir', 'raw-key-should-not-appear', 'hamburguesa'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertSame(['id', 'workspace_id', 'chatbot_id', 'conversation_id', 'execution_ref', 'tool', 'kind', 'auth', 'status', 'http_status', 'reason', 'idempotency_ref', 'resource_type', 'resource_id', 'replayed', 'result_state', 'notification', 'duration_ms', 'created_at', 'finished_at'], Schema::getColumnListing('agent_tool_calls'), 'A new column is a decision about privacy: it must be reviewed here.');
    }

    public function test_the_trace_of_one_workspace_never_reaches_another(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        [$otherBot, $otherToken] = $this->bot($other);
        $otherConversation = $this->conversation('573001112233', $otherBot);
        $theirs = $this->product('De otra tienda', '7.00', $other);
        $burger = $this->product();

        $this->api('POST', '/orders', $this->order($theirs->id, 1, ['channel' => 'whatsapp', 'contact_id' => '573001112233']), $otherToken, ['Idempotency-Key' => 'a'])->assertCreated();
        $this->api('POST', '/orders', $this->order($burger->id, 1, ['channel' => 'whatsapp', 'contact_id' => '573001112233']), null, ['Idempotency-Key' => 'a'])->assertCreated();

        $this->assertSame(2, AgentToolCall::where('tool', 'orders.store')->whereIn('status', ['succeeded'])->count());
        $mine = AgentToolCall::where('workspace_id', $this->workspace->id)->where('status', 'succeeded')->first();
        $theirsCall = AgentToolCall::where('workspace_id', $other->id)->where('status', 'succeeded')->first();
        $this->assertSame([$this->conversation->id, Order::where('workspace_id', $this->workspace->id)->value('id')], [$mine->conversation_id, $mine->resource_id]);
        $this->assertSame([$otherConversation->id, Order::where('workspace_id', $other->id)->value('id')], [$theirsCall->conversation_id, $theirsCall->resource_id]);
        $this->assertNotSame($mine->idempotency_ref, $theirsCall->idempotency_ref);
    }

    public function test_the_database_refuses_a_trace_of_a_conversation_of_another_workspace(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        [$otherBot] = $this->bot($other);
        $foreign = $this->conversation('573001112233', $otherBot);

        $this->expectException(QueryException::class);
        AgentToolCall::create(['workspace_id' => $this->workspace->id, 'conversation_id' => $foreign->id, 'tool' => 'orders.store', 'kind' => 'write', 'auth' => 'chatbot', 'status' => 'started']);
    }

    // --- when the trace itself fails ---------------------------------------------------------------------------------------------------------

    public function test_a_trace_that_cannot_be_written_never_blocks_the_operation(): void
    {
        $burger = $this->product();
        AgentToolCall::creating(fn () => throw new RuntimeException('disk full'));
        [, $run] = $this->running($this->conversation);

        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertCreated();

        $this->assertSame(1, Order::count());
        $this->assertSame(0, AgentToolCall::count());
    }

    public function test_before_the_migration_nothing_is_traced_and_the_tools_work_as_before(): void
    {
        $burger = $this->product();
        Schema::drop('agent_tool_calls');
        [, $run] = $this->running($this->conversation);

        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertCreated();
        $this->api('GET', '/orders/products', [], $run)->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertFalse(app(AgentToolTrace::class)->ready());
        $this->artisan('agent:tools:reconcile')->expectsOutputToContain('no existe todavía')->assertSuccessful();
    }

    public function test_a_crash_after_the_order_is_saved_leaves_started_and_reconciliation_proves_the_order(): void
    {
        $burger = $this->product();
        [, $run] = $this->running($this->conversation);
        // The process dies after the order is committed and before the outcome can be written: only the "finish" update fails.
        AgentToolCall::updating(function (AgentToolCall $call) {
            if ($call->isDirty('status') && $call->status === 'succeeded') {
                throw new RuntimeException('killed');
            }
        });

        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertCreated();

        $call = AgentToolCall::firstOrFail();
        $this->assertSame('started', $call->status, 'Nobody recorded an outcome: the trace does not claim one.');
        $this->assertNull($call->resource_id);
        $this->assertNotNull($call->idempotency_ref, 'It was noted BEFORE the order was saved, so the order can be proven later.');

        $this->assertSame(['succeeded' => 0, 'failed' => 0, 'uncertain' => 0], app(AgentToolTrace::class)->reconcile(5), 'A call that is still recent is left alone.');
        AgentToolCall::flushEventListeners();
        $this->travel(6)->minutes();
        $this->artisan('agent:tools:reconcile')->expectsOutputToContain('1 hechas')->assertSuccessful();

        $call->refresh();
        $this->assertSame(['succeeded', 'reconciled', 'order', Order::first()->id, 'received'], [$call->status, $call->reason, $call->resource_type, $call->resource_id, $call->result_state]);
    }

    public function test_reconciliation_marks_what_it_cannot_prove_and_what_provably_did_not_happen(): void
    {
        AgentToolCall::create(['workspace_id' => $this->workspace->id, 'conversation_id' => $this->conversation->id, 'tool' => 'orders.store', 'kind' => 'write', 'auth' => 'chatbot', 'status' => 'started', 'idempotency_ref' => hash('sha256', 'never saved')]);
        AgentToolCall::create(['workspace_id' => $this->workspace->id, 'conversation_id' => $this->conversation->id, 'tool' => 'booking.book', 'kind' => 'write', 'auth' => 'chatbot', 'status' => 'started']);
        AgentToolCall::create(['workspace_id' => $this->workspace->id, 'conversation_id' => $this->conversation->id, 'tool' => 'orders.products', 'kind' => 'read', 'auth' => 'chatbot', 'status' => 'started']);
        $this->travel(10)->minutes();

        $this->assertSame(['succeeded' => 0, 'failed' => 1, 'uncertain' => 2], app(AgentToolTrace::class)->reconcile(5));

        $this->assertSame(['failed', 'no_result'], [AgentToolCall::where('tool', 'orders.store')->value('status'), AgentToolCall::where('tool', 'orders.store')->value('reason')]);
        $this->assertSame(['uncertain', 'no_outcome'], [AgentToolCall::where('tool', 'booking.book')->value('status'), AgentToolCall::where('tool', 'booking.book')->value('reason')]);
        $this->assertSame(0, AgentToolCall::where('status', 'started')->count());
    }

    public function test_an_unknown_outcome_of_an_external_agenda_is_traced_as_uncertain_with_the_appointment(): void
    {
        $call = AgentToolCall::create(['workspace_id' => $this->workspace->id, 'conversation_id' => $this->conversation->id, 'tool' => 'booking.book', 'kind' => 'write', 'auth' => 'chatbot', 'status' => 'started']);
        $request = Request::create('/api/agent/booking/appointments', 'POST');
        $response = new JsonResponse(['message' => 'texto del proveedor que no debe guardarse', 'reason' => 'uncertain', 'appointment_id' => 41], 409);

        app(AgentToolTrace::class)->finish($call, $request, $response, null, microtime(true));

        $call->refresh();
        $this->assertSame(['uncertain', 409, 'uncertain', 'appointment', 41], [$call->status, $call->http_status, $call->reason, $call->resource_type, $call->resource_id]);
        $this->assertNull($call->result_state);
        $this->assertStringNotContainsString('proveedor', json_encode($call->getAttributes()));
    }

    public function test_free_text_in_a_reason_is_never_stored(): void
    {
        $call = AgentToolCall::create(['workspace_id' => $this->workspace->id, 'tool' => 'orders.store', 'kind' => 'write', 'auth' => 'chatbot', 'status' => 'started']);

        app(AgentToolTrace::class)->finish($call, Request::create('/x'), new JsonResponse(['reason' => 'El teléfono 573001112233 no es válido'], 422), null, microtime(true));

        $this->assertSame('invalid', $call->fresh()->reason, 'The text is dropped and replaced by the fixed code of the status.');
        $this->assertStringNotContainsString('573001112233', json_encode($call->fresh()->getAttributes()));
        $this->assertSame('rejected', $call->fresh()->status);
    }

    // --- what the person who takes over sees ---------------------------------------------------------------------------------------------------

    public function test_the_person_sees_the_tools_of_this_conversation_only_and_never_those_of_another(): void
    {
        $burger = $this->product();
        $mine = $this->conversation;
        $other = $this->conversation('573009998877', $this->bot);
        [, $run] = $this->running($mine);
        [, $otherRun] = $this->running($other);

        $this->api('POST', '/orders', $this->order($burger->id), $run)->assertCreated();
        $this->api('POST', '/orders', $this->order($burger->id, 0), $run)->assertStatus(422);
        $this->api('GET', '/orders/products', [], $otherRun)->assertOk();

        $this->actAsMember($this->workspace, 'admin');
        $page = $this->get('/conversations?c='.$mine->id)->assertOk()->viewData('page')['props']['selected'];
        $this->assertSame(['Rechazado', 'Hecho'], array_column($page['tools'], 'statusLabel'));
        $this->assertSame(['Creación de un pedido', 'Creación de un pedido'], array_column($page['tools'], 'label'));
        $this->assertSame(['id', 'label', 'kind', 'status', 'statusLabel', 'replayed', 'when'], array_keys($page['tools'][0]), 'The panel gets no ids of orders, no reasons and nothing about the contact.');

        $otherPage = $this->get('/conversations?c='.$other->id)->assertOk()->viewData('page')['props']['selected'];
        $this->assertSame(['Consulta del catálogo'], array_column($otherPage['tools'], 'label'));
    }
}
