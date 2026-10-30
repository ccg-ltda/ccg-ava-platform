<?php

namespace App\Providers;

use App\Booking\AppointmentNotifier;
use App\Booking\Locations;
use App\Campaigns\CampaignDispatcher;
use App\Campaigns\UnavailableCampaignDispatcher;
use App\Chatbots\ChatbotExecutor;
use App\Chatbots\N8nExecutor;
use App\Models\User;
use App\Orders\CommerceSchema;
use App\Orders\OrderNotifier;
use App\Orders\UnavailableOrderNotifier;
use App\Payments\PaymentGateway;
use App\Payments\UnconfiguredPaymentGateway;
use App\Payments\WompiConfig;
use App\Payments\WompiPaymentGateway;
use App\Services\AbsoluteSession;
use App\Services\AgentToolTrace;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request: it remembers whether the database already has the structure of locations.
        $this->app->scoped(Locations::class);
        $this->app->scoped(AppointmentNotifier::class);
        $this->app->scoped(CommerceSchema::class);
        $this->app->scoped(AgentToolTrace::class);

        // The payment provider of the public sign-up: Wompi once its keys are complete and match the environment; otherwise the unconfigured gateway says so and charges nothing.
        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(WompiConfig::class)->usable() ? $app->make(WompiPaymentGateway::class) : new UnconfiguredPaymentGateway);

        // How an order event would reach the customer: no channel is connected yet, so the unavailable notifier says so and sends nothing.
        $this->app->bind(OrderNotifier::class, UnavailableOrderNotifier::class);

        // What runs a chatbot's workflow (today the platform's n8n); the rest of Ava only knows the contract.
        $this->app->bind(ChatbotExecutor::class, N8nExecutor::class);

        // How a campaign would really be delivered: no channel is connected yet, so the unavailable dispatcher says so and sends nothing.
        $this->app->bind(CampaignDispatcher::class, UnavailableCampaignDispatcher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Production accounts need a longer, mixed password; development and tests keep the framework minimum.
        Password::defaults(fn () => app()->isProduction() ? Password::min(12)->letters()->mixedCase()->numbers() : Password::min(8));

        // The role/permission catalog is global (shared by every Workspace), so only superusers working inside the administrative
        // Workspace manage it: a superuser acting inside a client Workspace has no platform privileges.
        Gate::define('manage-roles', fn (User $user) => $user->isPlatformSuperuser());

        // Creating or editing a Workspace or an Organization is a platform operation: same rule.
        Gate::define('manage-workspaces', fn (User $user) => $user->isPlatformSuperuser());

        // The public widget is open to the whole internet: each visitor (IP) is limited per widget, since every message
        // reaches a workflow that may call a paid model.
        // The webhook of one WhatsApp integration is limited by its own public key: Meta's addresses vary, an attacker's flood must not use up the budget of every integration.
        RateLimiter::for('whatsapp-integration', fn (Request $request) => Limit::perMinute((int) config('integrations.whatsapp.webhook_per_minute'))->by('wa-webhook|'.$request->route('key')));
        RateLimiter::for('widget-poll', fn (Request $request) => Limit::perMinute((int) config('chatbots.widget.poll_per_minute'))->by($request->ip().'|'.$request->route('key')));
        RateLimiter::for('widget-messages', fn (Request $request) => Limit::perMinute((int) config('chatbots.widget.messages_per_minute'))->by($request->ip().'|'.$request->route('key')));
        RateLimiter::for('widget-config', fn (Request $request) => Limit::perMinute((int) config('chatbots.widget.config_per_minute'))->by($request->ip().'|'.$request->route('key')));
        // The open booking page: reading is cheap, searching availability costs a calculation, and creating or changing a booking is what to protect.
        RateLimiter::for('booking-config', fn (Request $request) => Limit::perMinute((int) config('bookings.public.config_per_minute'))->by($request->ip().'|'.$request->route('key')));
        RateLimiter::for('booking-availability', fn (Request $request) => Limit::perMinute((int) config('bookings.public.availability_per_minute'))->by($request->ip().'|'.$request->route('key')));
        RateLimiter::for('booking-write', fn (Request $request) => Limit::perMinute((int) config('bookings.public.write_per_minute'))->by($request->ip().'|'.$request->route('key')));
        // Account recovery is open to the internet and sends email: limited per IP and per address, so one mailbox cannot be flooded.
        RateLimiter::for('password-reset-request', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(5)->by('email:'.sha1(mb_strtolower(is_string($email = $request->input('email')) ? $email : ''))),
        ]);
        RateLimiter::for('password-reset-submit', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));
        // Changing one's own password asks for the current one: limit guessing it from a hijacked session.
        // Webhooks de salida (§78): own buckets per person (a numeric `throttle:N,1` shares one counter across every route of the same person).
        RateLimiter::for('webhook-test', fn (Request $request) => Limit::perMinute((int) config('webhooks.limits.tests_per_minute'))->by('wh-test|'.$request->user()?->id.'|'.$request->route('webhook')));
        RateLimiter::for('webhook-secret', fn (Request $request) => Limit::perMinute(30)->by('wh-secret|'.$request->user()?->id));
        RateLimiter::for('webhook-write', fn (Request $request) => Limit::perMinute(30)->by('wh-write|'.$request->user()?->id));
        RateLimiter::for('webhook-read', fn (Request $request) => Limit::perMinute(60)->by('wh-read|'.$request->user()?->id));

        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(6)->by('user:'.$request->user()?->id.'|'.$request->ip()));

        // Public sign-up: sending a code reaches a mailbox (limited per IP and per address), checking one is a guessing surface, the other steps and the
        // availability check are cheap but open to anyone.
        RateLimiter::for('signup-code', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(20)->by('ip-hour:'.$request->ip()),
            Limit::perHour(5)->by('email:'.sha1(mb_strtolower(is_string($email = $request->input('email')) ? $email : ''))),
        ]);
        // Checking a code is limited by IP alone: the session id is chosen by the visitor (dropping the cookie would open a fresh bucket). The attempts per
        // code are also capped in SignupVerifier, so this only bounds how many codes one address can try at all.
        RateLimiter::for('signup-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perHour(60)->by('ip-hour:'.$request->ip()),
        ]);
        RateLimiter::for('signup-step', fn (Request $request) => Limit::perMinute(60)->by('ip:'.$request->ip()));
        RateLimiter::for('signup-availability', fn (Request $request) => Limit::perMinute(30)->by('ip:'.$request->ip()));

        // The absolute session clock starts at every FRESH login (the remember-cookie restoration fires the same event and is reconciled by EnforceAbsoluteSession).
        Event::listen(Login::class, function (Login $event) {
            $guard = Auth::guard($event->guard);

            if (! (method_exists($guard, 'viaRemember') && $guard->viaRemember()) && app()->bound('session.store')) {
                app(AbsoluteSession::class)->start(app('session.store'), $event->user, $event->remember);
            }
        });

        // Everything behind the sign-in (routes/web.php): one generous bucket per person, so a stolen session or a script cannot hammer the application. Polling screens stay far below it.
        // A second bucket for the Workspace of the SESSION (all its people together): one tenant can not take the shared resources, and a person can not change it (it is the server's session).
        RateLimiter::for('authenticated', fn (Request $request) => array_values(array_filter([
            Limit::perMinute((int) config('auth.authenticated_per_minute'))->by($request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()),
            $request->user() && $request->hasSession() && $request->session()->get('workspace_id') ? Limit::perMinute((int) config('api_protection.user.workspace_per_minute'))->by('ws:'.(int) $request->session()->get('workspace_id')) : null,
        ])));

        // All the visitors of ONE public widget (or booking page) together: the per-IP limits stop one visitor, these stop many IPs against one tenant (and its AI cost).
        RateLimiter::for('widget-key', fn (Request $request) => Limit::perMinute((int) config('api_protection.widget.key_messages_per_minute'))->by('widget-key:'.sha1((string) $request->route('key'))));
        RateLimiter::for('booking-key', fn (Request $request) => Limit::perMinute((int) config('api_protection.booking.key_write_per_minute'))->by('booking-key:'.sha1((string) $request->route('key'))));

        // Own buckets per person AND route (a numeric `throttle:N,1` shares one counter across every numeric route of the same person: see the webhook limiters).
        RateLimiter::for('reminders-read', fn (Request $request) => Limit::perMinute(120)->by('rem-read|'.$request->user()?->id.'|'.$request->route()?->getName()));
        RateLimiter::for('reminders-write', fn (Request $request) => Limit::perMinute(60)->by('rem-write|'.$request->user()?->id));
        RateLimiter::for('activity-read', fn (Request $request) => Limit::perMinute(60)->by('activity|'.$request->user()?->id.'|'.$request->route('conversation')));

        // Inbound webhook endpoints (the signature is the credential): one bucket per endpoint key (all senders) and one per IP, so neither a leaked URL nor a single host can flood them.
        RateLimiter::for('inbound-webhook-key', fn (Request $request) => Limit::perMinute(120)->by('inbound-key:'.sha1((string) $request->route('key'))));
        RateLimiter::for('inbound-webhook-ip', fn (Request $request) => Limit::perMinute(60)->by('inbound-ip:'.$request->ip()));

        // The automation of a chatbot reads its configuration on every conversation.
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute((int) config('chatbots.agent_per_minute'))->by(sha1((string) $request->bearerToken()).'|'.$request->ip()));

        // `composer run dev` (php artisan dev) runs on the host. Horizon (needs pcntl + Redis) and Reverb
        // (needs Redis) are only available in the Docker stack, so keep them out of the host dev processes.
        // Replaces Horizon's own `except('queue')`, so the plain queue listener runs again on the host.
        DevCommands::except('horizon', 'reverb');
    }
}
