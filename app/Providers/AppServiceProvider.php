<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
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

        // `composer run dev` (php artisan dev) runs on the host. Horizon (needs pcntl + Redis) and Reverb
        // (needs Redis) are only available in the Docker stack, so keep them out of the host dev processes.
        // Replaces Horizon's own `except('queue')`, so the plain queue listener runs again on the host.
        DevCommands::except('horizon', 'reverb');
    }
}
