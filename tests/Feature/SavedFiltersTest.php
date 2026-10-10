<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SavedFilter;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Search filters a user saves per module: their own, in their Workspace, validated against that Workspace's data. */
class SavedFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function otherWorkspace(string $code = 'OTHER_WS'): Workspace
    {
        return Workspace::create(['organization_id' => Organization::firstOrFail()->id, 'code' => $code, 'name' => "Otro {$code}"]);
    }

    private function store(string $scope, array $data)
    {
        return $this->post("/saved-filters/{$scope}", $data);
    }

    private function auditCriteria(array $overrides = []): array
    {
        return $overrides + ['resource' => 'user', 'action' => 'updated', 'from' => '2026-10-01', 'to' => '2026-10-09'];
    }

    // --- saving and reading ------------------------------------------------------------------------------------

    public function test_a_filter_is_saved_with_a_name_and_comes_back_in_the_page_props(): void
    {
        $user = $this->actAs();

        $this->store('audit', ['name' => '  Cambios de usuarios ', 'criteria' => $this->auditCriteria(['search' => ''])])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $row = SavedFilter::firstOrFail();
        $this->assertSame('Cambios de usuarios', $row->name);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(Workspace::first()->id, $row->workspace_id);
        $this->assertSame(['resource' => 'user', 'action' => 'updated', 'from' => '2026-10-01', 'to' => '2026-10-09'], $row->criteria);

        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('savedFilters', 1)->where('savedFilters.0.name', 'Cambios de usuarios')->where('savedFilters.0.criteria.resource', 'user'));
    }

    public function test_the_page_lists_an_empty_array_when_nothing_is_saved(): void
    {
        $this->actAs();

        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page->where('savedFilters', []));
        $this->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page->where('savedFilters', []));
    }

    public function test_conversation_filters_are_saved_for_the_shared_inbox_only(): void
    {
        $workspace = $this->actAsWorkspace();
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);

        $this->store('conversations', ['name' => 'Pendientes de web', 'criteria' => ['status' => 'pending', 'channel' => 'web', 'chatbot' => $bot->id, 'q' => 'ana']])->assertSessionHasNoErrors();

        $this->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page->has('savedFilters', 1)->where('savedFilters.0.criteria.status', 'pending'));
        $this->get("/chatbots/{$bot->id}/channels/whatsapp/conversations")->assertInertia(fn (AssertableInertia $page) => $page->where('savedFilters', null));
    }

    private function actAsWorkspace(): Workspace
    {
        $this->actAs();

        return Workspace::first();
    }

    // --- validation --------------------------------------------------------------------------------------------

    public function test_incomplete_or_incompatible_filters_are_refused(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $stranger = User::factory()->create();
        $other->users()->attach($stranger->id, ['role' => 'admin']);
        $foreignBot = $other->chatbots()->create(['name' => 'Ajeno']);

        $invalid = [
            ['name' => '', 'criteria' => $this->auditCriteria()],
            ['name' => str_repeat('a', 61), 'criteria' => $this->auditCriteria()],
            ['name' => 'Vacío', 'criteria' => []],
            ['name' => 'Solo vacíos', 'criteria' => ['search' => '', 'user' => null]],
            ['name' => 'Módulo falso', 'criteria' => ['resource' => 'nope']],
            ['name' => 'Acción falsa', 'criteria' => ['action' => 'exploded']],
            ['name' => 'Fechas', 'criteria' => ['from' => '2026-10-09', 'to' => '2026-10-01']],
            ['name' => 'Fecha rara', 'criteria' => ['from' => '09/10/2026']],
            ['name' => 'Usuario de otro Workspace', 'criteria' => ['user' => $stranger->id]],
            ['name' => 'Criterio ajeno', 'criteria' => ['resource' => 'user', 'chatbot' => 1]],
            ['name' => 'Sin criterios'],
        ];

        foreach ($invalid as $data) {
            $this->store('audit', $data)->assertSessionHasErrors();
        }

        $this->store('conversations', ['name' => 'Asistente ajeno', 'criteria' => ['chatbot' => $foreignBot->id]])->assertSessionHasErrors('criteria.chatbot');
        $this->store('conversations', ['name' => 'Estado falso', 'criteria' => ['status' => 'frozen']])->assertSessionHasErrors('criteria.status');
        $this->store('conversations', ['name' => 'Canal sin conversaciones', 'criteria' => ['channel' => 'instagram']])->assertSessionHasErrors('criteria.channel');

        $this->assertSame(0, SavedFilter::count());
    }

    public function test_names_are_unique_per_user_and_module_but_may_repeat_elsewhere(): void
    {
        $this->actAs();

        $this->store('audit', ['name' => 'Mi filtro', 'criteria' => $this->auditCriteria()])->assertSessionHasNoErrors();
        $this->store('audit', ['name' => 'Mi filtro', 'criteria' => $this->auditCriteria(['action' => 'created'])])->assertSessionHasErrors(['name' => 'Ya tienes un filtro guardado con ese nombre.']);
        $this->store('conversations', ['name' => 'Mi filtro', 'criteria' => ['status' => 'ai']])->assertSessionHasNoErrors();

        $this->assertSame(2, SavedFilter::count());
    }

    public function test_a_user_cannot_keep_more_than_the_limit_per_module(): void
    {
        $this->actAs();
        config(['saved_filters.max_per_scope' => 2]);

        $this->store('audit', ['name' => 'Uno', 'criteria' => $this->auditCriteria()])->assertSessionHasNoErrors();
        $this->store('audit', ['name' => 'Dos', 'criteria' => $this->auditCriteria(['action' => 'created'])])->assertSessionHasNoErrors();
        $this->store('audit', ['name' => 'Tres', 'criteria' => $this->auditCriteria(['action' => 'deleted'])])->assertSessionHasErrors('name');

        $this->assertSame(2, SavedFilter::count());
    }

    public function test_an_unknown_module_cannot_be_used(): void
    {
        $this->actAs();

        $this->store('users', ['name' => 'X', 'criteria' => ['search' => 'a']])->assertForbidden();
        $this->assertSame(0, SavedFilter::count());
    }

    // --- update, rename, delete --------------------------------------------------------------------------------

    public function test_a_filter_is_renamed_and_its_criteria_updated_only_explicitly(): void
    {
        $this->actAs();
        $this->store('audit', ['name' => 'Original', 'criteria' => $this->auditCriteria()]);
        $filter = SavedFilter::firstOrFail();
        $this->store('audit', ['name' => 'Otro', 'criteria' => $this->auditCriteria(['action' => 'created'])]);

        $this->put("/saved-filters/audit/{$filter->id}", ['name' => 'Renombrado'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $filter->refresh();
        $this->assertSame('Renombrado', $filter->name);
        $this->assertSame('updated', $filter->criteria['action']);

        $this->put("/saved-filters/audit/{$filter->id}", ['criteria' => ['action' => 'deleted']])->assertSessionHasNoErrors();
        $filter->refresh();
        $this->assertSame('Renombrado', $filter->name);
        $this->assertSame(['action' => 'deleted'], $filter->criteria);

        $this->put("/saved-filters/audit/{$filter->id}", ['name' => 'Otro'])->assertSessionHasErrors('name');
        $this->put("/saved-filters/audit/{$filter->id}", ['name' => 'Renombrado', 'criteria' => ['action' => 'created']])->assertSessionHasNoErrors();
        $this->put("/saved-filters/audit/{$filter->id}", ['criteria' => []])->assertSessionHasErrors('criteria');
        $this->assertSame(['action' => 'created'], $filter->refresh()->criteria);
    }

    public function test_a_filter_is_deleted(): void
    {
        $this->actAs();
        $this->store('audit', ['name' => 'Borrar', 'criteria' => $this->auditCriteria()]);
        $filter = SavedFilter::firstOrFail();

        $this->delete("/saved-filters/audit/{$filter->id}")->assertSessionHas('success');

        $this->assertSame(0, SavedFilter::count());
    }

    // --- isolation ---------------------------------------------------------------------------------------------

    public function test_nobody_reads_changes_or_deletes_filters_of_another_user_or_workspace(): void
    {
        $owner = $this->actAs();
        $this->store('audit', ['name' => 'Privado', 'criteria' => $this->auditCriteria()]);
        $filter = SavedFilter::firstOrFail();

        // Another user of the same Workspace.
        $colleague = User::factory()->create();
        Workspace::first()->users()->attach($colleague->id, ['role' => 'admin']);
        $this->actingAs($colleague);
        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page->where('savedFilters', []));
        $this->put("/saved-filters/audit/{$filter->id}", ['name' => 'Robado'])->assertNotFound();
        $this->put("/saved-filters/audit/{$filter->id}", ['criteria' => ['action' => 'created']])->assertNotFound();
        $this->delete("/saved-filters/audit/{$filter->id}")->assertNotFound();

        // The same user, but working in another Workspace.
        $other = $this->otherWorkspace();
        $other->users()->attach($owner->id, ['role' => 'admin']);
        $this->actingAs($owner)->withSession(['workspace_id' => $other->id]);
        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page->where('savedFilters', []));
        $this->put("/saved-filters/audit/{$filter->id}", ['name' => 'Cruce'])->assertNotFound();
        $this->delete("/saved-filters/audit/{$filter->id}")->assertNotFound();

        // The filter of another module is not reachable through this module's URL either.
        $this->actingAs($owner)->withSession(['workspace_id' => Workspace::first()->id]);
        $this->delete("/saved-filters/conversations/{$filter->id}")->assertNotFound();

        $filter->refresh();
        $this->assertSame('Privado', $filter->name);
        $this->assertSame(1, SavedFilter::count());
    }

    public function test_the_workspace_and_owner_never_come_from_the_request(): void
    {
        $user = $this->actAs();
        $other = $this->otherWorkspace();
        $stranger = User::factory()->create();

        $this->store('audit', ['name' => 'Mío', 'criteria' => $this->auditCriteria(), 'workspace_id' => $other->id, 'user_id' => $stranger->id])->assertSessionHasNoErrors();

        $row = SavedFilter::firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(Workspace::first()->id, $row->workspace_id);
    }

    public function test_the_module_permission_is_required(): void
    {
        $user = $this->actAs('agente');

        // `agente` reads conversations but cannot open Auditoría (manage-settings).
        $this->store('audit', ['name' => 'No', 'criteria' => $this->auditCriteria()])->assertForbidden();
        $this->store('conversations', ['name' => 'Sí', 'criteria' => ['status' => 'pending']])->assertSessionHasNoErrors();
        $this->assertSame(1, SavedFilter::where('user_id', $user->id)->count());

        $this->actAs('cliente');
        $this->store('conversations', ['name' => 'No', 'criteria' => ['status' => 'pending']])->assertForbidden();
    }

    public function test_guests_cannot_use_saved_filters(): void
    {
        $this->post('/saved-filters/audit', ['name' => 'x', 'criteria' => ['action' => 'created']])->assertRedirect();
        $this->assertSame(0, SavedFilter::count());
    }
}
