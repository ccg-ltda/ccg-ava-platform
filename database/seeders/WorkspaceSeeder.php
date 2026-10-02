<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Base Organization/Workspaces. Requires RolesAndPermissionsSeeder to have run first.
     */
    public function run(): void
    {
        $organization = Organization::firstOrCreate(['name' => 'CCG']);

        $dev = Workspace::firstOrCreate(
            ['code' => 'DESARROLLO_DEV'],
            ['organization_id' => $organization->id, 'name' => 'Desarrollo'],
        );
        $prd = Workspace::firstOrCreate(
            ['code' => 'OPERACION_PRD'],
            ['organization_id' => $organization->id, 'name' => 'Operación'],
        );

        $admin = User::where('email', 'daniel@gmail.com')->first();
        if ($admin) {
            $admin->forceFill(['is_superuser' => true])->save();
            $dev->users()->syncWithoutDetaching([$admin->id => ['role' => 'admin']]);
            $prd->users()->syncWithoutDetaching([$admin->id => ['role' => 'admin']]);
        }

        foreach (['supervisor@ccg.com' => 'supervisor', 'cliente@ccg.com' => 'cliente'] as $email => $role) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $dev->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
            }
        }
    }
}
