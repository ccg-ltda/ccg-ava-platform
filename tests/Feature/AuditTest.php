<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Auditoría: who changed what, where and when; only relevant differences; never secrets; strictly per Workspace. */
class AuditTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_live_SUPER-SECRET-VALUE-123';

    private const PASSWORD = 'Pa55word-Secreta-9876!';

    private function actAs(string $role = 'admin', bool $superuser = false, string $name = 'Ana Admin'): User
    {
        $user = User::factory()->create(['name' => $name]);

        if ($superuser) {
            $user->forceFill(['is_superuser' => true])->save();
        }

        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function otherWorkspace(string $code = 'OTHER_WS'): Workspace
    {
        return Workspace::withoutEvents(fn () => Workspace::create(['organization_id' => Organization::firstOrFail()->id, 'code' => $code, 'name' => "Otro {$code}"]));
    }

    private function log(array $overrides = []): AuditLog
    {
        $workspace = $overrides['workspace'] ?? Workspace::first();
        unset($overrides['workspace']);

        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $log = AuditLog::create($overrides + [
            'workspace_id' => $workspace->id, 'workspace_code' => $workspace->code, 'workspace_name' => $workspace->name,
            'user_id' => null, 'user_name' => 'Persona', 'user_email' => 'persona@example.com',
            'action' => 'updated', 'resource_type' => 'user', 'resource_id' => 1, 'resource_label' => 'Registro',
            'description' => 'Modificó el usuario Registro',
            'changes' => [['field' => 'Nombre', 'before' => 'A', 'after' => 'B']], 'ip_address' => '203.0.113.5',
        ]);

        // `created_at` is not mass assignable (rows are only ever written by the logger).
        if ($createdAt) {
            $log->forceFill(['created_at' => $createdAt])->save();
        }

        return $log;
    }

    /** All the text drawn in a PDF (the streams are compressed and written in WinAnsi). */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $streams);
        $text = '';

        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream) ?: $stream;
            preg_match_all('/\((.*?)(?<!\\\\)\) Tj/s', $content, $strings);
            $text .= implode("\n", array_map(fn ($s) => iconv('Windows-1252', 'UTF-8', stripcslashes($s)), $strings[1]))."\n";
        }

        return $text;
    }

    // --- recording ------------------------------------------------------------------------------------------

    public function test_creating_a_user_records_who_where_what_and_the_ip_without_the_password(): void
    {
        $actor = $this->actAs();
        $workspace = Workspace::first();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeader('User-Agent', 'Mozilla/5.0 (X11) Chrome/999')
            ->post('/users', ['workspace_id' => $workspace->id, 'name' => 'María López', 'email' => 'maria@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'role' => 'cliente'])
            ->assertSessionHasNoErrors()->assertSessionMissing('errors');

        $log = AuditLog::where('resource_type', 'user')->where('action', 'created')->firstOrFail();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame(['Ana Admin', $actor->email], [$log->user_name, $log->user_email]);
        $this->assertSame([$workspace->id, 'TEST_WS', 'Test Workspace'], [$log->workspace_id, $log->workspace_code, $log->workspace_name]);
        $this->assertSame('198.51.100.7', $log->ip_address);
        $this->assertSame('María López', $log->resource_label);
        $this->assertSame('Creó el usuario María López', $log->description);
        $this->assertContains(['field' => 'Nombre', 'before' => null, 'after' => 'María López'], $log->changes);
        $this->assertContains(['field' => 'Contraseña', 'before' => null, 'after' => 'Valor oculto'], $log->changes);

        $membership = AuditLog::where('resource_type', 'membership')->where('action', 'created')->firstOrFail();
        $this->assertContains(['field' => 'Rol', 'before' => null, 'after' => 'cliente'], $membership->changes);

        $dump = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString(self::PASSWORD, $dump);
        $this->assertStringNotContainsString('Mozilla', $dump);
        $this->assertStringNotContainsString('Chrome', $dump);
    }

    public function test_the_table_has_no_browser_columns(): void
    {
        $columns = Schema::getColumnListing('audit_logs');

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/agent|browser|session|cookie|token|password|secret/i', $column);
        }

        $this->assertContains('ip_address', $columns);
    }

    public function test_editing_a_user_records_only_the_fields_that_changed(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $user = User::factory()->create(['name' => 'María López', 'email' => 'maria@example.com']);
        $workspace->users()->attach($user->id, ['role' => 'cliente']);

        $this->put("/users/{$user->id}", ['name' => 'María Rodríguez', 'email' => 'maria@example.com', 'role' => 'admin'])->assertSessionHasNoErrors()->assertSessionMissing('errors');

        $log = AuditLog::where('resource_type', 'user')->where('action', 'updated')->firstOrFail();
        $this->assertSame([['field' => 'Nombre', 'before' => 'María López', 'after' => 'María Rodríguez']], $log->changes);
        $this->assertSame('Modificó el usuario María Rodríguez', $log->description);

        $role = AuditLog::where('resource_type', 'membership')->where('action', 'updated')->firstOrFail();
        $this->assertSame([['field' => 'Rol', 'before' => 'cliente', 'after' => 'admin']], $role->changes);

        // Saving without changing anything records nothing.
        AuditLog::query()->delete();
        $this->put("/users/{$user->id}", ['name' => 'María Rodríguez', 'email' => 'maria@example.com', 'role' => 'admin'])->assertSessionHasNoErrors()->assertSessionMissing('errors');
        $this->assertSame(0, AuditLog::count());
    }

    public function test_deactivating_and_activating_are_audited_as_changes(): void
    {
        $this->actAs();
        $user = User::factory()->create(['name' => 'Luis']);
        Workspace::first()->users()->attach($user->id, ['role' => 'cliente']);

        $this->post("/users/{$user->id}/deactivate");
        $this->post("/users/{$user->id}/activate");

        $logs = AuditLog::where('resource_type', 'user')->orderBy('id')->get();
        $this->assertSame([['field' => 'Activo', 'before' => 'Sí', 'after' => 'No']], $logs[0]->changes);
        $this->assertSame([['field' => 'Activo', 'before' => 'No', 'after' => 'Sí']], $logs[1]->changes);
    }

    public function test_removing_a_member_is_audited_as_deleted_in_that_workspace(): void
    {
        $actor = $this->actAs('admin', superuser: true);
        $other = $this->otherWorkspace();
        $member = User::factory()->create(['name' => 'Pedro']);
        $other->users()->attach($member->id, ['role' => 'cliente']);

        $this->delete("/workspaces/{$other->id}/members/{$member->id}")->assertSessionHasNoErrors()->assertSessionMissing('errors');

        $log = AuditLog::where('resource_type', 'membership')->firstOrFail();
        $this->assertSame('deleted', $log->action);
        $this->assertSame($other->id, $log->workspace_id);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertContains(['field' => 'Rol', 'before' => 'cliente', 'after' => null], $log->changes);
    }

    public function test_roles_record_the_permissions_added_and_removed(): void
    {
        $this->actAs('admin', superuser: true);

        $this->post('/roles', ['name' => 'auditor', 'permissions' => ['view-dashboard']])->assertSessionHasNoErrors()->assertSessionMissing('errors');
        $role = Role::findByName('auditor', 'web');
        $this->put("/roles/{$role->id}", ['permissions' => ['view-users']])->assertSessionHasNoErrors()->assertSessionMissing('errors');

        $this->assertContains(['field' => 'Permisos', 'before' => null, 'after' => 'view-dashboard'], AuditLog::where('action', 'created')->where('resource_type', 'role')->firstOrFail()->changes);
        $this->assertSame([
            ['field' => 'Permisos añadidos', 'before' => null, 'after' => 'view-users'],
            ['field' => 'Permisos quitados', 'before' => 'view-dashboard', 'after' => null],
        ], AuditLog::where('action', 'updated')->where('resource_type', 'role')->firstOrFail()->changes);
    }

    public function test_workspaces_and_organizations_are_audited(): void
    {
        $this->actAs('admin', superuser: true);
        $organization = Organization::first();

        $this->put("/organizations/{$organization->id}", ['name' => 'Nueva Org'])->assertSessionHasNoErrors()->assertSessionMissing('errors');
        $this->post('/workspaces', ['organization_id' => $organization->id, 'code' => 'nuevo_ws', 'name' => 'Nuevo WS'])->assertSessionHasNoErrors()->assertSessionMissing('errors');
        $created = Workspace::where('code', 'NUEVO_WS')->firstOrFail();
        $this->post("/workspaces/{$created->id}/deactivate");

        $this->assertSame([['field' => 'Nombre', 'before' => 'Test Org', 'after' => 'Nueva Org']], AuditLog::where('resource_type', 'organization')->firstOrFail()->changes);

        $workspace = AuditLog::where('resource_type', 'workspace')->where('action', 'created')->firstOrFail();
        $this->assertSame($created->id, $workspace->workspace_id);
        $this->assertContains(['field' => 'Código', 'before' => null, 'after' => 'NUEVO_WS'], $workspace->changes);
        $this->assertContains(['field' => 'Organización', 'before' => null, 'after' => 'Nueva Org'], $workspace->changes);
        $this->assertSame([['field' => 'Activo', 'before' => 'Sí', 'after' => 'No']], AuditLog::where('resource_type', 'workspace')->where('action', 'updated')->firstOrFail()->changes);
    }

    public function test_workspace_settings_changes_are_audited(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $workspace->settings()->create(array_merge(WorkspaceSetting::defaults(), ['primary_color' => '#1d4ed8']));
        AuditLog::query()->delete();

        $this->get('/dashboard'); // a signed-in request in the Workspace, as when the settings page saves
        $settings = $workspace->settings()->first();
        $settings->update(['primary_color' => '#0f766e', 'currency' => 'USD']);

        $log = AuditLog::where('resource_type', 'settings')->firstOrFail();
        $this->assertEqualsCanonicalizing([
            ['field' => 'Color principal', 'before' => '#1d4ed8', 'after' => '#0f766e'],
            ['field' => 'Moneda', 'before' => config('workspace.defaults.currency'), 'after' => 'USD'],
        ], $log->changes);
        $this->assertSame($workspace->id, $log->workspace_id);
    }

    public function test_integration_events_never_contain_credentials_or_the_body(): void
    {
        $this->actAs();
        Http::preventStrayRequests();
        $base = ['name' => 'API', 'provider' => 'P', 'type' => 'http', 'is_active' => true, 'base_url' => 'https://api.example.com', 'endpoint' => '', 'method' => 'POST', 'timeout' => 10, 'auth_type' => 'bearer', 'auth_secret' => self::SECRET, 'headers' => [['name' => 'X-Token', 'value' => self::SECRET.'-h', 'secret' => true]], 'query' => [], 'body' => '{"password":"'.self::SECRET.'-b"}'];

        $this->post('/integrations', $base)->assertSessionHasNoErrors()->assertSessionMissing('errors');
        $integration = Integration::firstOrFail();
        $this->put("/integrations/{$integration->id}", array_merge($base, ['auth_secret' => self::SECRET.'-new', 'body' => '{"x":1}', 'method' => 'PUT']))->assertSessionHasNoErrors()->assertSessionMissing('errors');

        $created = AuditLog::where('resource_type', 'integration')->where('action', 'created')->firstOrFail();
        $this->assertContains(['field' => 'Credenciales', 'before' => null, 'after' => 'Valor oculto'], $created->changes);
        $this->assertContains(['field' => 'Body', 'before' => null, 'after' => 'Valor oculto'], $created->changes);
        $this->assertContains(['field' => 'Header X-Token', 'before' => null, 'after' => 'Secreto'], $created->changes);

        $updated = AuditLog::where('resource_type', 'integration')->where('action', 'updated')->firstOrFail();
        $this->assertContains(['field' => 'Credenciales', 'before' => 'Valor oculto', 'after' => 'Valor oculto (modificado)'], $updated->changes);
        $this->assertContains(['field' => 'Método', 'before' => 'POST', 'after' => 'PUT'], $updated->changes);
        $this->assertSame($integration->workspace_id, $updated->workspace_id);

        $this->assertStringNotContainsString('SUPER-SECRET', json_encode(AuditLog::all()->toArray()));

        // A connection test only updates the last-test summary: not audited.
        $count = AuditLog::count();
        $integration->forceFill(['last_test_ok' => true, 'last_tested_at' => now()])->save();
        $this->assertSame($count, AuditLog::count());
    }

    public function test_the_actor_keeps_the_name_they_had_when_acting(): void
    {
        $actor = $this->actAs();
        $user = User::factory()->create();
        Workspace::first()->users()->attach($user->id, ['role' => 'cliente']);
        $this->post("/users/{$user->id}/deactivate");

        $actor->forceFill(['name' => 'Ana Renombrada'])->save();
        // Newest first: the rename itself, then the deactivation, still under the name she had then.
        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.user.name', 'Ana Renombrada')->where('events.data.1.user.name', 'Ana Admin')->where('events.data.1.record', $user->name));
        $this->assertSame($actor->id, AuditLog::firstOrFail()->user_id);

        // The history survives the removal of the account.
        User::withoutEvents(fn () => $actor->delete());
        $this->assertSame('Ana Admin', AuditLog::firstOrFail()->user_name);
        $this->assertNull(AuditLog::firstOrFail()->user_id);
    }

    public function test_nothing_is_recorded_outside_a_signed_in_workspace_request(): void
    {
        User::factory()->create();
        $this->assertSame(0, AuditLog::count());

        $this->actAs();
        $before = AuditLog::count();
        $this->get('/users')->assertOk();
        $this->get('/audit')->assertOk();
        $this->assertSame($before, AuditLog::count());
    }

    // --- page, scope and permissions ---------------------------------------------------------------------------

    public function test_the_page_lists_only_the_active_workspaces_events_newest_first(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $this->log(['resource_label' => 'Vieja', 'created_at' => now()->subDay()]);
        $this->log(['resource_label' => 'Nueva', 'created_at' => now()]);
        $this->log(['resource_label' => 'Ajena', 'workspace' => $other]);

        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Audit/Index')->has('events.data', 2)->where('events.data.0.record', 'Nueva')->where('events.data.1.record', 'Vieja')
            ->where('summary.total', 2)->where('options.canChoose', false)
            ->where('events.data.0.ip', '203.0.113.5')->has('events.data.0.date')->has('events.data.0.time')->has('events.data.0.changes', 1));
    }

    public function test_a_regular_admin_cannot_widen_the_scope_to_another_workspace(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $this->log(['resource_label' => 'Mía']);
        $this->log(['resource_label' => 'Ajena', 'workspace' => $other]);

        foreach (['all', (string) $other->id] as $workspace) {
            $this->get('/audit?workspace='.$workspace)->assertForbidden();
            $this->get('/audit/export?workspace='.$workspace)->assertForbidden();
        }

        // The users offered in the filter are only people who acted in this Workspace.
        $outsider = User::withoutEvents(fn () => User::factory()->create(['name' => 'Fuera']));
        $this->log(['user_id' => $outsider->id, 'user_name' => 'Fuera', 'workspace' => $other]);
        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)->where('options.canChoose', false)->where('options.users', fn ($users) => ! collect($users)->contains('label', 'Fuera')));
    }

    public function test_a_superuser_outside_the_administrative_workspace_cannot_widen_the_scope(): void
    {
        $this->actAs('admin', superuser: true);
        $other = $this->otherWorkspace();

        $this->get('/audit?workspace=all')->assertForbidden();
        $this->get("/audit?workspace={$other->id}")->assertForbidden();
        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page->where('options.canChoose', false));
    }

    public function test_a_superuser_in_the_administrative_workspace_may_choose_one_workspace_or_all(): void
    {
        $this->actAs('admin', superuser: true);
        config(['workspace.admin_code' => 'TEST_WS']);
        $other = $this->otherWorkspace();
        $this->log(['resource_label' => 'Mía']);
        $this->log(['resource_label' => 'Ajena', 'workspace' => $other]);

        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 1)->where('options.canChoose', true)->where('scope.mode', 'active'));
        $this->get('/audit?workspace=all')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 2)->where('scope.mode', 'all')->where('events.data.0.workspace.name', fn ($name) => in_array($name, ['Test Workspace', $other->name], true)));
        $this->get("/audit?workspace={$other->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)->where('events.data.0.record', 'Ajena')->where('events.data.0.workspace.code', 'OTHER_WS')->where('scope.workspace.code', 'OTHER_WS'));
        $this->get('/audit?workspace=999999')->assertNotFound();
    }

    public function test_only_users_with_manage_settings_can_see_or_export_the_audit(): void
    {
        $this->actAs('cliente');
        $this->get('/audit')->assertForbidden();
        $this->get('/audit/export')->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.permissions', fn ($permissions) => ! in_array('manage-settings', collect($permissions)->all(), true)));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/audit')->assertRedirect();
        $this->get('/audit/export')->assertRedirect();
    }

    public function test_the_menu_entry_follows_the_permission(): void
    {
        $this->actAs();
        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.permissions', fn ($permissions) => in_array('manage-settings', collect($permissions)->all(), true)));
        $this->assertStringContainsString("permission: 'manage-settings'", file_get_contents(resource_path('js/config/navigation.js')));
    }

    public function test_the_audit_has_no_routes_to_change_or_read_one_event_by_id(): void
    {
        $this->actAs();
        $log = $this->log();

        $this->get("/audit/{$log->id}")->assertNotFound();
        $this->put("/audit/{$log->id}")->assertNotFound();
        $this->delete("/audit/{$log->id}")->assertNotFound();
        $this->post('/audit')->assertStatus(405);
        $this->assertSame(1, AuditLog::count());
    }

    // --- filters and paging --------------------------------------------------------------------------------

    public function test_filters_narrow_the_list_and_the_summary(): void
    {
        $this->actAs();
        $ana = User::factory()->create(['name' => 'Ana Filtro']);
        $this->log(['action' => 'created', 'resource_type' => 'user', 'resource_label' => 'Alfa', 'user_id' => $ana->id, 'user_name' => 'Ana Filtro', 'created_at' => '2026-03-10 12:00:00']);
        $this->log(['action' => 'updated', 'resource_type' => 'integration', 'resource_label' => 'Beta API', 'created_at' => '2026-03-15 12:00:00', 'ip_address' => '10.9.8.7']);
        $this->log(['action' => 'deleted', 'resource_type' => 'membership', 'resource_label' => 'Gamma', 'created_at' => '2026-04-01 12:00:00']);

        $records = fn (string $query) => $this->get('/audit?'.$query)->viewData('page')['props']['events']['data'];
        $names = fn (string $query) => collect($records($query))->pluck('record')->sort()->values()->all();

        $this->assertSame(['Alfa'], $names('action=created'));
        $this->assertSame(['Beta API'], $names('resource=integration'));
        $this->assertSame(['Alfa'], $names('user='.$ana->id));
        $this->assertSame(['Beta API'], $names('search=beta'));
        $this->assertSame(['Beta API'], $names('search=10.9.8'));
        $this->assertSame(['Alfa'], $names('search=ana+filtro'));
        $this->assertSame(['Alfa', 'Beta API'], $names('from=2026-03-01&to=2026-03-31'));
        $this->assertSame(['Gamma'], $names('from=2026-03-20'));

        $this->get('/audit?from=2026-03-01&to=2026-03-31&action=updated')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary', ['total' => 2, 'created' => 1, 'updated' => 1, 'deleted' => 0, 'failed' => 0, 'automatic' => 0])->has('events.data', 1));
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->actAs();
        $this->log(['resource_label' => 'Alfa']);

        $this->get('/audit?search=%25')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 0));
        $this->get('/audit?search=_lfa')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 0));
    }

    public function test_the_date_range_follows_the_workspace_timezone(): void
    {
        $this->actAs();
        Workspace::first()->settings()->create(array_merge(WorkspaceSetting::defaults(), ['timezone' => 'America/Bogota']));
        // 2026-03-11 02:00 UTC is still March 10 in Bogotá (UTC-5).
        $this->log(['resource_label' => 'Noche', 'created_at' => '2026-03-11 02:00:00']);

        $this->get('/audit?from=2026-03-10&to=2026-03-10')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 1)->where('events.data.0.date', fn ($date) => str_contains($date, '10')));
        $this->get('/audit?from=2026-03-11&to=2026-03-11')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 0));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actAs();

        $this->get('/audit?action=hack')->assertSessionHasErrors('action');
        $this->get('/audit?resource=nope')->assertSessionHasErrors('resource');
        $this->get('/audit?from=2026-03-10&to=2026-03-01')->assertSessionHasErrors('to');
        $this->get('/audit?from=ayer')->assertSessionHasErrors('from');
    }

    public function test_the_list_is_paginated_with_the_offered_sizes(): void
    {
        $this->actAs();

        foreach (range(1, 25) as $i) {
            $this->log(['resource_label' => sprintf('Evento %02d', $i), 'created_at' => now()->subMinutes(30 - $i)]);
        }

        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 10)->where('events.meta.total', 25)->where('events.meta.last_page', 3)->where('events.data.0.record', 'Evento 25'));
        $this->get('/audit?page=3')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 5)->where('events.data.4.record', 'Evento 01'));
        $this->get('/audit?per_page=20')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 20)->where('events.meta.last_page', 2));
        $this->get('/audit?per_page=7')->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 10));
    }

    public function test_the_page_does_not_run_a_query_per_event(): void
    {
        $this->actAs();

        foreach (range(1, 10) as $i) {
            $this->log(['resource_label' => "E{$i}"]);
        }

        $count = fn () => collect(DB::getQueryLog())->count();
        DB::enableQueryLog();
        $this->get('/audit')->assertOk();
        $few = $count();

        DB::flushQueryLog();
        foreach (range(11, 30) as $i) {
            $this->log(['resource_label' => "E{$i}"]);
        }
        DB::flushQueryLog();
        $this->get('/audit?per_page=30')->assertOk();

        $this->assertSame($few, $count());
    }

    // --- PDF -------------------------------------------------------------------------------------------------

    public function test_the_pdf_respects_the_filters_and_shows_only_relevant_changes(): void
    {
        $user = $this->actAs('admin', name: 'Ana Admin');
        $this->log(['action' => 'updated', 'resource_label' => 'María Rodríguez', 'user_name' => 'Juan Pérez', 'changes' => [['field' => 'Rol', 'before' => 'viewer', 'after' => 'admin']], 'created_at' => '2026-03-10 15:42:18']);
        $this->log(['action' => 'created', 'resource_label' => 'Integración Z', 'resource_type' => 'integration', 'changes' => [['field' => 'Credenciales', 'before' => null, 'after' => 'Valor oculto']]]);
        $this->log(['action' => 'deleted', 'resource_label' => 'Quitado', 'resource_type' => 'membership', 'changes' => [['field' => 'Rol', 'before' => 'cliente', 'after' => null]]]);

        $response = $this->get('/audit/export?action=updated&from=2026-03-01&to=2026-03-31')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="auditoria-test_ws-', $response->headers->get('Content-Disposition'));
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);

        $text = $this->pdfText($pdf);
        foreach (['Reporte de Auditoría', 'Test Workspace (TEST_WS)', 'Acción: Modificado', 'Ana Admin ('.$user->email.')', 'Juan Pérez', 'María Rodríguez', 'MODIFICADO', 'Rol', 'viewer', 'admin', '203.0.113.5', 'ANTES', 'DESPUÉS'] as $expected) {
            $this->assertStringContainsString($expected, $text, "PDF should contain [{$expected}]");
        }
        $this->assertStringContainsString('Del 01/03/2026 al 31/03/2026', $text);
        $this->assertStringNotContainsString('Integración Z', $text);
        $this->assertStringNotContainsString('Quitado', $text);
        $this->assertStringNotContainsString('{', $text, 'no JSON dump');
    }

    public function test_the_pdf_never_contains_credentials(): void
    {
        $this->actAs();
        Http::preventStrayRequests();
        $this->post('/integrations', ['name' => 'API', 'provider' => '', 'type' => 'http', 'is_active' => true, 'base_url' => 'https://api.example.com', 'endpoint' => '', 'method' => 'GET', 'timeout' => 10, 'auth_type' => 'bearer', 'auth_secret' => self::SECRET, 'headers' => [], 'query' => [], 'body' => ''])->assertSessionHasNoErrors()->assertSessionMissing('errors');

        $text = $this->pdfText($this->get('/audit/export')->assertOk()->getContent());

        $this->assertStringContainsString('Credenciales', $text);
        $this->assertStringContainsString('Valor oculto', $text);
        $this->assertStringNotContainsString('SUPER-SECRET', $text);
    }

    public function test_the_pdf_is_limited_to_the_workspace_and_to_the_maximum_of_events(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        config(['audit.pdf_max_events' => 3]);
        $this->log(['resource_label' => 'Ajena SECRETA', 'workspace' => $other]);

        foreach (range(1, 5) as $i) {
            $this->log(['resource_label' => "Evento {$i}", 'created_at' => now()->subMinutes(10 - $i)]);
        }

        $text = $this->pdfText($this->get('/audit/export')->assertOk()->getContent());

        $this->assertStringContainsString('Evento 5', $text);
        $this->assertStringContainsString('Evento 3', $text);
        $this->assertStringNotContainsString('Evento 2', $text);
        $this->assertStringNotContainsString('Ajena SECRETA', $text);
        $this->assertStringContainsString('3 de 5', $text);
    }

    public function test_the_pdf_handles_a_long_history_over_many_pages(): void
    {
        $this->actAs();

        foreach (range(1, 120) as $i) {
            $this->log(['resource_label' => "Registro {$i}", 'changes' => [['field' => 'Descripción', 'before' => str_repeat('antes ', 30), 'after' => str_repeat('después ', 30)]]]);
        }

        $pdf = $this->get('/audit/export')->assertOk()->getContent();

        preg_match('/\/Count (\d+)/', $pdf, $pages);
        $this->assertGreaterThan(5, (int) $pages[1]);
        $this->assertStringContainsString('Página '.$pages[1].' de '.$pages[1], $this->pdfText($pdf));
    }

    public function test_an_empty_pdf_says_there_are_no_events(): void
    {
        $this->actAs();

        $this->assertStringContainsString('No hay eventos', $this->pdfText($this->get('/audit/export')->assertOk()->getContent()));
    }
}
