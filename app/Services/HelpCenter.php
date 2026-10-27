<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * The data of the «Ayuda» panel (config/help.php): the questions as written, the manuals the Workspace may open and the support channels that really exist.
 * A manual is listed only if its PDF is in resources/manuals/, its module is in the plan of the Workspace AND the person holds the permission that opens that module
 * (config/modules.php `implemented.*.permission`): a manual of a module the Workspace does not have, or one the role cannot open, is neither listed nor served. A manual
 * without a module (general help) is for every member. Nothing is invented: no address and no manual appear unless they are configured.
 */
class HelpCenter
{
    public function __construct(private readonly ModuleAccess $modules, private readonly SupportDesk $desk) {}

    /** @return array{faq: list<array<string, mixed>>, manuals: list<array<string, mixed>>, supportEmail: ?string, mailto: ?string, chat: bool, chatState: string} */
    public function forRequest(Request $request): array
    {
        return [
            'faq' => config('help.faq'),
            'manuals' => array_map(fn (array $manual) => [
                'key' => $manual['key'], 'title' => $manual['title'], 'description' => $manual['description'] ?? '', 'version' => $manual['version'] ?? null,
                'module' => $manual['module'] ?? null, 'url' => route('help.manual', $manual['key']),
            ], $this->manuals($request)),
            'supportEmail' => $this->supportEmail(),
            // A mailto: link (the person's own mail application opens with the draft; Zonno sends nothing). Null while no valid address is configured.
            'mailto' => $this->mailto($request),
            // The chat is the support panel of SupportDesk (it opens inside the application, writes into Conversaciones of the administrative Workspace and needs no public key).
            // `chatState` says why it is or is not there, as a fixed word: `ready`, `internal` (CCG's own Workspace: it answers, it does not ask), `restricted` (only the administrators of a client Workspace may write to support) or
            // `unavailable` (the administrative Workspace that receives the requests cannot be found or is not active).
            'chat' => $this->chatState($request) === 'ready',
            'chatState' => $this->chatState($request),
        ];
    }

    private function chatState(Request $request): string
    {
        return match (true) {
            $request->attributes->get('workspace')?->isAdministrative() => 'internal',
            ! $this->desk->eligible($request) => 'restricted',
            ! $this->desk->available() => 'unavailable',
            default => 'ready',
        };
    }

    /**
     * The configured support address (BRAND_SUPPORT_EMAIL, else LEGAL_CONTACT_EMAIL), only if it is ONE valid address: no spaces, commas, semicolons, angle
     * brackets, quotes or line breaks, so a bad value can neither add recipients nor break the link. Anything else counts as not configured.
     */
    public function supportEmail(): ?string
    {
        $email = config('branding.support_email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match('/[\s,;<>"\'\\\\]/', $email) === 0 ? $email : null;
    }

    /** The draft: subject and a short body that helps describe the problem. Everything is percent-encoded, so no value can add a header, a recipient or markup. */
    public function mailto(Request $request): ?string
    {
        $email = $this->supportEmail();

        if ($email === null) {
            return null;
        }

        $workspace = $request->attributes->get('workspace');
        $body = implode('
', [
            'Describe qué estabas haciendo, qué esperabas que pasara y qué pasó en realidad:',
            '',
            '',
            '— Datos para que podamos ayudarte (puedes borrarlos) —',
            'Workspace: '.$this->plain((string) $workspace?->code),
            'Usuario: '.$this->plain((string) $request->user()?->name),
        ]);

        return 'mailto:'.rawurlencode($email).'?subject='.rawurlencode('Solicitud de soporte — '.(string) config('branding.name')).'&body='.rawurlencode($body);
    }

    /** A value shown inside the draft: single line, no control characters. */
    private function plain(string $value): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value), 0, 120);
    }

    /** @return list<array<string, mixed>> */
    public function manuals(Request $request): array
    {
        $available = $this->modules->forRequest($request);
        $permissions = (array) $request->attributes->get('workspace_permissions', []);

        return array_values(array_filter((array) config('help.manuals'), fn (array $manual) => isset($manual['key'], $manual['title'], $manual['file'])
            && (($manual['module'] ?? null) === null || (in_array($manual['module'], $available, true) && in_array(config("modules.implemented.{$manual['module']}.permission"), $permissions, true)))
            && is_file($this->path($manual))));
    }

    public function find(Request $request, string $key): ?array
    {
        foreach ($this->manuals($request) as $manual) {
            if ($manual['key'] === $key && is_file($this->path($manual))) {
                return $manual;
            }
        }

        return null;
    }

    public function path(array $manual): string
    {
        // Only a file name: a configured value can never point outside resources/manuals.
        return resource_path('manuals/'.basename((string) $manual['file']));
    }
}
