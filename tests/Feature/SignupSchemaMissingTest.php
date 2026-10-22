<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression of the incident "Regístrate" -> QueryException (`column "is_public" does not exist`): the code was running against a database whose sign-up
 * migration (`create_public_signup_tables`) had not been applied. The sign-up must say it has nothing to offer, not leak SQL.
 */
class SignupSchemaMissingTest extends TestCase
{
    use RefreshDatabase;

    private function withoutTheSignupMigration(): void
    {
        Schema::dropIfExists('signup_requests');
        Schema::dropIfExists('signup_verifications');
        Schema::table('plans', function ($table) {
            $table->dropForeign(['module_category_id']);
        });
        Schema::table('plans', fn ($table) => $table->dropColumn(['module_category_id', 'price_monthly_cents', 'price_yearly_cents', 'currency', 'is_public', 'requires_quote']));
    }

    public function test_the_wizard_opens_and_says_nothing_can_be_contracted_when_the_migration_is_not_applied(): void
    {
        $this->withoutTheSignupMigration();

        $this->get('/registro')->assertOk()->assertInertia(fn ($page) => $page->where('step', 'email')->where('offersAvailable', false)->where('solutions', [])->where('draft', null));
    }

    public function test_every_other_step_answers_a_controlled_503_without_sql_or_a_trace(): void
    {
        $this->withoutTheSignupMigration();

        foreach (['/registro/correo', '/registro/codigo', '/registro/empresa', '/registro/solucion', '/registro/plan', '/registro/contratar', '/registro/cotizacion', '/registro/cancelar'] as $uri) {
            $response = $this->post($uri, ['email' => 'ana@empresa.co']);
            $response->assertStatus(503);
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('is_public', $response->getContent());
        }

        $this->get('/registro/disponibilidad?code=ABC')->assertStatus(503);
        $this->get('/registro/bienvenida')->assertStatus(503);
    }
}
