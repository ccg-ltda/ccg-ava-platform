<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Orders\CommerceSchema;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The person who takes over a conversation sees what was done from it (appointments and orders), with the state Ava has now. Read only, and it follows
 * the rules of the modules themselves: plan AND permission, only this conversation, never another Workspace.
 */
class ConversationOperationsTest extends TestCase
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
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Asistente']);
        $this->token = app(AgentAccess::class)->generate($this->bot);
        $this->conversation = $this->conversation('573001112233');
        Role::findOrCreate('lector-chats', 'web')->syncPermissions(['view-conversations']);
        Role::findOrCreate('solo-pedidos', 'web')->syncPermissions(['view-conversations', 'view-orders']);
    }

    private function conversation(string $contact): Conversation
    {
        return $this->bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => 'Laura'])->refresh();
    }

    /** Does what the assistant does from a conversation: books an appointment and places an order. */
    private function operate(string $contact = '573001112233'): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);
        $product = $this->workspace->products()->create(['name' => 'Hamburguesa', 'price' => '18500.00']);

        $this->withToken($this->token)->postJson('/api/agent/booking/appointments', ['channel' => 'whatsapp', 'contact_id' => $contact, 'service_id' => $service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => $contact]])->assertCreated();
        $this->withToken($this->token)->postJson('/api/agent/orders', ['channel' => 'whatsapp', 'contact_id' => $contact, 'delivery_method' => 'pickup', 'customer' => ['name' => 'Laura Pérez'], 'lines' => [['product_id' => $product->id, 'quantity' => 2]]], ['Idempotency-Key' => 'k-'.$contact])->assertCreated();
    }

    private function open(?Conversation $conversation = null, string $role = 'admin')
    {
        $this->actAsMember($this->workspace, $role);

        return $this->get('/conversations?c='.($conversation ?? $this->conversation)->id);
    }

    public function test_a_person_sees_the_appointments_and_orders_made_from_the_conversation_with_their_current_state(): void
    {
        $this->operate();

        $this->open()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('selected.operations', 2)
            ->where('selected.operations', fn ($rows) => collect($rows)->pluck('kind')->sort()->values()->all() === ['appointment', 'order']
                && collect($rows)->firstWhere('kind', 'order')['title'] === 'Pedido #1'
                && collect($rows)->firstWhere('kind', 'order')['statusLabel'] === 'Recibido'
                && collect($rows)->firstWhere('kind', 'order')['detail'] === '37,000.00 COP'
                && collect($rows)->firstWhere('kind', 'appointment')['title'] === 'Manicure'
                && collect($rows)->firstWhere('kind', 'appointment')['statusLabel'] !== null));
    }

    public function test_only_what_belongs_to_this_conversation_is_shown(): void
    {
        $this->operate();
        $other = $this->conversation('573009998877');

        $this->open($other)->assertInertia(fn (AssertableInertia $page) => $page->has('selected.operations', 0));
    }

    public function test_each_kind_needs_its_permission_and_its_module(): void
    {
        $this->operate();

        $this->open(null, 'lector-chats')->assertInertia(fn (AssertableInertia $page) => $page->has('selected.operations', 0));
        $this->open(null, 'solo-pedidos')->assertInertia(fn (AssertableInertia $page) => $page->has('selected.operations', 1)->where('selected.operations.0.kind', 'order'));

        $this->contractModules($this->workspace, ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings']);
        $this->open(null, 'admin')->assertInertia(fn (AssertableInertia $page) => $page->has('selected.operations', 1)->where('selected.operations.0.kind', 'appointment'));
    }

    public function test_without_the_migration_the_conversation_still_opens_and_shows_what_it_can(): void
    {
        $this->operate();
        // The orders' link to conversations does not exist in this environment yet: the page must not touch it.
        $this->app->instance(CommerceSchema::class, new class extends CommerceSchema
        {
            public function conversationLinkReady(): bool
            {
                return false;
            }
        });

        $this->open()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('selected.operations', 1)->where('selected.operations.0.kind', 'appointment'));
    }

    public function test_it_carries_no_contact_data(): void
    {
        $this->operate();

        $rows = $this->open()->viewData('page')['props']['selected']['operations'];

        $this->assertStringNotContainsString('573001112233', json_encode($rows));
        $this->assertStringNotContainsString('Laura', json_encode($rows));
    }
}
