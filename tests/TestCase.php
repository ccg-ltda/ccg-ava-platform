<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * Act as a user who is a member of a Workspace and has it selected in the session,
     * i.e. the state the app expects after a successful login.
     */
    protected function actingAsWorkspaceMember(User $user, string $role = 'cliente'): static
    {
        Role::findOrCreate($role, 'web');

        $workspace = Workspace::first() ?? Workspace::create([
            'organization_id' => Organization::create(['name' => 'Test Org'])->id,
            'code' => 'TEST_WS',
            'name' => 'Test Workspace',
        ]);
        $workspace->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);

        return $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
    }
}
