<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes one section of an analytics page on its own. A failure is logged with its class only (an exception can carry
 * data) and the page receives an error for that section, so "nothing to show" and "could not be read" are never confused.
 */
trait ReadsSections
{
    /** @return array{data: mixed, error: ?string} */
    private function section(string $name, Closure $compute): array
    {
        try {
            return ['data' => $compute(), 'error' => null];
        } catch (Throwable $e) {
            Log::error(strtolower(str_replace('Controller', '', class_basename(static::class))).'_section_failed', ['section' => $name, 'exception' => $e::class]);

            return ['data' => null, 'error' => 'No se pudieron leer estos datos. Intenta de nuevo en unos minutos.'];
        }
    }
}
