<?php

namespace Tests\Feature;

use App\Legal\LegalDocuments;
use App\Models\CommunicationConsent;
use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Public legal pages, the record of accepted versions and the separate choice about commercial communications.
 * Acceptance is off by default (the texts are drafts): turning it on only asks accounts to accept; it grants nothing.
 */
class LegalTest extends TestCase
{
    use RefreshDatabase;

    private array $slugs = ['terminos-y-condiciones', 'aviso-de-privacidad', 'politica-de-tratamiento-de-datos'];

    public function test_the_legal_pages_are_public_and_marked_as_draft(): void
    {
        $this->get('/legal')->assertOk()->assertInertia(fn ($p) => $p->component('Legal/Index')->where('draft', true)->has('documents', 3));

        foreach ($this->slugs as $slug) {
            $this->get("/legal/{$slug}")->assertOk()->assertInertia(fn ($p) => $p->component('Legal/Show')->where('draft', true)->has('document.sections'));
        }

        $this->get('/legal/does-not-exist')->assertNotFound();
        $this->get('/legal/..%2Fterms')->assertNotFound();
    }

    public function test_company_data_is_never_invented_and_shows_as_pending_until_configured(): void
    {
        $text = json_encode($this->get('/legal/aviso-de-privacidad')->viewData('page')['props']['document'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('[POR CONFIRMAR: razón social]', $text);
        $this->assertStringContainsString('[POR CONFIRMAR: canal para consultas', $text);

        config(['legal.responsible.legal_name' => 'Empresa de Prueba S.A.S.', 'legal.reviewed' => true]);

        $page = $this->get('/legal/aviso-de-privacidad')->viewData('page')['props'];
        $this->assertStringContainsString('Empresa de Prueba S.A.S.', json_encode($page['document'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('[POR CONFIRMAR: razón social]', json_encode($page['document'], JSON_UNESCAPED_UNICODE));
        $this->assertFalse($page['draft']);
    }

    public function test_the_visible_links_use_the_configured_slugs(): void
    {
        $source = file_get_contents(resource_path('js/Components/LegalLinks.jsx'));

        foreach (config('legal.documents') as $document) {
            $this->assertStringContainsString("'{$document['slug']}'", $source);
        }
        foreach (['Pages/Auth/Login.jsx' => 'AccessLayout', 'Layouts/AccessLayout.jsx' => 'LegalLinks', 'Layouts/GuestLayout.jsx' => 'LegalLinks'] as $file => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(resource_path("js/{$file}")));
        }
    }

    public function test_nobody_is_asked_to_accept_while_acceptance_is_off(): void
    {
        $user = User::factory()->create();

        $this->actingAsWorkspaceMember($user)->get('/profile')->assertOk();
        $this->assertSame(0, LegalAcceptance::count());
    }

    public function test_with_acceptance_on_an_account_must_accept_before_using_the_platform(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();

        $this->actingAsWorkspaceMember($user)->get('/profile')->assertRedirect(route('legal.accept'));
        $this->actingAsWorkspaceMember($user)->get('/dashboard')->assertRedirect(route('legal.accept'));

        // It can still read the texts, see the screen and sign out; guests are not affected.
        $this->actingAs($user)->get(route('legal.accept'))->assertOk()->assertInertia(fn ($p) => $p->component('Legal/Accept')->has('pending', 2)->where('marketing', false));
        $this->actingAs($user)->get('/legal/terminos-y-condiciones')->assertOk();
        $this->actingAs($user)->post('/logout')->assertRedirect();
        auth()->logout();
        $this->get('/legal')->assertOk();
        $this->get('/pre-login')->assertOk();
    }

    public function test_accepting_records_each_current_version_with_date_and_ip_and_nothing_about_marketing(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice']])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $rows = LegalAcceptance::where('user_id', $user->id)->orderBy('document')->get();
        $this->assertSame(['privacy_notice', 'terms'], $rows->pluck('document')->all());
        $this->assertSame(['0.1', '0.1'], $rows->pluck('version')->all());
        $this->assertSame('203.0.113.7', $rows->first()->ip_address);
        $this->assertNotNull($rows->first()->accepted_at);

        // The commercial authorization is separate and optional: not granting it neither blocks access nor leaves a record.
        $this->assertSame(0, CommunicationConsent::count());
        $this->actingAsWorkspaceMember($user)->get('/profile')->assertOk();
    }

    public function test_every_pending_document_is_required_and_a_partial_acceptance_records_nothing(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => ['terms']])->assertSessionHasErrors('accept');
        $this->actingAs($user)->post(route('legal.accept.store'), [])->assertSessionHasErrors('accept');
        // The optional text cannot be passed off as a required one, nor an unknown key recorded.
        $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => ['terms', 'data_policy', 'nonsense']])->assertSessionHasErrors('accept');

        $this->assertSame(0, LegalAcceptance::count());
    }

