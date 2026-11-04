<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\MetaConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('meta:refresh-tokens')]
#[Description('Renew the Instagram authorizations that are close to expiring (Meta allows it after 24 hours and before they expire). Prints only counts.')]
class MetaRefreshTokensCommand extends Command
{
    /** Renew when this many days or fewer are left (each renewal adds 60 days). */
    private const WINDOW_DAYS = 20;

    public function handle(MetaConnection $meta): int
    {
        if (! Schema::hasTable('integrations')) {
            return self::SUCCESS;
        }

        $renewed = $failed = 0;

        Integration::where('type', 'instagram')->where('is_active', true)->each(function (Integration $integration) use ($meta, &$renewed, &$failed) {
            $expires = $integration->config['token_expires_at'] ?? null;
            $since = $integration->config['token_refreshed_at'] ?? $integration->config['connected_at'] ?? null;

            // Not close to expiring yet, already expired (Meta no longer renews it: the card asks to connect again) or younger than 24 hours.
            if (! filled($expires) || now()->addDays(self::WINDOW_DAYS)->lessThan($expires) || now()->greaterThan($expires) || (filled($since) && now()->subDay()->lessThan($since))) {
                return;
            }

            $meta->refresh($integration) ? $renewed++ : $failed++;
        });

        $this->components->info("{$renewed} autorización(es) de Instagram renovada(s); {$failed} sin renovar.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
