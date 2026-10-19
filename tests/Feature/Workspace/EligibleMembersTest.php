<?php

namespace Tests\Feature\Workspace;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The selector of the add-member form lists only the accounts that may really be added to THAT Workspace, finds all of them by name or email,
 * reveals nothing else, and the add action decides again: the interface never differs from what the server accepts.
 */
class EligibleMembersTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $admin;

    private Workspace $client;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $org = Organization::create(['name' => 'Org']);
        $this->admin = Workspace::create(['organization_id' => $org->id, 'code' => 'ADMIN_WS', 'name' => 'Plataforma']);
        $this->client = Workspace::create(['organization_id' => $org->id, 'code' => 'CLIENTE', 'name' => 'Cliente']);
        $this->other = Workspace::create(['organization_id' => $org->id, 'code' => 'OTRO', 'name' => 'Otro cliente']);
        config(['workspace.admin_code' => $this->admin->code]);
    }

    private function superuser(): User
    {
        $user = User::factory()->create(['is_superuser' => true]);
        $user->forceFill(['is_superuser' => true])->save();
        $this->admin->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $this->admin->id]);

        return $user;
    }

    private function names(Workspace $workspace, string $query = ''): array
    {
        return collect($this->getJson("/workspaces/{$workspace->id}/eligible-users".($query !== '' ? '?q='.urlencode($query) : ''))->assertOk()->json('data'))->pluck('name')->all();
    }

    public function test_all_fifteen_eligible_accounts_are_found_and_nobody_else(): void
    {
        $this->superuser();
        foreach (range(1, 15) as $n) {
            User::factory()->create(['name' => sprintf('Persona %02d', $n), 'email' => "persona{$n}@example.com"]);
        }

        $listed = $this->names($this->client);
        $this->assertCount(15, $listed);

        // Typing finds any of them, by name or by email, whatever the case.
        $this->assertSame(['Persona 07'], $this->names($this->client, 'persona 07'));
        $this->assertSame(['Persona 12'], $this->names($this->client, 'PERSONA12@EXAMPLE'));
    }

    public function test_members_inactive_accounts_and_internal_accounts_are_not_listed_for_a_client_workspace(): void
    {
        $this->superuser();
        $free = User::factory()->create(['name' => 'Libre']);
        $member = User::factory()->create(['name' => 'Ya es miembro']);
        $this->client->users()->attach($member->id, ['role' => 'cliente']);
        User::factory()->create(['name' => 'Desactivada', 'is_active' => false]);
        $staff = User::factory()->create(['name' => 'Interna de la plataforma']);
        $this->admin->users()->attach($staff->id, ['role' => 'soporte']);
        User::factory()->create(['name' => 'Superusuaria', 'is_superuser' => true])->forceFill(['is_superuser' => true])->save();

        $listed = $this->names($this->client);

        $this->assertContains('Libre', $listed);
        foreach (['Ya es miembro', 'Desactivada', 'Interna de la plataforma', 'Superusuaria'] as $hidden) {
            $this->assertNotContains($hidden, $listed, "{$hidden} must not be offered");
        }
        // Searching for a hidden one reveals nothing about it.
        $this->assertSame([], $this->names($this->client, 'Interna de la plataforma'));
    }

    public function test_the_list_is_per_workspace_a_member_of_one_is_still_offered_to_another(): void
    {
        $this->superuser();
        $person = User::factory()->create(['name' => 'Compartida']);
        $this->other->users()->attach($person->id, ['role' => 'cliente']);

        $this->assertContains('Compartida', $this->names($this->client));
        $this->assertNotContains('Compartida', $this->names($this->other));
    }

    public function test_the_administrative_workspace_may_receive_internal_accounts(): void
    {
        $this->superuser();
        $staff = User::factory()->create(['name' => 'Interna']);
        $this->other->users()->attach($staff->id, ['role' => 'cliente']);

        $this->assertContains('Interna', $this->names($this->admin));
    }

    public function test_only_a_superuser_who_administers_the_workspace_can_ask(): void
    {
        // An administrator of a client Workspace cannot add existing accounts, so there is nothing to list for them.
        $admin = User::factory()->create();
        $this->client->users()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin)->withSession(['workspace_id' => $this->client->id]);
        User::factory()->create(['name' => 'Cuenta cualquiera']);

        $this->getJson("/workspaces/{$this->client->id}/eligible-users")->assertForbidden();
        $this->getJson("/workspaces/{$this->other->id}/eligible-users")->assertForbidden();

        auth()->logout();
        $this->getJson("/workspaces/{$this->client->id}/eligible-users")->assertUnauthorized();
    }

    public function test_the_response_carries_no_more_than_the_name_and_email_and_is_not_cached(): void
    {
        $this->superuser();
        User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

        $response = $this->getJson("/workspaces/{$this->client->id}/eligible-users")->assertOk();

        $this->assertSame(['id', 'name', 'email'], array_keys($response->json('data.0')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_adding_by_the_selected_id_works_and_the_server_checks_again(): void
    {
        $this->superuser();
        $free = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $staff = User::factory()->create();
        $this->admin->users()->attach($staff->id, ['role' => 'soporte']);
        $member = User::factory()->create();
        $this->client->users()->attach($member->id, ['role' => 'cliente']);

        $this->post("/workspaces/{$this->client->id}/members", ['user_id' => $inactive->id, 'role' => 'cliente'])->assertSessionHasErrors('user_id');
        $this->post("/workspaces/{$this->client->id}/members", ['user_id' => $staff->id, 'role' => 'cliente'])->assertSessionHasErrors('user_id');
        $this->post("/workspaces/{$this->client->id}/members", ['user_id' => $member->id, 'role' => 'cliente'])->assertSessionHasErrors('user_id');
        $this->post("/workspaces/{$this->client->id}/members", ['user_id' => 999999, 'role' => 'cliente'])->assertSessionHasErrors('user_id');
        $this->assertFalse($this->client->users()->whereKey($free->id)->exists());

        $this->post("/workspaces/{$this->client->id}/members", ['user_id' => $free->id, 'role' => 'cliente'])->assertSessionHasNoErrors();
        $this->assertTrue($this->client->users()->whereKey($free->id)->exists());
        // The old way (a correo) still works and follows the same rules.
        $again = User::factory()->create();
        $this->post("/workspaces/{$this->client->id}/members", ['email' => $again->email, 'role' => 'cliente'])->assertSessionHasNoErrors();
        $this->post("/workspaces/{$this->client->id}/members", ['role' => 'cliente'])->assertSessionHasErrors(['user_id', 'email']);
    }

    public function test_what_the_selector_offers_is_exactly_what_the_server_accepts(): void
    {
        $this->superuser();
        $accounts = collect([
            User::factory()->create(),
            User::factory()->create(['is_active' => false]),
            User::factory()->create(),
            User::factory()->create(),
        ]);
        $this->admin->users()->attach($accounts[2]->id, ['role' => 'soporte']);
        $this->client->users()->attach($accounts[3]->id, ['role' => 'cliente']);
        $offered = collect($this->getJson("/workspaces/{$this->client->id}/eligible-users")->json('data'))->pluck('id');

        foreach ($accounts as $account) {
            $this->post("/workspaces/{$this->client->id}/members", ['user_id' => $account->id, 'role' => 'cliente']);
            $accepted = $this->client->users()->whereKey($account->id)->exists() && ! $accounts[3]->is($account);

            $this->assertSame($offered->contains($account->id), $accepted, "account {$account->id}: offered and accepted must agree");
        }
    }
}