    public function test_a_new_version_asks_again_and_keeps_the_old_evidence(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice']]);
        $this->actingAsWorkspaceMember($user)->get('/profile')->assertOk();

        config(['legal.documents.terms.version' => '0.2']);

        $this->actingAsWorkspaceMember($user)->get('/profile')->assertRedirect(route('legal.accept'));
        $this->actingAs($user)->get(route('legal.accept'))->assertInertia(fn ($p) => $p->has('pending', 1)->where('pending.0.key', 'terms')->where('pending.0.version', '0.2'));

        $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => ['terms']])->assertSessionHasNoErrors();

        $this->assertSame(['0.1', '0.2'], LegalAcceptance::where('user_id', $user->id)->where('document', 'terms')->orderBy('version')->pluck('version')->all());
        $this->actingAsWorkspaceMember($user)->get('/profile')->assertOk();
    }

    public function test_acceptance_belongs_to_each_account(): void
    {
        config(['legal.acceptance_required' => true]);
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice']]);

        $this->actingAsWorkspaceMember($a)->get('/profile')->assertOk();
        $this->actingAsWorkspaceMember($b)->get('/profile')->assertRedirect(route('legal.accept'));
        $this->assertSame(0, LegalAcceptance::where('user_id', $b->id)->count());
    }

    public function test_commercial_communications_are_a_separate_revocable_choice_with_history(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Refusing when nothing was ever granted records nothing.
        $this->actingAsWorkspaceMember($user)->put('/profile/communications', ['marketing' => false])->assertSessionHasNoErrors();
        $this->assertSame(0, CommunicationConsent::count());

        $this->actingAsWorkspaceMember($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->put('/profile/communications', ['marketing' => true]);
        $this->actingAsWorkspaceMember($user)->put('/profile/communications', ['marketing' => true]); // unchanged: no new row
        $this->actingAsWorkspaceMember($user)->put('/profile/communications', ['marketing' => false]);

        $rows = CommunicationConsent::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame([true, false], $rows->pluck('granted')->all());
        $this->assertSame('profile', $rows->first()->source);
        $this->assertSame('203.0.113.9', $rows->first()->ip_address);
        $this->assertSame('0.1', $rows->first()->privacy_notice_version);
        $this->assertSame(0, CommunicationConsent::where('user_id', $other->id)->count());

        $this->actingAsWorkspaceMember($user)->get('/profile')->assertInertia(fn ($p) => $p->where('marketing', false));
        $this->actingAsWorkspaceMember($user)->put('/profile/communications', [])->assertSessionHasErrors('marketing');
    }

    public function test_marketing_can_be_granted_while_accepting_but_never_by_accepting(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice'], 'marketing' => true]);

        $this->assertTrue(CommunicationConsent::where('user_id', $user->id)->latest('id')->first()->granted);
        $this->assertSame('acceptance', CommunicationConsent::first()->source);
    }

    public function test_the_profile_lists_what_the_account_accepted_and_only_its_own(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice']]);
        $this->actingAs($other)->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice']]);

        $this->actingAsWorkspaceMember($user)->get('/profile')->assertInertia(fn ($p) => $p->has('accepted', 2));
    }

    public function test_a_guest_cannot_use_the_acceptance_or_communication_endpoints(): void
    {
        $this->get(route('legal.accept'))->assertRedirect();
        $this->post(route('legal.accept.store'), ['accept' => ['terms']])->assertRedirect();
        $this->put('/profile/communications', ['marketing' => true])->assertRedirect();
        $this->assertSame(0, LegalAcceptance::count() + CommunicationConsent::count());
    }

    private function dropRecordTables(): void
    {
        Schema::drop('communication_consents');
        Schema::drop('legal_acceptances');
    }

    public function test_without_the_migration_the_profile_still_works_and_says_the_record_is_unavailable(): void
    {
        $user = User::factory()->create();
        $this->dropRecordTables();

        $this->actingAsWorkspaceMember($user)->get('/profile')->assertOk()
            ->assertInertia(fn ($p) => $p->where('legalAvailable', false)->where('marketing', false)->where('accepted', []));
        $this->get('/legal/aviso-de-privacidad')->assertOk();
    }

    public function test_without_the_migration_nothing_is_written_and_the_refusal_is_visible(): void
    {
        $user = User::factory()->create();
        $this->dropRecordTables();

        $this->actingAsWorkspaceMember($user)->from('/profile')->put('/profile/communications', ['marketing' => true])
            ->assertRedirect('/profile')
            ->assertSessionHas('error', LegalDocuments::UNAVAILABLE);

        $this->actingAs($user)->from('/profile')->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice']])
            ->assertSessionHas('error', LegalDocuments::UNAVAILABLE);
    }

    public function test_with_acceptance_required_and_no_tables_the_platform_refuses_instead_of_letting_anyone_in_unrecorded(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();
        $this->dropRecordTables();

        $this->actingAsWorkspaceMember($user)->get('/profile')->assertStatus(503);
        $this->actingAs($user)->get(route('legal.accept'))->assertStatus(503);
        // The texts, the sign-out and guests keep working.
        $this->actingAs($user)->get('/legal')->assertOk();
        $this->actingAs($user)->post('/logout')->assertRedirect();
        auth()->logout();
        $this->get('/pre-login')->assertOk();
    }

    public function test_diagnose_reports_the_missing_tables_and_the_unconfigured_mail(): void
    {
        $this->dropRecordTables();
        $this->artisan('integrations:diagnose')->expectsOutputToContain('Faltan las tablas de aceptaciones')->expectsOutputToContain('Correo no configurado');

        config(['legal.acceptance_required' => true]);
        $this->artisan('integrations:diagnose')->expectsOutputToContain('toda cuenta recibe un 503')->assertFailed();
    }

    public function test_nothing_to_accept_is_not_an_error(): void
    {
        config(['legal.acceptance_required' => true]);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('legal.accept.store'), ['accept' => ['terms', 'privacy_notice']]);

        $this->actingAs($user)->post(route('legal.accept.store'), [])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, LegalAcceptance::count());
    }

    public function test_the_legal_pages_have_the_structure_assistive_technology_needs(): void
    {
        $show = file_get_contents(resource_path('js/Pages/Legal/Show.jsx'));
        $layout = file_get_contents(resource_path('js/Layouts/GuestLayout.jsx'));
        $links = file_get_contents(resource_path('js/Components/LegalLinks.jsx'));

        $this->assertStringContainsString('<h2', $show);
        $this->assertStringContainsString('aria-label="Otros documentos"', $show);
        $this->assertStringContainsString('<main', $layout);
        $this->assertStringContainsString('aria-label="Información legal"', $links);
        $this->assertStringContainsString('lang="', file_get_contents(resource_path('views/app.blade.php')));
    }
}
