<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Which skeleton the first paint of a page uses (config/skeleton.php): the shape by URL, and whether the page has the application shell at all. */
final class Skeleton
{
    /** The variant for a request path («orders/12» -> table); the configured default for a path that is not listed. */
    public static function variantFor(string $path): string
    {
        $first = explode('/', trim($path, '/'))[0] ?? '';

        return (string) (config('skeleton.paths')[$first] ?? config('skeleton.default'));
    }

    /** Whether an Inertia page component is drawn inside the application shell (and so can show its skeleton). */
    public static function hasShell(?string $component): bool
    {
        return $component !== null && ! Str::startsWith($component, (array) config('skeleton.guest_components'));
    }
}
