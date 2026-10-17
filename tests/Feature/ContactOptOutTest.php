<?php

namespace Tests\Feature;

use App\Audit\AuditLogger;
use App\Campaigns\CampaignAudience;
use App\Customers\ContactOptOut;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Chatbot;
use App\Models\Customer;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ConversationService;
use App\Services\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * A contact who writes STOP is taken off marketing. The rule is narrow on purpose: an exact keyword, a customer already linked to that contact,
 * only ever `revoked`, audited without personal data, and it never fails the message that carried it.
 */
class ContactOptOutTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'customers'];

    private Workspace $workspace;

    private Chatbot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', self::MODULES);
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Asistente']);
    }

    private function customer(array $attributes = [], ?Workspace $workspace = null): Customer
    {
        return ($workspace ?? $this->workspace)->customers()->create($attributes + ['first_name' => 'Laura', 'marketing_consent' => 'granted', 'source' => 'manual']);
    }

    private function inbound(string $text, string $contact = '573001112233', string $channel = 'whatsapp', string $direction = 'in', ?string $externalId = null, ?Chatbot $bot = null): void
    {
        app(ConversationService::class)->record($bot ?? $this->bot, $channel, [
            'contact_id' => $contact, 'direction' => $direction, 'type' => 'text', 'body' => $text, 'external_id' => $externalId ?? 'wamid.'.uniqid(), 'sent_at' => now(),
        ]);
    }

    public function test_an_exact_keyword_revokes_the_consent_of_the_customer_linked_to_the_contact(): void
    {
        $linked = $this->customer(['first_name' => 'Por identidad']);
        $linked->identities()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'external_id' => '573001112233']);

        $this->inbound('STOP');

        $this->assertSame('revoked', $linked->fresh()->marketing_consent);
        $this->assertNotNull($linked->fresh()->consent_at);
        $this->assertStringContainsString('por mensaje (whatsapp)', $linked->fresh()->consent_note);
    }

    public function test_on_whatsapp_the_verified_number_of_the_conversation_finds_the_customer_by_phone_too(): void
    {
        $byPhone = $this->customer(['first_name' => 'Por teléfono', 'phone' => '+573001112233']);
        $local = $this->customer(['first_name' => 'Local', 'phone' => '573009998877']);
        $other = $this->customer(['first_name' => 'Otra', 'phone' => '+573005550000']);

        $this->inbound(' ¡Stop! ');
        $this->inbound('baja.', '573009998877');

        $this->assertSame('revoked', $byPhone->fresh()->marketing_consent);
        $this->assertSame('revoked', $local->fresh()->marketing_consent);
        $this->assertSame('granted', $other->fresh()->marketing_consent);
    }

    public function test_only_an_exact_keyword_counts_and_only_from_the_contact(): void
    {
        $customer = $this->customer(['phone' => '+573001112233']);

        foreach (['no quiero que me pongan stop', 'stop por favor', 'bajas de precio', 'baja de mi pedido', '', 'stopstop', 'sí'] as $text) {
            $this->inbound($text);
        }
        $this->inbound('STOP', '573001112233', 'whatsapp', 'out');

        $this->assertSame('granted', $customer->fresh()->marketing_consent);
        $this->assertSame(0, AuditLog::where('user_name', 'Contacto (baja por mensaje)')->count());
    }

    public function test_it_only_ever_revokes_and_never_touches_an_anonymized_record_or_creates_a_customer(): void
    {
        $unknown = $this->customer(['phone' => '+573001112233', 'marketing_consent' => 'unknown']);
        $revoked = $this->customer(['first_name' => 'Ya de baja', 'phone' => '+573009998877', 'marketing_consent' => 'revoked']);
        $anonymized = $this->customer(['first_name' => 'Cliente anonimizado', 'phone' => '+573005550000']);
        $anonymized->forceFill(['anonymized_at' => now()])->save();
        $count = Customer::count();

        $this->inbound('stop');
        $this->inbound('stop', '573009998877');
        $this->inbound('stop', '573005550000');
        $this->inbound('stop', '573007770000');

        $this->assertSame('revoked', $unknown->fresh()->marketing_consent);
        $this->assertSame('revoked', $revoked->fresh()->marketing_consent);
        $this->assertSame('granted', $anonymized->fresh()->marketing_consent);
        $this->assertSame($count, Customer::count(), 'A contact with no record gets none: nothing is created.');
        $this->assertSame(1, AuditLog::where('user_name', 'Contacto (baja por mensaje)')->count(), 'Only the one that actually changed is recorded.');
    }

    public function test_the_audit_is_automatic_and_carries_no_personal_data(): void
    {
        $customer = $this->customer(['first_name' => 'Laura', 'last_name' => 'Martínez', 'phone' => '+573001112233']);

        $this->inbound('STOP');

        $log = AuditLog::where('resource_type', 'customer')->where('user_name', 'Contacto (baja por mensaje)')->firstOrFail();
        $this->assertSame('system', $log->actor);
        $this->assertSame($this->workspace->id, $log->workspace_id);
        $this->assertSame("#{$customer->id}", $log->resource_label);
        $this->assertStringNotContainsString('Laura', json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('573001112233', json_encode($log->getAttributes()));
    }

    public function test_a_repeated_event_is_recorded_once_and_nothing_is_sent_back(): void
    {
        $customer = $this->customer(['phone' => '+573001112233']);

        $this->inbound('stop', '573001112233', 'whatsapp', 'in', 'wamid.same');
        $this->inbound('stop', '573001112233', 'whatsapp', 'in', 'wamid.same');

        $this->assertSame(1, AuditLog::where('user_name', 'Contacto (baja por mensaje)')->count());
        $this->assertSame(0, $this->bot->conversations()->first()->messages()->where('direction', 'out')->count());
        $this->assertSame('revoked', $customer->fresh()->marketing_consent);
    }

    public function test_it_needs_clientes_in_the_plan_and_never_crosses_a_workspace(): void
    {
        $mine = $this->customer(['phone' => '+573001112233']);
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        $theirs = $this->customer(['phone' => '+573001112233'], $other);

        $this->inbound('stop');
        $this->assertSame('revoked', $mine->fresh()->marketing_consent);
        $this->assertSame('granted', $theirs->fresh()->marketing_consent);

        $this->contractModules($this->workspace, ['dashboard', 'assistants', 'conversations']);
        $this->bot = $this->bot->fresh(); // a request loads the plan again; this process kept the old relation
        $again = $this->customer(['first_name' => 'Sin módulo', 'phone' => '+573009998877']);
        $this->inbound('stop', '573009998877');
        $this->assertSame('granted', $again->fresh()->marketing_consent);
    }

    public function test_a_revoked_customer_is_left_out_of_every_campaign(): void
    {
        $customer = $this->customer(['phone' => '+573001112233']);
        $campaign = Campaign::make(['workspace_id' => $this->workspace->id, 'name' => 'Oferta', 'body' => 'Hola', 'channel' => 'whatsapp']);
        $campaign->setRelation('workspace', $this->workspace);
        $audience = app(CampaignAudience::class);
        $this->assertNull($audience->exclusion($customer->fresh(), $campaign));

        $this->inbound('STOP');

        $this->assertSame('opted_out', $audience->exclusion($customer->fresh(), $campaign));
    }

    public function test_the_message_is_stored_even_when_the_opt_out_fails(): void
    {
        $this->customer(['phone' => '+573001112233']);
        $this->app->bind(ContactOptOut::class, fn () => new class(app(AuditLogger::class), app(ModuleAccess::class)) extends ContactOptOut
        {
            public function apply($workspace, string $channel, string $contactId, ?string $text): int
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->inbound('stop');

        $this->assertSame(1, $this->bot->conversations()->first()->messages()->where('direction', 'in')->count());
    }

    public function test_a_stop_reported_by_the_automation_through_the_agent_api_is_honoured(): void
    {
        $customer = $this->customer(['phone' => '+573001112233']);
        $integration = $this->workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '100200300'], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN']]);
        $this->bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        $token = app(AgentAccess::class)->generate($this->bot);

        $this->withToken($token)->postJson('/api/agent/messages', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'STOP', 'external_id' => 'wamid.api1'])->assertCreated();
        $this->withToken($token)->postJson('/api/agent/messages', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'STOP', 'external_id' => 'wamid.api1'])->assertOk();

        $this->assertSame('revoked', $customer->fresh()->marketing_consent);
        $this->assertSame(1, AuditLog::where('user_name', 'Contacto (baja por mensaje)')->count());
    }

    public function test_the_keyword_is_recognised_through_spacing_case_punctuation_and_invisible_characters_but_never_inside_a_sentence(): void
    {
        $optOut = app(ContactOptOut::class);

        foreach (['stop', 'STOP', '  Stop  ', 'stop.', '¡STOP!', "stop\n", "\u{00A0}baja\u{00A0}", "\u{200B}stop", 'Ｓｔｏｐ', 'BAJA', 'Baja!!!', "stop \u{1F6D1}"] as $text) {
            $this->assertTrue($optOut->isKeyword($text), "«{$text}» is an opt-out");
        }

        foreach ([null, '', '   ', 'stop por favor', 'no quiero parar', 'bajas de precio', 'baja de mi pedido', 'stopping', 's t o p', 'stop stop', 'hola, stop', 'quiero la baja', '0'] as $text) {
            $this->assertFalse($optOut->isKeyword($text), '«'.$text.'» is not an opt-out');
        }
    }

    public function test_an_opt_out_only_changes_consent_and_leaves_everything_operational_alone(): void
    {
        $customer = $this->customer(['phone' => '+573001112233', 'email' => 'laura@example.com', 'notes' => 'Prefiere la tarde']);

        $this->inbound('STOP');

        $fresh = $customer->fresh();
        $this->assertSame('revoked', $fresh->marketing_consent);
        $this->assertTrue($fresh->is_active, 'The customer is not deactivated: orders and bookings keep working.');
        $this->assertSame(['+573001112233', 'laura@example.com', 'Prefiere la tarde', 'Laura'], [$fresh->phone, $fresh->email, $fresh->notes, $fresh->first_name]);
        $this->assertSame(1, $this->bot->conversations()->first()->messages()->count(), 'The message itself is stored like any other.');
    }
}
