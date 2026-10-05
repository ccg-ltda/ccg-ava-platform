<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Keep the seeder re-runnable: only create the sample user once, and only where sample data belongs.
        if (app()->environment('local', 'testing') && ! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(AdminUserSeeder::class);
        $this->call(WorkspaceSeeder::class);
    }
}
