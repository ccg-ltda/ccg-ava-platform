<?php

namespace App\Booking;

use App\Models\BookingSetting;
use App\Models\Workspace;
use App\Services\ModuleAccess;

/**
 * What the open channels (the booking page and the web) may reach. The public key in the URL is the ONLY thing the visitor
 * chooses: it names one Workspace, and the Workspace must have the booking page switched on, be active (with its Organization)
 * and have the plan modules that make booking work. If anything is off, the key simply does not exist (404): nothing says why.
 */
class PublicBooking
{
    /** Modules the open booking page needs: the catalog, the professionals and the agenda itself. */
    public const MODULES = ['service_catalog', 'professionals', 'bookings'];

    public function __construct(private readonly ModuleAccess $modules) {}

    public function resolve(string $key): Workspace
    {
        $setting = BookingSetting::where('public_key', $key)->where('public_enabled', true)->first();
        $workspace = $setting ? Workspace::available()->with('organization')->find($setting->workspace_id) : null;

        abort_unless($workspace && $this->modules->allowsAll($workspace, self::MODULES), 404);

        return $workspace;
    }

    /** What the page needs to draw itself and to know what it may ask for. @return array<string, mixed> */
    public function config(Workspace $workspace, BookingManager $bookings): array
    {
        $setting = BookingSetting::for($workspace);
        $capabilities = $bookings->capabilities($workspace);

        return [
            'business' => $workspace->name,
            'timezone' => $workspace->settingsOrDefault()->timezone,
            'currency' => $workspace->settingsOrDefault()->currency,
            'provider' => $capabilities['provider'],
            // The page offers booking only when the agenda behind it can really do it; otherwise it says so.
            'canBook' => $capabilities['connected'] && $capabilities['availability'] && $capabilities['book'],
            'requiresConfirmation' => $setting->requires_confirmation,
            'requirePhone' => $setting->require_phone,
            'requireEmail' => $setting->require_email,
            'cancelNoticeHours' => $setting->cancel_notice_hours,
            'maxDays' => (int) config('bookings.public.max_days'),
        ];
    }
}
