<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Outside local only the accounts listed in HORIZON_ALLOWED_EMAILS enter (empty list = nobody), and only while they are active.
        Gate::define('viewHorizon', function ($user = null) {
            if (! $user || ! $user->is_active) {
                return false;
            }

            return in_array(mb_strtolower((string) $user->email), array_map('mb_strtolower', (array) config('horizon.allowed_emails')), true);
        });
    }
}
