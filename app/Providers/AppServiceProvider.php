<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // The role/permission catalog is global (shared by every Workspace), so only superusers manage it.
        Gate::define('manage-roles', fn (User $user) => $user->is_superuser);

        // Creating or editing a Workspace is an Organization-level operation: superusers only.
        Gate::define('manage-workspaces', fn (User $user) => $user->is_superuser);

        // `composer run dev` (php artisan dev) runs on the host. Horizon (needs pcntl + Redis) and Reverb
        // (needs Redis) are only available in the Docker stack, so keep them out of the host dev processes.
        // Replaces Horizon's own `except('queue')`, so the plain queue listener runs again on the host.
        DevCommands::except('horizon', 'reverb');
    }
}
