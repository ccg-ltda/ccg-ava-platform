<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The visible brand has ONE source (config/branding.php): the page title, the shared props the layouts read and the emails all follow it. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_brand_is_zonno_platform_by_ccg_with_a_compact_form(): void
    {
        $this->assertSame(['Zonno Platform by CCG', 'Zonno by CCG'], [config('branding.name'), config('branding.short')]);
        $this->get('/pre-login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('brand', 'Zonno Platform by CCG')->where('brandShort', 'Zonno by CCG'))
            ->assertSee('<title inertia>Zonno Platform by CCG</title>', false);
    }

    public function test_zonno_by_ccg_is_a_configuration_value_that_reaches_the_page_the_props_and_the_emails(): void
    {
        config(['branding.name' => 'Zonno by CCG']);

        $this->get('/pre-login')->assertOk()
            ->assertInertia(fn ($page) => $page->where('brand', 'Zonno by CCG'))
            ->assertSee('<meta name="brand" content="Zonno by CCG">', false)
            ->assertSee('<title inertia>Zonno by CCG</title>', false);

        $mail = new SignupCodeMail('123456', 15);
        $this->assertSame('Tu código de verificación de Zonno by CCG', $mail->envelope()->subject);
        $this->assertStringContainsString('Zonno by CCG', $mail->render());
        $this->assertStringContainsString('Tu código de verificación de Zonno by CCG es:', (string) view('emails.signup-code', ['code' => '123456', 'minutes' => 15]));
    }

    public function test_the_brand_is_escaped_in_the_page(): void
    {
        config(['branding.name' => 'Marca <script>x</script> & "co"']);

        $html = $this->get('/pre-login')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function test_the_legal_name_and_internal_identifiers_do_not_follow_the_brand(): void
    {
        config(['branding.name' => 'Zonno by CCG']);

        $this->assertSame('DESARROLLO_DEV', config('workspace.admin_code'));
    }
}
