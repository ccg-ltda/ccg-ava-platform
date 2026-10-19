<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Something that needs a person soon (the AI failed for good and a conversation was handed over, for example). The one place that raises it:
 * it writes a structured record to the `alerts` log channel (`storage/logs/alerts.log`, plus every channel listed in ALERT_LOG_CHANNELS, such as
 * Slack once its webhook is configured). Without that configuration the alert exists only on the server: nothing is sent anywhere else and no
 * caller may say otherwise. Context is identifiers and fixed codes only, never message text, contact data or credentials. Raising an alert can never
 * break the work that raised it.
 */
class OperationalAlert
{
    /** @param  array<string, scalar|null>  $context */
    public function raise(string $event, array $context = []): void
    {
        try {
            Log::channel('alerts')->warning($event, $context);
        } catch (Throwable $e) {
            Log::error('operational_alert_failed', ['event' => $event, 'exception' => $e::class]);
        }
    }
}
