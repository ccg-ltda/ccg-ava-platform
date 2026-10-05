<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferences of one Workspace. A Workspace without a row uses the defaults of config/workspace.php
 * (see Workspace::settingsOrDefault()).
 */
#[Fillable([
    'description', 'currency', 'timezone', 'date_format', 'time_format', 'logo_path', 'primary_color',
    'appearance', 'tax_country', 'tax_enabled', 'tax_name', 'tax_rate',
])]
class WorkspaceSetting extends Model
{
    protected function casts(): array
    {
        return ['tax_enabled' => 'boolean', 'tax_rate' => 'float'];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('workspace.defaults');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** A moment shown the way this Workspace wants: its timezone and date format. */
    public function formatDate(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone($this->timezone)->format(config("workspace.date_formats.{$this->date_format}.php"));
    }

    public function formatTime(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone($this->timezone)->format(config("workspace.time_formats.{$this->time_format}.php"));
    }
}
