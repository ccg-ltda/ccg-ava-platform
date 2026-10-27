<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** The «Ayuda» panel: content from config/help.php, manuals filtered by the plan of the Workspace and by the existence of the file, nothing invented. */
class HelpPanelTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        config(['branding.support_email' => null]);
        $this->workspace = $this->bookingWorkspace('CLIENTE', ['dashboard', 'customers']);
        $this->actAsMember($this->workspace);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function manualFile(string $name): void
    {
        $path = resource_path('manuals/'.$name);
        file_put_contents($path, "%PDF-1.4\n% test manual\n");
        $this->created[] = $path;
    }

    public function test_every_member_gets_the_faq_and_the_support_channels_that_really_exist(): void
    {
        $this->get('/customers')->assertInertia(fn ($page) => $page
            ->has('help.faq')->where('help.manuals', [])->where('help.supportEmail', null)->where('help.chat', false)->where('help.chatState', 'unavailable'));

        config(['branding.support_email' => 'soporte@ccg.test']);
        $this->get('/customers')->assertInertia(fn ($page) => $page->where('help.supportEmail', 'soporte@ccg.test'));
    }

    public function test_the_support_button_is_a_mailto_draft_with_one_recipient_and_a_subject_and_sends_nothing(): void
    {
        config(['branding.support_email' => 'soporte@ccg.test']);
        Mail::fake();
        Notification::fake();

        $this->get('/profile')->assertInertia(fn ($page) => $page->where('help.mailto', function ($mailto) {
            parse_str((string) parse_url($mailto, PHP_URL_QUERY), $query);

            return str_starts_with($mailto, 'mailto:soporte%40ccg.test?')
                && $query['subject'] === 'Solicitud de soporte — Zonno Platform by CCG'
                && str_contains($query['body'], 'Workspace: CLIENTE')
                && ! str_contains($mailto, "\r") && ! str_contains($mailto, "\n");
        }));

        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_no_mailto_exists_without_one_valid_address_and_a_malicious_value_never_adds_recipients_or_headers(): void
    {
        foreach ([null, '', 'no-es-correo', 'a@b.co,c@d.co', 'a@b.co;c@d.co', "a@b.co\r\nBcc: x@y.co", 'Nombre <a@b.co>', 'a b@c.co', '"a"@b.co'] as $bad) {
            config(['branding.support_email' => $bad]);

            $this->get('/profile')->assertInertia(fn ($page) => $page->where('help.mailto', null)->where('help.supportEmail', null));
        }
    }

    public function test_the_user_name_cannot_inject_a_header_into_the_draft(): void
    {
        config(['branding.support_email' => 'soporte@ccg.test']);
        auth()->user()->forceFill(['name' => "Ana\r\nBcc: spy@evil.test"])->save();

        $this->get('/profile')->assertInertia(fn ($page) => $page->where('help.mailto', function ($mailto) {
            return ! str_contains($mailto, "\r") && ! str_contains($mailto, "\n") && ! str_contains($mailto, 'Bcc:') && str_contains(rawurldecode($mailto), 'Usuario: Ana Bcc: spy@evil.test');
        }));
    }

    public function test_the_questions_say_how_to_tell_a_missing_module_from_a_missing_permission(): void
    {
        $text = json_encode(config('help.faq'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('no tiene acceso según su plan', $text);
        $this->assertStringContainsString('tu rol no tiene permiso', $text);
    }

    public function test_a_manual_is_listed_and_served_only_when_its_file_exists_and_its_module_is_in_the_plan(): void
    {
        $this->manualFile('guia-clientes.pdf');
        $this->manualFile('guia-general.pdf');
        config(['help.manuals' => [
            ['key' => 'clientes', 'title' => 'Clientes', 'description' => 'Fichas', 'version' => '1.0', 'module' => 'customers', 'file' => 'guia-clientes.pdf'],
            ['key' => 'general', 'title' => 'Primeros pasos', 'module' => null, 'file' => 'guia-general.pdf'],
            ['key' => 'pedidos', 'title' => 'Pedidos', 'module' => 'orders', 'file' => 'guia-general.pdf'],
            ['key' => 'fantasma', 'title' => 'Sin archivo', 'module' => null, 'file' => 'no-existe.pdf'],
        ]]);

        $this->get('/customers')->assertInertia(fn ($page) => $page->where('help.manuals', fn ($manuals) => collect($manuals)->pluck('key')->sort()->values()->all() === ['clientes', 'general']));

        $response = $this->get('/help/manuals/clientes')->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->get('/help/manuals/general')->assertOk();
        $this->get('/help/manuals/pedidos')->assertNotFound();
        $this->get('/help/manuals/fantasma')->assertNotFound();
        $this->get('/help/manuals/no-existe')->assertNotFound();
    }

    public function test_a_module_manual_also_needs_the_permission_that_opens_that_module(): void
    {
        $this->manualFile('guia-clientes.pdf');
        config(['help.manuals' => [['key' => 'clientes', 'title' => 'Clientes', 'module' => 'customers', 'file' => 'guia-clientes.pdf']]]);
        Role::findOrCreate('solo_dashboard', 'web')->syncPermissions(['view-dashboard']);

        // The plan has the module but this role cannot open it: not listed, not served.
        $this->actAsMember($this->workspace, 'solo_dashboard');
        $this->get('/profile')->assertInertia(fn ($page) => $page->where('help.manuals', []));
        $this->get('/help/manuals/clientes')->assertNotFound();

        // A role that can open Clientes gets it.
        $this->actAsMember($this->workspace, 'admin');
        $this->get('/help/manuals/clientes')->assertOk();
    }

    public function test_a_configured_file_name_can_never_point_outside_the_manuals_folder(): void
    {
        config(['help.manuals' => [['key' => 'trampa', 'title' => 'Trampa', 'module' => null, 'file' => '../../.env']]]);

        $this->get('/customers')->assertInertia(fn ($page) => $page->where('help.manuals', []));
        $this->get('/help/manuals/trampa')->assertNotFound();
    }

    public function test_guests_get_no_help_data_and_no_manual(): void
    {
        $this->manualFile('guia.pdf');
        config(['help.manuals' => [['key' => 'general', 'title' => 'General', 'module' => null, 'file' => 'guia.pdf']]]);
        auth()->logout();
        $this->flushSession();

        $this->get('/help/manuals/general')->assertRedirect();
    }
}
