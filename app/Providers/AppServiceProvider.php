<?php

namespace App\Providers;

use App\Chatbots\ChatbotExecutor;
use App\Chatbots\N8nExecutor;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // What runs a chatbot's workflow (today the platform's n8n); the rest of Ava only knows the contract.
        $this->app->bind(ChatbotExecutor::class, N8nExecutor::class);
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

        // The public widget is open to the whole internet: each visitor (IP) is limited per widget, since every message
        // reaches a workflow that may call a paid model.
        RateLimiter::for('widget-poll', fn (Request $request) => Limit::perMinute((int) config('chatbots.widget.poll_per_minute'))->by($request->ip().'|'.$request->route('key')));
        RateLimiter::for('widget-messages', fn (Request $request) => Limit::perMinute((int) config('chatbots.widget.messages_per_minute'))->by($request->ip().'|'.$request->route('key')));
        RateLimiter::for('widget-config', fn (Request $request) => Limit::perMinute((int) config('chatbots.widget.config_per_minute'))->by($request->ip().'|'.$request->route('key')));
        // The automation of a chatbot reads its configuration on every conversation.
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute((int) config('chatbots.agent_per_minute'))->by(sha1((string) $request->bearerToken()).'|'.$request->ip()));

        // `composer run dev` (php artisan dev) runs on the host. Horizon (needs pcntl + Redis) and Reverb
        // (needs Redis) are only available in the Docker stack, so keep them out of the host dev processes.
        // Replaces Horizon's own `except('queue')`, so the plain queue listener runs again on the host.
        DevCommands::except('horizon', 'reverb');
    }
}
