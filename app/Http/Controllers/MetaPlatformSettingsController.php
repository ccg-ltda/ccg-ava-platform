<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Integrations\MetaGraph;
use App\Services\PlatformMeta;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * The platform's Meta app from Configuraciones > Meta. Platform administration only (routes behind `workspace.platform:*`, Desarrollo_DEV): it is configured ONCE for every
 * Workspace, and each Workspace then connects its own Instagram account or Facebook Page with OAuth. Secrets are write-only: nothing here returns one, an empty field keeps the stored
 * value, validation errors never echo input, and the audit records only WHICH fields changed.
 */
class MetaPlatformSettingsController extends Controller
{
    private const LABELS = ['app_id' => 'App ID de Facebook', 'app_secret' => 'Secreto de la app de Facebook', 'instagram_app_id' => 'App ID de Instagram', 'instagram_app_secret' => 'Secreto de la app de Instagram', 'webhook_verify_token' => 'Token de verificación del webhook', 'graph_version' => 'Versión de Graph'];

    public function __construct(private readonly PlatformMeta $meta, private readonly AuditLogger $audit) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'string', 'max:40'],
            'app_secret' => ['nullable', 'string', 'max:100'],
            'instagram_app_id' => ['nullable', 'string', 'max:40'],
            'instagram_app_secret' => ['nullable', 'string', 'max:100'],
            'webhook_verify_token' => ['nullable', 'string', 'max:120'],
            'graph_version' => ['nullable', 'string', 'max:12'],
            'clear' => ['sometimes', 'array'],
            'clear.*' => ['string', Rule::in(array_keys(PlatformMeta::FIELDS))],
        ]);

        $changed = $this->meta->save($data, $request->user());

        if ($changed !== []) {
            $this->audit->record('updated', 'meta_platform_settings', 1, 'App de Meta de la plataforma', array_map(fn (string $field) => AuditLogger::change(self::LABELS[$field], null, 'actualizado'), $changed));
        }

        return back()->with('success', $changed === [] ? 'No había cambios que guardar.' : 'Configuración de Meta guardada. Usa «Comprobar con Meta» para verificar la app.');
    }

    /**
     * Asks Meta whether the app credentials work: the app access token (`{app-id}|{app-secret}`) reads the app itself. It checks the Facebook app (Messenger and WhatsApp); the Instagram
     * app secret can only be proven by a real authorization, and the answer says so. Only fixed messages come back: never Meta's text, a token or a secret.
     */
    public function check(Request $request): JsonResponse
    {
        $results = [];

        foreach ([['facebook', 'app_id', 'app_secret'], ['instagram', 'instagram_app_id', 'instagram_app_secret']] as [$label, $idKey, $secretKey]) {
            $id = PlatformMeta::get($idKey);
            $secret = PlatformMeta::get($secretKey);

            if ($id === null || $secret === null) {
                $results[$label] = ['configured' => false, 'ok' => false, 'message' => 'Falta el App ID o el secreto.'];

                continue;
            }

            if ($label === 'instagram') {
                // Meta offers no call that proves an Instagram app secret without authorizing an account: it is proven by the first real connection, and we say so instead of guessing.
                $results[$label] = ['configured' => true, 'ok' => null, 'message' => 'Guardada. Meta solo permite comprobar este secreto al autorizar la primera cuenta de Instagram.'];

                continue;
            }

            try {
                $response = Http::acceptJson()->timeout(10)->connectTimeout(10)->withoutRedirecting()
                    ->get(rtrim(config('integrations.meta.facebook_graph_url'), '/').'/'.MetaGraph::version().'/'.$id, ['fields' => 'id', 'access_token' => $id.'|'.$secret]);
                $ok = $response->successful() && (string) $response->json('id') === $id;
                $results[$label] = ['configured' => true, 'ok' => $ok, 'message' => $ok ? 'Meta reconoce el App ID y el secreto.' : 'Meta rechazó el App ID o el secreto: revísalos en el panel de la app.'];
            } catch (ConnectionException) {
                $results[$label] = ['configured' => true, 'ok' => false, 'message' => 'No se pudo conectar con Meta. Inténtalo de nuevo en unos minutos.'];
            }
        }

        $configured = array_filter($results, fn (array $r) => $r['configured'] && $r['ok'] !== null);
        $ok = $configured !== [] && collect($configured)->every(fn (array $r) => $r['ok']);
        $summary = $configured === [] ? 'No hay nada configurado que comprobar.' : ($ok ? 'La app de Meta respondió correctamente.' : 'Meta no aceptó alguna credencial.');

        if ($configured !== []) {
            $this->meta->recordValidation($ok, $summary);
        }

        $this->audit->record('tested', 'meta_platform_settings', 1, 'App de Meta de la plataforma', [AuditLogger::change('Resultado', null, $ok ? 'verificada' : 'falló')], null, $ok ? AuditLogger::SUCCESS : AuditLogger::FAILED);

        return response()->json(['ok' => $ok, 'message' => $summary, 'results' => $results, 'settings' => $this->meta->present()])->header('Cache-Control', 'no-store');
    }
}
