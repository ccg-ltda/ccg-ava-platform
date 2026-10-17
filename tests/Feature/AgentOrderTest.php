<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Workspace;
use App\Orders\OrderService;
use App\Services\AgentAccess;
use App\Services\ExecutionAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The order tools of Ava Chat (Delivery and E-commerce). The automation (n8n) is simulated by the test itself: this proves Ava's side of the
 * contract. What it must guarantee: the server owns every price and total, a retried call is the same order, a contact only reaches the orders of
 * THEIR conversation, nothing crosses a Workspace, and the assistant cannot move an order or promise what Ava does not do.
 */
class AgentOrderTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'product_catalog', 'orders', 'locations', 'customers'];

    private Workspace $shop;

    private Chatbot $bot;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->shop = $this->bookingWorkspace('TIENDA', self::MODULES);
        $this->shop->settingsOrDefault()->update(['currency' => 'COP']);
        [$this->bot, $this->token] = $this->bot($this->shop);
    }

    /** @return array{Chatbot, string} */
    private function bot(Workspace $workspace): array
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Atiende pedidos']);

        return [$bot, app(AgentAccess::class)->generate($bot)];
    }

    private function conversation(string $contact = '573001112233', ?Chatbot $bot = null, string $channel = 'whatsapp'): Conversation
    {
        $bot ??= $this->bot;

        return $bot->conversations()->create(['workspace_id' => $bot->workspace_id, 'channel' => $channel, 'contact_id' => $contact, 'contact_name' => 'Laura'])->refresh();
    }

    private function product(string $name = 'Hamburguesa', string $price = '18500.00', array $extra = [], ?Workspace $workspace = null): Product
    {
        return ($workspace ?? $this->shop)->products()->create($extra + ['name' => $name, 'price' => $price, 'sku' => null])->fresh();
    }

    /** An execution as it is while the workflow runs, and the token Ava gives that workflow. @return array{0: ChatbotExecution, 1: string} */
    private function running(Conversation $conversation): array
    {
        $message = $conversation->messages()->create(['workspace_id' => $conversation->workspace_id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Quiero pedir', 'external_id' => 'wamid.'.Str::random(8), 'sent_at' => now()]);
        $execution = ChatbotExecution::create([
            'correlation_id' => (string) Str::uuid(), 'workspace_id' => $conversation->workspace_id, 'chatbot_id' => $conversation->chatbot_id, 'conversation_id' => $conversation->id,
            'message_id' => $message->id, 'workflow_key' => 'support', 'n8n_workflow_id' => 'wf1', 'handling_version' => 0, 'status' => ChatbotExecution::RUNNING, 'attempts' => 1,
        ]);

        return [$execution, app(ExecutionAccess::class)->issue($execution)];
    }

    private function tool(string $method, string $path = '', array $data = [], ?string $token = null, array $headers = [])
    {
        return $this->withToken($token ?? $this->token)->json($method, "/api/agent/orders{$path}", $data, $headers);
    }

    /** @return array<string, mixed> */
    private function order(array $lines, array $overrides = [], string $contact = '573001112233'): array
    {
        return $overrides + ['channel' => 'whatsapp', 'contact_id' => $contact, 'delivery_method' => 'pickup', 'customer' => ['name' => 'Laura Pérez', 'phone' => $contact], 'lines' => $lines];
    }

    // --- discovery and reading -----------------------------------------------------------------------------------------------------

    public function test_the_agent_configuration_announces_the_order_tools_only_when_the_plan_has_them(): void
    {
        $this->withToken($this->token)->getJson('/api/agent/config')->assertOk()
            ->assertJsonPath('orders.available', true)
            ->assertJsonPath('orders.endpoints.products', url('/api/agent/orders/products'))
            ->assertJsonPath('orders.endpoints.orders', url('/api/agent/orders'));

        $this->contractModules($this->shop, ['dashboard', 'assistants', 'conversations', 'product_catalog']);
        $this->withToken($this->token)->getJson('/api/agent/config')->assertOk()->assertJsonPath('orders', ['available' => false]);
    }

    public function test_the_tools_do_not_exist_for_a_workspace_whose_plan_lacks_the_catalog_or_orders(): void
    {
        $this->contractModules($this->shop, ['dashboard', 'assistants', 'conversations', 'orders']);
        $this->tool('GET', '/products')->assertForbidden();

        $this->contractModules($this->shop, ['dashboard', 'assistants', 'conversations', 'product_catalog']);
        $this->tool('POST', '', $this->order([['product_id' => 1, 'quantity' => 1]]))->assertForbidden();

        $this->contractModules($this->shop, ['dashboard', 'assistants', 'product_catalog', 'orders']);
        $this->tool('GET', '/options')->assertForbidden();
    }

    public function test_the_assistant_reads_the_active_catalog_with_the_price_ava_has_now_and_no_promise_of_stock(): void
    {
        $this->product('Hamburguesa', '18500.00', ['category' => 'Comida', 'sku' => 'HAM-1']);
        $this->product('Gaseosa', '4000.50', ['category' => 'Bebidas']);
        $this->product('Retirado', '1.00', ['is_active' => false]);
        $this->product('Ajeno', '99.00', [], $this->bookingWorkspace('OTRA', self::MODULES));

        $response = $this->tool('GET', '/products')->assertOk()->assertJsonPath('stockTracked', false)->assertJsonPath('truncated', false);
        $this->assertSame(['Gaseosa', 'Hamburguesa'], collect($response->json('products'))->pluck('name')->all());
        $response->assertJsonPath('products.1.price', '18500.00')->assertJsonPath('products.1.currency', 'COP')->assertJsonPath('products.1.sku', 'HAM-1');

        $this->tool('GET', '/products?q=gaseo')->assertOk()->assertJsonCount(1, 'products');
        $this->tool('GET', '/products?category=Comida')->assertOk()->assertJsonPath('products.0.name', 'Hamburguesa');
        $this->tool('GET', '/products?q=%25')->assertOk()->assertJsonCount(0, 'products');
    }

    public function test_the_catalog_can_be_read_by_location_and_a_foreign_or_inactive_location_is_refused(): void
    {
        $centro = $this->shop->locations()->create(['name' => 'Centro']);
        $norte = $this->shop->locations()->create(['name' => 'Norte']);
        $inactive = $this->shop->locations()->create(['name' => 'Cerrada', 'is_active' => false]);
        $foreign = $this->bookingWorkspace('OTRA', self::MODULES)->locations()->create(['name' => 'Ajena']);
        $everywhere = $this->product('En todas');
        $onlyNorte = $this->product('Solo norte');
        $onlyNorte->locations()->sync([$norte->id => ['workspace_id' => $this->shop->id]]);

        $this->assertSame(['En todas'], collect($this->tool('GET', "/products?location_id={$centro->id}")->assertOk()->json('products'))->pluck('name')->all());
        $this->assertSame(['En todas', 'Solo norte'], collect($this->tool('GET', "/products?location_id={$norte->id}")->assertOk()->json('products'))->pluck('name')->all());
        $this->tool('GET', "/products?location_id={$inactive->id}")->assertStatus(422)->assertJsonPath('reason', 'invalid');
        $this->tool('GET', "/products?location_id={$foreign->id}")->assertStatus(422);
        $this->assertNotNull($everywhere);
    }

    public function test_the_options_tell_the_assistant_what_ava_does_not_do(): void
    {
        $this->shop->locations()->create(['name' => 'Centro']);

        $this->tool('GET', '/options')->assertOk()
            ->assertJsonPath('currency', 'COP')
            ->assertJsonPath('methods.delivery', 'Entrega a domicilio')
            ->assertJsonPath('locations.0.name', 'Centro')
            ->assertJsonPath('customerNotified', false)
            ->assertJsonPath('delivery.coverageChecked', false)
            ->assertJsonPath('delivery.shippingCost', null);
    }

    // --- placing an order ----------------------------------------------------------------------------------------------------------

    public function test_the_total_comes_from_the_catalog_never_from_what_the_assistant_sends(): void
    {
        $burger = $this->product('Hamburguesa', '18500.50');
        $soda = $this->product('Gaseosa', '4000.25');
        $conversation = $this->conversation();
        [, $run] = $this->running($conversation);

        $body = $this->order([['product_id' => $burger->id, 'quantity' => 3, 'price' => '1.00', 'unit_price' => 1, 'total' => 1], ['product_id' => $soda->id, 'quantity' => 2]], ['total' => '1.00', 'currency' => 'USD', 'status' => 'delivered', 'number' => 999, 'workspace_id' => 99, 'conversation_id' => 12345, 'idempotency_key' => 'x']);
        $response = $this->tool('POST', '', $body, $run)->assertCreated()->assertJsonPath('replayed', false);

        $response->assertJsonPath('order.total', '63502.00')->assertJsonPath('order.currency', 'COP')->assertJsonPath('order.status', 'received')->assertJsonPath('order.number', 1)->assertJsonPath('customerNotified', false);
        $this->assertSame(['Hamburguesa', 'Gaseosa'], collect($response->json('order.lines'))->pluck('product')->all());
        $order = Order::firstOrFail();
        $this->assertSame('63502.00', $order->total);
        $this->assertSame($this->shop->id, $order->workspace_id);
        $this->assertSame($conversation->id, $order->conversation_id);
        $this->assertNotSame('x', $order->idempotency_key);
        $this->assertSame('+573001112233', $order->customer_phone);
        $this->assertSame(1, $order->statusChanges()->count());
    }

    public function test_the_same_request_again_is_the_same_order_and_is_recorded_once(): void
    {
        $burger = $this->product();
        $conversation = $this->conversation();
        [, $run] = $this->running($conversation);
        $body = $this->order([['product_id' => $burger->id, 'quantity' => 2]]);

        $first = $this->tool('POST', '', $body, $run)->assertCreated();
        $again = $this->tool('POST', '', $body, $run)->assertOk()->assertJsonPath('replayed', true);
        // The same products in another order or split in two lines are the same content.
        $split = $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1], ['product_id' => $burger->id, 'quantity' => 1]]), $run)->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame($first->json('order.number'), $again->json('order.number'));
        $this->assertSame($first->json('order.number'), $split->json('order.number'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, AuditLog::where('resource_type', 'order')->where('action', 'created')->count());
    }

    public function test_the_same_key_with_another_content_is_refused_and_creates_nothing(): void
    {
        $burger = $this->product();
        $soda = $this->product('Gaseosa', '4000.00');
        [, $run] = $this->running($this->conversation());

        $first = $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), $run)->assertCreated();

        // Another quantity, another product, another note or another delivery address under the same key is NOT the same order.
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 2]]), $run)->assertStatus(409)->assertJsonPath('reason', 'idempotency_conflict');
        $this->tool('POST', '', $this->order([['product_id' => $soda->id, 'quantity' => 1]]), $run)->assertStatus(409);
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], ['notes' => 'Sin cebolla']), $run)->assertStatus(409);
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], ['delivery_method' => 'delivery', 'delivery_address' => 'Calle 1']), $run)->assertStatus(409);

        $this->assertSame(1, Order::count());
        $this->assertSame('18500.00', Order::first()->total, 'The refused attempts changed nothing.');
        $this->assertSame($first->json('order.number'), $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), $run)->assertOk()->json('order.number'));
    }

    public function test_a_pickup_order_ignores_delivery_data_so_it_is_still_the_same_content(): void
    {
        $burger = $this->product();
        $this->conversation();
        $lines = [['product_id' => $burger->id, 'quantity' => 1]];
        $key = ['Idempotency-Key' => 'k1'];

        $this->tool('POST', '', $this->order($lines, ['delivery_address' => 'Calle 9']), null, $key)->assertCreated();
        $this->tool('POST', '', $this->order($lines, ['delivery_address' => 'Otra calle', 'recipient_name' => 'Pedro']), null, $key)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, Order::count());
    }

    public function test_a_refused_request_does_not_burn_its_key_and_the_raw_key_is_never_stored(): void
    {
        $burger = $this->product();
        $this->conversation();
        $key = ['Idempotency-Key' => 'my-secret-looking-key-123'];

        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 0]]), null, $key)->assertStatus(422);
        $this->assertSame(0, Order::count());

        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), null, $key)->assertCreated();
        $order = Order::firstOrFail();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $order->idempotency_key);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $order->idempotency_fingerprint);
        $this->assertStringNotContainsString('my-secret-looking-key-123', json_encode($order->getAttributes()));
    }

    public function test_a_long_lived_chatbot_token_needs_an_idempotency_key_so_a_retry_cannot_duplicate_the_order(): void
    {
        $burger = $this->product();
        $this->conversation();
        $body = $this->order([['product_id' => $burger->id, 'quantity' => 1]]);

        $this->tool('POST', '', $body)->assertStatus(422)->assertJsonPath('reason', 'idempotency_key_required');
        $this->assertSame(0, Order::count());

        $this->tool('POST', '', $body, null, ['Idempotency-Key' => 'wamid-123'])->assertCreated();
        $this->tool('POST', '', $body, null, ['Idempotency-Key' => 'wamid-123'])->assertOk()->assertJsonPath('replayed', true);
        $this->tool('POST', '', $body, null, ['Idempotency-Key' => 'wamid-456'])->assertCreated()->assertJsonPath('order.number', 2);
        $this->tool('POST', '', $body, null, ['Idempotency-Key' => str_repeat('k', 129)])->assertStatus(422)->assertJsonPath('reason', 'idempotency_key_invalid');
        $this->tool('POST', '', $body, null, ['Idempotency-Key' => 'has a space'])->assertStatus(422)->assertJsonPath('reason', 'idempotency_key_invalid');
        $this->assertSame(2, Order::count());
    }

    public function test_the_same_key_in_two_conversations_never_returns_the_order_of_another_contact(): void
    {
        $burger = $this->product();
        $this->conversation('573001112233');
        $this->conversation('573009998877');
        $headers = ['Idempotency-Key' => 'same'];

        $first = $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], [], '573001112233'), null, $headers)->assertCreated();
        $second = $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], [], '573009998877'), null, $headers)->assertCreated();

        $this->assertNotSame($first->json('order.number'), $second->json('order.number'));
        $this->assertSame(2, Order::count());
    }

    public function test_a_delivery_order_needs_an_address_and_a_pickup_order_never_stores_one(): void
    {
        $burger = $this->product();
        $this->conversation();
        $lines = [['product_id' => $burger->id, 'quantity' => 1]];
        $key = ['Idempotency-Key' => 'k1'];

        $this->tool('POST', '', $this->order($lines, ['delivery_method' => 'delivery']), null, $key)->assertStatus(422);
        $this->assertSame(0, Order::count());

        $this->tool('POST', '', $this->order($lines, ['delivery_method' => 'delivery', 'delivery_address' => 'Calle 1 # 2-3', 'delivery_city' => 'Bogotá', 'recipient_name' => 'Pedro']), null, $key)
            ->assertCreated()->assertJsonPath('order.deliveryAddress', 'Calle 1 # 2-3')->assertJsonPath('order.methodLabel', 'Entrega a domicilio');

        $this->tool('POST', '', $this->order($lines, ['delivery_address' => 'Calle 9']), null, ['Idempotency-Key' => 'k2'])->assertCreated()->assertJsonPath('order.deliveryAddress', null);
    }

    public function test_the_answers_never_reveal_whether_something_exists_in_another_workspace_or_conversation(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        $theirs = $this->product('Producto ajeno', '7.00', [], $other);
        $this->conversation('573001112233');
        $this->conversation('573009998877');
        $burger = $this->product();
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], [], '573009998877'), null, ['Idempotency-Key' => 'a'])->assertCreated();
        $body = fn (int $productId) => $this->tool('POST', '', $this->order([['product_id' => $productId, 'quantity' => 1]]), null, ['Idempotency-Key' => 'b']);

        // A product of another Workspace is answered exactly like one that does not exist anywhere.
        $foreign = $body($theirs->id)->assertStatus(422);
        $missing = $body(987654)->assertStatus(422);
        $this->assertSame($missing->json('errors'), $foreign->json('errors'));
        $this->assertStringNotContainsString('ajeno', $foreign->getContent());

        // An order number of ANOTHER conversation is answered exactly like a number nobody has.
        $notMine = $this->tool('GET', '?channel=whatsapp&contact_id=573001112233&number=1')->assertOk();
        $nobody = $this->tool('GET', '?channel=whatsapp&contact_id=573001112233&number=999')->assertOk();
        $this->assertSame($nobody->json(), $notMine->json());
        $this->assertSame([], $notMine->json('orders'));
    }

    public function test_a_refused_order_never_looks_like_a_placed_one(): void
    {
        $burger = $this->product();
        $inactive = $this->product('Retirado', '5.00', ['is_active' => false]);
        $this->conversation();
        $conflictKey = ['Idempotency-Key' => 'same'];
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), null, $conflictKey)->assertCreated();

        $refusals = [
            $this->tool('POST', '', $this->order([['product_id' => $inactive->id, 'quantity' => 1]]), null, ['Idempotency-Key' => 'a']),
            $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 0]]), null, ['Idempotency-Key' => 'b']),
            $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), null),
            $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 3]]), null, $conflictKey),
        ];

        foreach ($refusals as $response) {
            $this->assertGreaterThanOrEqual(400, $response->status());
            $this->assertArrayNotHasKey('order', $response->json());
            $this->assertArrayNotHasKey('replayed', $response->json());
            $this->assertArrayNotHasKey('customerNotified', $response->json());
            $this->assertNotEmpty($response->json('message'));
        }
        $this->assertSame(1, Order::count(), 'Only the first request placed an order.');
    }

    public function test_an_inactive_unknown_or_foreign_product_is_refused_and_nothing_is_created(): void
    {
        $active = $this->product();
        $inactive = $this->product('Retirado', '5.00', ['is_active' => false]);
        $foreign = $this->product('Ajeno', '5.00', [], $this->bookingWorkspace('OTRA', self::MODULES));
        $this->conversation();
        $key = ['Idempotency-Key' => 'k'];

        $this->tool('POST', '', $this->order([['product_id' => $inactive->id, 'quantity' => 1]]), null, $key)->assertStatus(422);
        $this->tool('POST', '', $this->order([['product_id' => $foreign->id, 'quantity' => 1]]), null, $key)->assertStatus(422)->assertJsonValidationErrors('lines.0.product_id');
        $this->tool('POST', '', $this->order([['product_id' => 999999, 'quantity' => 1]]), null, $key)->assertStatus(422);
        $this->tool('POST', '', $this->order([['product_id' => $active->id, 'quantity' => 0]]), null, $key)->assertStatus(422);
        $this->tool('POST', '', $this->order([['product_id' => $active->id, 'quantity' => 10000]]), null, $key)->assertStatus(422);
        $this->tool('POST', '', $this->order([]), null, $key)->assertStatus(422);
        $this->tool('POST', '', $this->order([['product_id' => $active->id, 'quantity' => 1]], ['location_id' => $this->bookingWorkspace('LEJOS', self::MODULES)->locations()->create(['name' => 'Ajena'])->id]), null, $key)->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_the_contact_phone_must_be_international_unless_it_is_the_whatsapp_number_of_the_conversation(): void
    {
        $burger = $this->product();
        $this->conversation('573001112233');
        $lines = [['product_id' => $burger->id, 'quantity' => 1]];

        $this->tool('POST', '', $this->order($lines, ['customer' => ['name' => 'Laura', 'phone' => '3001112233']]), null, ['Idempotency-Key' => 'a'])->assertStatus(422)->assertJsonValidationErrors('customer.phone');
        $this->tool('POST', '', $this->order($lines, ['customer' => ['name' => 'Laura', 'phone' => '573009998877']]), null, ['Idempotency-Key' => 'b'])->assertStatus(422);
        $this->tool('POST', '', $this->order($lines, ['customer' => ['name' => 'Laura', 'phone' => '+573009998877']]), null, ['Idempotency-Key' => 'c'])->assertCreated();
        $this->tool('POST', '', $this->order($lines, ['customer' => ['name' => 'Laura', 'phone' => '573001112233']]), null, ['Idempotency-Key' => 'd'])->assertCreated();
        $this->assertSame('+573001112233', Order::orderByDesc('id')->first()->customer_phone);
    }

    public function test_a_known_customer_of_the_conversation_is_linked_and_nothing_is_created_in_clientes(): void
    {
        $burger = $this->product();
        $conversation = $this->conversation();
        $known = $this->shop->customers()->create(['first_name' => 'Laura', 'source' => 'manual']);
        $known->identities()->create(['workspace_id' => $this->shop->id, 'channel' => 'whatsapp', 'external_id' => '573001112233']);
        $stranger = $this->conversation('573005550000');

        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), null, ['Idempotency-Key' => 'a'])->assertCreated();
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], [], '573005550000'), null, ['Idempotency-Key' => 'b'])->assertCreated();

        $this->assertSame($known->id, Order::where('conversation_id', $conversation->id)->first()->customer_id);
        $this->assertNull(Order::where('conversation_id', $stranger->id)->first()->customer_id);
        $this->assertSame(1, Customer::count());

        $this->contractModules($this->shop, ['dashboard', 'assistants', 'conversations', 'product_catalog', 'orders']);
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 2]]), null, ['Idempotency-Key' => 'c'])->assertCreated();
        $this->assertNull(Order::orderByDesc('id')->first()->customer_id);
    }

    public function test_the_automatic_audit_names_the_assistant_and_carries_no_personal_data(): void
    {
        $burger = $this->product();
        $this->conversation();

        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], ['notes' => 'Sin cebolla']), null, ['Idempotency-Key' => 'a'])->assertCreated();

        $log = AuditLog::where('resource_type', 'order')->where('action', 'created')->firstOrFail();
        $this->assertSame('system', $log->actor);
        $this->assertSame('Asistente (automático)', $log->user_name);
        $this->assertSame($this->shop->id, $log->workspace_id);
        $this->assertSame('Pedido #1', $log->resource_label);
        $this->assertStringNotContainsString('Laura', json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('573001112233', json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('Sin cebolla', json_encode($log->getAttributes()));
    }

    // --- a contact only reaches their own orders -------------------------------------------------------------------------------------------

    public function test_a_contact_sees_only_the_orders_of_their_own_conversation(): void
    {
        $burger = $this->product();
        $this->conversation('573001112233');
        $this->conversation('573009998877');

        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], [], '573001112233'), null, ['Idempotency-Key' => 'a'])->assertCreated();
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 2]], [], '573009998877'), null, ['Idempotency-Key' => 'b'])->assertCreated();

        $mine = $this->tool('GET', '?channel=whatsapp&contact_id=573001112233')->assertOk()->assertJsonCount(1, 'orders');
        $mine->assertJsonPath('orders.0.number', 1)->assertJsonPath('orders.0.lines.0.quantity', 1)->assertJsonPath('orders.0.statusLabel', 'Recibido');
        $this->tool('GET', '?channel=whatsapp&contact_id=573009998877')->assertOk()->assertJsonPath('orders.0.number', 2);
        $this->tool('GET', '?channel=whatsapp&contact_id=573001112233&number=2')->assertOk()->assertJsonCount(0, 'orders');
        $this->tool('GET', '?channel=whatsapp&contact_id=573000000000')->assertNotFound()->assertJsonPath('reason', 'not_found');
        $this->tool('GET')->assertStatus(422);
    }

    public function test_the_assistant_tells_the_state_ava_really_has(): void
    {
        $burger = $this->product();
        $this->conversation();
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), null, ['Idempotency-Key' => 'a'])->assertCreated();

        $order = Order::firstOrFail();
        app(OrderService::class)->transition($order, 'confirmed', null);
        app(OrderService::class)->transition($order->fresh(), 'preparing', null);

        $this->tool('GET', '?channel=whatsapp&contact_id=573001112233')->assertOk()->assertJsonPath('orders.0.status', 'preparing')->assertJsonPath('orders.0.statusLabel', 'En preparación')->assertJsonPath('orders.0.isFinal', false);
    }

    public function test_a_new_order_does_not_change_when_the_catalog_changes_later(): void
    {
        $burger = $this->product('Hamburguesa', '18500.00');
        $this->conversation();
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 2]]), null, ['Idempotency-Key' => 'a'])->assertCreated();

        $burger->update(['price' => '99999.00', 'name' => 'Otra cosa', 'is_active' => false]);

        $this->tool('GET', '?channel=whatsapp&contact_id=573001112233')->assertOk()
            ->assertJsonPath('orders.0.total', '37000.00')->assertJsonPath('orders.0.lines.0.product', 'Hamburguesa')->assertJsonPath('orders.0.lines.0.unitPrice', '18500.00');
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 2]]), null, ['Idempotency-Key' => 'b'])->assertStatus(422);
    }

    // --- isolation -----------------------------------------------------------------------------------------------------------------------

    public function test_nothing_crosses_a_workspace(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        [$otherBot, $otherToken] = $this->bot($other);
        $mine = $this->product();
        $theirs = $this->product('De otra tienda', '7.00', [], $other);
        $this->conversation();
        $this->conversation('573001112233', $otherBot);

        $this->tool('POST', '', $this->order([['product_id' => $mine->id, 'quantity' => 1]]), null, ['Idempotency-Key' => 'a'])->assertCreated();

        // The other shop's assistant sees an empty history for the same contact id, and cannot order from this catalog.
        $this->tool('GET', '?channel=whatsapp&contact_id=573001112233', [], $otherToken)->assertOk()->assertJsonCount(0, 'orders');
        $this->tool('POST', '', $this->order([['product_id' => $mine->id, 'quantity' => 1]]), $otherToken, ['Idempotency-Key' => 'a'])->assertStatus(422);
        $this->assertSame(['De otra tienda'], collect($this->tool('GET', '/products', [], $otherToken)->assertOk()->json('products'))->pluck('name')->all());
        $this->tool('POST', '', $this->order([['product_id' => $theirs->id, 'quantity' => 1]]), null, ['Idempotency-Key' => 'b'])->assertStatus(422);

        $this->assertSame(1, Order::where('workspace_id', $this->shop->id)->count());
        $this->assertSame(0, Order::where('workspace_id', $other->id)->count());
    }

    public function test_the_database_refuses_to_tie_an_order_to_a_conversation_of_another_workspace(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        [$otherBot] = $this->bot($other);
        $foreign = $this->conversation('573001112233', $otherBot);
        $burger = $this->product();

        $this->expectException(QueryException::class);
        $this->shop->orders()->create(['number' => 1, 'customer_name' => 'X', 'delivery_method' => 'pickup', 'currency' => 'COP', 'total' => '1.00', 'conversation_id' => $foreign->id, 'idempotency_key' => 'z']);
        $this->assertNotNull($burger);
    }

    public function test_an_inactive_chatbot_or_workspace_cannot_use_the_tools(): void
    {
        $this->product();
        $this->conversation();

        $this->bot->update(['is_active' => false]);
        $this->tool('GET', '/products')->assertForbidden();
        $this->bot->update(['is_active' => true]);
        $this->shop->update(['is_active' => false]);
        $this->tool('GET', '/products')->assertForbidden();
        $this->shop->update(['is_active' => true]);
        $this->withToken('ava_not_a_token')->getJson('/api/agent/orders/products')->assertUnauthorized();
        $this->getJson('/api/agent/orders/products')->assertUnauthorized();
    }

    // --- the run-scoped token ------------------------------------------------------------------------------------------------------------

    public function test_the_run_scoped_token_works_only_for_the_conversation_of_its_execution(): void
    {
        $burger = $this->product();
        $mine = $this->conversation('573001112233');
        $this->conversation('573009998877');
        [, $run] = $this->running($mine);

        // It cannot name another contact, even by repeating the id of its own with another channel.
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]], [], '573009998877'), $run)->assertForbidden();
        $this->tool('GET', '?channel=whatsapp&contact_id=573009998877', [], $run)->assertForbidden();
        $this->tool('GET', '?channel=web&contact_id=573001112233', [], $run)->assertForbidden();
        $this->assertSame(0, Order::count());

        $this->tool('POST', '', ['delivery_method' => 'pickup', 'customer' => ['name' => 'Laura'], 'lines' => [['product_id' => $burger->id, 'quantity' => 1]]], $run)->assertCreated();
        $this->tool('GET', '', [], $run)->assertOk()->assertJsonCount(1, 'orders');
        // It reaches the order tools and the booking tools, never the configuration or the reports of the chatbot.
        $this->withToken($run)->getJson('/api/agent/config')->assertForbidden();
    }

    public function test_the_run_scoped_token_dies_when_a_person_takes_the_conversation_or_the_execution_ends(): void
    {
        $burger = $this->product();
        $conversation = $this->conversation();
        [$execution, $run] = $this->running($conversation);
        $body = ['delivery_method' => 'pickup', 'customer' => ['name' => 'Laura'], 'lines' => [['product_id' => $burger->id, 'quantity' => 1]]];

        $conversation->forceFill(['handling' => 'human', 'handling_version' => 1])->save();
        $this->tool('POST', '', $body, $run)->assertUnauthorized();

        $conversation->forceFill(['handling' => 'ai', 'handling_version' => 0])->save();
        $this->tool('POST', '', $body, $run)->assertCreated();
        $execution->forceFill(['status' => ChatbotExecution::SUCCEEDED])->save();
        $this->tool('POST', '', $body, $run)->assertUnauthorized();
        $this->assertSame(1, Order::count());
    }

    // --- what the assistant can never do --------------------------------------------------------------------------------------------------

    public function test_the_assistant_has_no_way_to_move_cancel_or_edit_an_order(): void
    {
        $burger = $this->product();
        $this->conversation();
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), null, ['Idempotency-Key' => 'a'])->assertCreated();

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->tool($method, '/1')->assertStatus(404);
        }
        $this->tool('POST', '/1/cancel')->assertNotFound();
        $this->tool('POST', '/1/status', ['status' => 'delivered'])->assertNotFound();
        $this->assertSame('received', Order::first()->status);
    }

    public function test_the_admin_form_cannot_set_the_conversation_or_the_key_of_an_order(): void
    {
        $burger = $this->product();
        $foreign = $this->conversation();
        $this->actAsMember($this->shop, 'admin');

        $this->post('/orders', ['customer_name' => 'Laura', 'delivery_method' => 'pickup', 'conversation_id' => $foreign->id, 'idempotency_key' => 'z', 'lines' => [['product_id' => $burger->id, 'quantity' => 1]]]);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertNull($order->conversation_id);
        $this->assertNull($order->idempotency_key);
    }

    // --- an environment that did not migrate yet --------------------------------------------------------------------------------------------

    public function test_without_the_migration_the_tools_say_so_and_the_admin_keeps_ordering(): void
    {
        $burger = $this->product();
        $this->conversation();
        (require database_path('migrations/2026_10_21_100000_link_orders_to_conversations.php'))->down();

        $this->tool('GET', '/products')->assertOk()->assertJsonPath('products.0.name', 'Hamburguesa');
        $this->tool('GET', '/options')->assertOk();
        $this->tool('POST', '', $this->order([['product_id' => $burger->id, 'quantity' => 1]]), null, ['Idempotency-Key' => 'a'])->assertStatus(503)->assertJsonPath('reason', 'not_ready');
        $this->tool('GET', '?channel=whatsapp&contact_id=573001112233')->assertStatus(503)->assertJsonPath('reason', 'not_ready');

        $this->actAsMember($this->shop, 'admin');
        $this->post('/orders', ['customer_name' => 'Laura', 'delivery_method' => 'pickup', 'lines' => [['product_id' => $burger->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }
}
