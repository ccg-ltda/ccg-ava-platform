<?php

namespace App\Legal;

use App\Models\CommunicationConsent;
use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The legal documents of the platform: their texts (resources/legal), their current versions (config/legal.php) and what each
 * account accepted. Company data is read from configuration; whatever is empty is shown as "[POR CONFIRMAR: ...]" and never guessed.
 */
class LegalDocuments
{
    private const FIELD_LABELS = [
        'legal_name' => 'razón social',
        'tax_id' => 'identificación tributaria',
        'address' => 'dirección',
        'contact_email' => 'correo de contacto',
        'privacy_contact' => 'canal para consultas y reclamos sobre datos personales',
        'jurisdiction' => 'jurisdicción y normativa aplicable',
    ];

    public const UNAVAILABLE = 'El registro de aceptaciones y de preferencias de comunicación no está disponible: falta aplicar la migración create_legal_acceptances_tables. No se guardó nada.';

    /**
     * Whether the tables of the acceptance record exist. Without them (migration not applied yet) nothing is read as if it were
     * empty-but-valid and nothing is written: the public texts and the rest of the profile keep working, the record's own screens
     * say it is unavailable, and when acceptance is required the platform refuses to continue rather than let anyone in without
     * leaving the evidence (EnsureLegalAcceptance, `integrations:diagnose`).
     */
    public function available(): bool
    {
        return Schema::hasTable('legal_acceptances') && Schema::hasTable('communication_consents');
    }

    /** @return array<string, array{slug: string, title: string, version: string, required: bool}> */
    public function all(): array
    {
        return config('legal.documents');
    }

    /** The document with this URL slug, as [key, definition], or null. */
    public function findBySlug(string $slug): ?array
    {
        foreach ($this->all() as $key => $definition) {
            if ($definition['slug'] === $slug) {
                return [$key, $definition];
            }
        }

        return null;
    }

    /** The text of a document, with the company data filled in (or marked as pending). */
    public function render(string $key): array
    {
        $document = require resource_path("legal/{$key}.php");
        $values = $this->values();
        $fill = fn (string $text) => preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => $values[$m[1]] ?? $m[0], $text);

        return [
            'intro' => array_map($fill, $document['intro']),
            'sections' => array_map(fn (array $section) => [
                'heading' => $section['heading'],
                'paragraphs' => array_map($fill, $section['paragraphs'] ?? []),
                'items' => array_map($fill, $section['items'] ?? []),
            ], $document['sections']),
        ];
    }

    /** The documents an account must still accept (current version of each required document). */
    public function pendingFor(User $user): Collection
    {
        $accepted = LegalAcceptance::where('user_id', $user->id)->get()->map(fn ($a) => $a->document.'@'.$a->version)->all();

        return collect($this->all())
            ->filter(fn (array $definition, string $key) => $definition['required'] && ! in_array($key.'@'.$definition['version'], $accepted, true));
    }

    /** Records the acceptance of the CURRENT version of each given required document. Idempotent. */
    public function accept(User $user, array $keys, ?string $ip): void
    {
        foreach ($keys as $key) {
            $definition = $this->all()[$key] ?? null;

            if ($definition && $definition['required']) {
                LegalAcceptance::firstOrCreate(
                    ['user_id' => $user->id, 'document' => $key, 'version' => $definition['version']],
                    ['accepted_at' => now(), 'ip_address' => $ip],
                );
            }
        }
    }

    /** Whether the account currently authorizes commercial communications (the latest decision wins; none = no). */
    public function marketingGranted(User $user): bool
    {
        return (bool) CommunicationConsent::where('user_id', $user->id)->where('purpose', 'marketing')->latest('id')->value('granted');
    }

    /**
     * Records a decision about commercial communications only when it changes the current one; a refusal from someone who
     * never granted anything is the default and needs no record. Returns whether it recorded.
     */
    public function setMarketing(User $user, bool $granted, string $source, ?string $ip): bool
    {
        if ($this->marketingGranted($user) === $granted) {
            return false;
        }

        CommunicationConsent::create([
            'user_id' => $user->id,
            'purpose' => 'marketing',
            'granted' => $granted,
            'source' => $source,
            'privacy_notice_version' => $this->all()['privacy_notice']['version'],
            'recorded_at' => now(),
            'ip_address' => $ip,
        ]);

        return true;
    }

    /** What the account accepted, newest first (for the profile). */
    public function acceptedBy(User $user): array
    {
        return LegalAcceptance::where('user_id', $user->id)->orderByDesc('accepted_at')->get()
            ->map(fn (LegalAcceptance $a) => [
                'title' => $this->all()[$a->document]['title'] ?? $a->document,
                'version' => $a->version,
                'accepted_at' => $a->accepted_at->toIso8601String(),
            ])->all();
    }

    /** @return array<string, string> placeholder => value or "[POR CONFIRMAR: ...]" */
    private function values(): array
    {
        $values = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            $value = trim((string) config("legal.responsible.{$field}"));
            $values[$field] = $value !== '' ? $value : "[POR CONFIRMAR: {$label}]";
        }

        $date = trim((string) config('legal.effective_date'));
        $values['effective_date'] = $date !== '' ? $date : '[POR CONFIRMAR: fecha de entrada en vigor]';

        return $values;
    }
}
