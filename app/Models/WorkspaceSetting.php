<?php

namespace App\Models;

use App\Audit\Masked;
use App\Models\Concerns\Audited;
use Carbon\CarbonInterface;
use Closure;
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
    use Audited;

    protected function casts(): array
    {
        return ['tax_enabled' => 'boolean', 'tax_rate' => 'float'];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('workspace.defaults');
    }

    public function auditResource(): string
    {
        return 'settings';
    }

    public function auditLabel(): string
    {
        return $this->workspace->name;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return [
            'description' => 'Descripción', 'currency' => 'Moneda', 'timezone' => 'Zona horaria',
            'date_format' => 'Formato de fecha', 'time_format' => 'Formato de hora', 'primary_color' => 'Color principal',
            'appearance' => 'Apariencia', 'tax_country' => 'País fiscal', 'tax_enabled' => 'Impuesto activado',
            'tax_name' => 'Nombre del impuesto', 'tax_rate' => 'Tarifa del impuesto',
        ];
    }

    public function auditSnapshot(Closure $get): array
    {
        // The stored path is internal: only the fact that a (different) logo is set is audited.
        return $this->auditBaseSnapshot($get) + ['Logo' => Masked::of($get('logo_path'), 'Logo cargado')];
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

    /** Like formatTime, with seconds (audit events are exact to the second). */
    public function formatPreciseTime(CarbonInterface $moment): string
    {
        $format = str_replace('i', 'i:s', config("workspace.time_formats.{$this->time_format}.php"));

        return $moment->copy()->setTimezone($this->timezone)->format($format);
    }

    public function formatTime(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone($this->timezone)->format(config("workspace.time_formats.{$this->time_format}.php"));
    }
}
