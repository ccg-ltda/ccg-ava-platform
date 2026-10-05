<?php

namespace App\Services;

use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Reads and saves the preferences of one Workspace (Configuraciones). The Workspace always comes from the
 * request context, never from the client, so a Workspace can only change its own settings.
 */
class WorkspaceSettingsService
{
    /** What every page needs to apply the Workspace's preferences (shared Inertia prop). */
    public function shared(Workspace $workspace): array
    {
        $settings = $workspace->settingsOrDefault();

        return [
            'appearance' => $settings->appearance,
            'primaryColor' => $settings->primary_color,
            'logoUrl' => $this->logoUrl($settings),
            'currency' => $settings->currency,
            'timezone' => $settings->timezone,
            'dateFormat' => $settings->date_format,
            'timeFormat' => $settings->time_format,
            'tax' => [
                'country' => $settings->tax_country,
                'enabled' => $settings->tax_enabled,
                'name' => $settings->tax_name,
                'rate' => $settings->tax_rate,
            ],
        ];
    }

    /** Values of the Configuraciones form. */
    public function form(Workspace $workspace): array
    {
        $settings = $workspace->settingsOrDefault();

        return [
            'name' => $workspace->name,
            'description' => $settings->description ?? '',
            'currency' => $settings->currency,
            'timezone' => $settings->timezone,
            'date_format' => $settings->date_format,
            'time_format' => $settings->time_format,
            'primary_color' => $settings->primary_color,
            'appearance' => $settings->appearance,
            'tax_country' => $settings->tax_country,
            'tax_enabled' => $settings->tax_enabled,
            'tax_name' => $settings->tax_name,
            'tax_rate' => $settings->tax_rate,
            'logo_url' => $this->logoUrl($settings),
        ];
    }

    /** Options of every select, from config/workspace.php. */
    public function catalog(): array
    {
        $options = fn (array $items) => collect($items)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return [
            'currencies' => collect(config('workspace.currencies'))
                ->map(fn ($label, $code) => ['value' => $code, 'label' => "{$code} — {$label}"])->values(),
            'timezones' => $options(config('workspace.timezones')),
            'dateFormats' => collect(config('workspace.date_formats'))->map(fn ($f, $v) => ['value' => $v, 'label' => $f['label'], 'php' => $f['php']])->values(),
            'timeFormats' => collect(config('workspace.time_formats'))->map(fn ($f, $v) => ['value' => $v, 'label' => $f['label'], 'php' => $f['php']])->values(),
            'appearances' => $options(config('workspace.appearances')),
            'palette' => config('workspace.palette'),
            'taxCountries' => collect(config('workspace.tax_countries'))->map(fn ($c, $code) => ['code' => $code] + $c)->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated by UpdateWorkspaceSettingsRequest
     */
    public function update(Workspace $workspace, array $data, ?UploadedFile $logo, bool $removeLogo): void
    {
        $stored = $logo ? Storage::putFile("workspaces/{$workspace->id}/logo", $logo) : null;
        $previous = null;

        try {
            DB::transaction(function () use ($workspace, $data, $stored, $removeLogo, &$previous) {
                $workspace->update(['name' => $data['name']]);

                $settings = $workspace->settings()->firstOrNew();
                $settings->fill(collect($data)->except(['name', 'logo', 'remove_logo'])->all());
                $settings->description = filled($data['description'] ?? null) ? $data['description'] : null;

                if ($stored || $removeLogo) {
                    $previous = $settings->logo_path;
                    $settings->logo_path = $stored;
                }

                $settings->save();
                $workspace->setRelation('settings', $settings);
            });
        } catch (Throwable $exception) {
            // Nothing was saved, so the file just uploaded must not stay behind.
            if ($stored) {
                Storage::delete($stored);
            }

            throw $exception;
        }

        // The old logo goes only after the new state is safely stored.
        if ($previous) {
            Storage::delete($previous);
        }
    }

    /**
     * Streams the logo through the app, so it needs no public bucket and only reaches signed-in members.
     * The same URL returns a different logo per Workspace, so the browser may keep a copy (ETag) but must
     * revalidate it with the server on every use (`no-cache`): a copy cached for one session is never shown
     * to another, because the server answers by the requester's own Workspace.
     */
    public function logoResponse(Workspace $workspace, Request $request): Response|StreamedResponse
    {
        $path = $workspace->settingsOrDefault()->logo_path;

        abort_unless($path && Storage::exists($path), 404);

        $headers = [
            'Cache-Control' => 'private, no-cache',
            'ETag' => '"'.sha1($path).'"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->header('If-None-Match') === $headers['ETag']) {
            return response('', 304, $headers);
        }

        return Storage::response($path, null, $headers);
    }

    private function logoUrl(WorkspaceSetting $settings): ?string
    {
        return $settings->logo_path
            ? route('workspace.logo', ['v' => "{$settings->workspace_id}-{$settings->updated_at?->timestamp}"])
            : null;
    }
}
