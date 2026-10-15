<?php

namespace Tests\Feature;

use App\Campaigns\MessageTemplates;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The length of a message body follows the BODY component of a WhatsApp template (1024 characters). The count is in Unicode characters, a line
 * break is one, {{variables}} count as written, nothing is cut silently, and templates and campaigns apply the very same rule. Templates are
 * still local drafts: no call to Meta is made, so there is no approval or status to simulate.
 */
class MessageLimitsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'customers', 'campaigns']);
        $this->actAsMember($this->workspace);
    }

    private function template(string $body): TestResponse
    {
        return $this->post('/campaigns/templates', ['name' => 'Plantilla '.$this->workspace->messageTemplates()->count(), 'body' => $body]);
    }

    public function test_the_configured_limit_is_the_whatsapp_body_limit(): void
    {
        $this->assertSame(1024, config('campaigns.body_max'));
        $this->get('/campaigns?tab=templates')->assertInertia(fn (AssertableInertia $page) => $page->where('bodyMax', 1024));
    }

    public function test_a_body_of_exactly_the_limit_is_saved_untouched_and_one_more_is_refused(): void
    {
        $this->template(str_repeat('a', 1024))->assertSessionHasNoErrors();
        $this->assertSame(1024, mb_strlen($this->workspace->messageTemplates()->firstOrFail()->body));

        $this->template(str_repeat('a', 1025))->assertSessionHasErrors(['body' => 'El mensaje tiene 1.025 caracteres y el máximo del cuerpo de una plantilla de WhatsApp es 1.024: sobran 1. Acórtalo; Ava no lo recorta por ti.']);
        $this->assertSame(1, $this->workspace->messageTemplates()->count(), 'nothing was saved, nothing was truncated');
    }

    public function test_the_message_says_the_limit_and_the_excess(): void
    {
        $this->template(str_repeat('b', 1100))->assertSessionHasErrors('body');
        $message = session('errors')->first('body');

        $this->assertStringContainsString('1.100 caracteres', $message);
        $this->assertStringContainsString('1.024', $message);
        $this->assertStringContainsString('sobran 76', $message);
    }

    public function test_emojis_and_accents_count_as_one_character_each(): void
    {
        // 1024 emojis are 4096 bytes and 2048 UTF-16 units, but 1024 characters.
        $this->template(str_repeat('😀', 1024))->assertSessionHasNoErrors();
        $this->template(str_repeat('é', 1024).'x')->assertSessionHasErrors('body');
        $this->assertSame(1024, mb_strlen($this->workspace->messageTemplates()->firstOrFail()->body));
    }

    public function test_a_line_break_is_one_character_whether_the_browser_sends_it_as_n_or_rn(): void
    {
        // 511 x "a\r\n" is 1533 raw bytes but 1022 characters; with "aa" it is exactly 1024.
        $this->template(str_repeat("a\r\n", 511).'aa')->assertSessionHasNoErrors();
        $stored = $this->workspace->messageTemplates()->firstOrFail()->body;

        $this->assertStringNotContainsString("\r", $stored);
        $this->assertSame(1024, mb_strlen($stored));
        $this->template(str_repeat("a\r\n", 513))->assertSessionHasErrors('body');
    }

    public function test_variables_count_as_written_not_as_the_value_that_will_replace_them(): void
    {
        $filler = str_repeat('a', 1024 - strlen('{{nombre_completo}}'));

        $this->template($filler.'{{nombre_completo}}')->assertSessionHasNoErrors();
        $this->template($filler.'{{nombre_completo}}b')->assertSessionHasErrors('body');
    }

    public function test_malformed_double_braces_are_explained_and_known_variables_still_work(): void
    {
        $message = 'Revisa las llaves dobles: una variable se escribe como {{nombre}}. Ava no usa variables numeradas como {{1}} ni llaves sueltas.';

        foreach (['Hola {{1}}', 'Hola {{nombre', 'Hola nombre}}', 'Hola {{}}', 'Hola {{ nombre completo }}'] as $body) {
            $this->template($body)->assertSessionHasErrors(['body' => $message]);
        }
        $this->template('Hola {nombre}')->assertSessionHasNoErrors(); // single braces are plain text
        $this->template('Hola {{ nombre }}, {{apellido}}')->assertSessionHasNoErrors();
        $this->template('Hola {{Nombre}}')->assertSessionHasErrors('body');
        $this->assertStringContainsString('Variables que no existen', session('errors')->first('body'));
        $this->assertSame(['nombre'], app(MessageTemplates::class)->used('Hola {{ nombre }} {{nombre}}'));
    }

    public function test_the_same_rule_protects_campaigns_on_create_and_on_update(): void
    {
        $this->post('/campaigns', ['name' => 'Larga', 'body' => str_repeat('a', 1025), 'channel' => 'whatsapp', 'audience' => 'all'])->assertSessionHasErrors('body');
        $this->post('/campaigns', ['name' => 'Justa', 'body' => str_repeat('a', 1024), 'channel' => 'whatsapp', 'audience' => 'all'])->assertSessionHasNoErrors();
        $campaign = $this->workspace->campaigns()->firstOrFail();

        $this->put("/campaigns/{$campaign->id}", ['name' => 'Justa', 'body' => str_repeat('a', 1030), 'channel' => 'whatsapp', 'audience' => 'all'])->assertSessionHasErrors('body');
        $this->assertSame(1024, mb_strlen($campaign->fresh()->body));
        $this->post('/campaigns', ['name' => 'Rota', 'body' => 'Hola {{1}}', 'channel' => 'whatsapp', 'audience' => 'all'])->assertSessionHasErrors('body');
    }

    public function test_a_manipulated_request_cannot_pass_the_limit_and_the_save_stays_audited(): void
    {
        // The editor refuses the text, but the server never trusts it: a direct request gets the same answer.
        $this->postJson('/campaigns/templates', ['name' => 'Directa', 'body' => str_repeat('x', 5000)])->assertStatus(422)->assertJsonValidationErrors('body');

        $this->template('Hola {{nombre}}')->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::where('resource_type', 'message_template')->where('action', 'created')->exists());
    }

    public function test_the_limit_does_not_leak_to_the_professional_form(): void
    {
        $this->bookingWorkspace('CLINICA');
        $clinic = Workspace::where('code', 'CLINICA')->firstOrFail();
        $this->actAsMember($clinic);

        $this->post('/professionals', ['name' => 'Ana', 'specialty' => str_repeat('s', 120), 'phone' => '+573001112233'])->assertSessionHasNoErrors();
        $this->post('/professionals', ['name' => 'Beto', 'specialty' => str_repeat('s', 121)])->assertSessionHasErrors('specialty');
    }
}
