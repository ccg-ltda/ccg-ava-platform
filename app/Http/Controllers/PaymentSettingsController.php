<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Payments\PaymentHistory;
use App\Payments\PaymentSettings;
use App\Payments\WompiConfig;
use App\Payments\WompiConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Wompi from Configuraciones > Impuestos y facturación. Platform administration only (routes behind `workspace.platform:*`, Desarrollo_DEV). Secrets are write-only:
 * nothing here ever returns one, an empty field keeps the stored value, validation errors never echo input and the audit stores only that a secret changed.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(private readonly PaymentSettings $settings, private readonly AuditLogger $audit) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::in(PaymentSettings::ENVIRONMENTS)],
            'public_key' => ['nullable', 'string', 'max:120'],
            'private_key' => ['nullable', 'string', 'max:300'],
            'events_secret' => ['nullable', 'string', 'max:300'],
            'integrity_secret' => ['nullable', 'string', 'max:300'],
            'activate' => ['sometimes', 'boolean'],
        ], ['environment.in' => 'Elige Sandbox o Producción.']);

        $before = $this->settings->present($this->settings->row($data['environment']));
        $wasActive = $this->settings->active()?->environment;
        $row = $this->settings->save($data, $request->user());
        app(WompiConfig::class)->refresh();

        $changes = [];
        $secrets = array_filter(['Llave privada' => $data['private_key'] ?? null, 'Secreto de eventos' => $data['events_secret'] ?? null, 'Secreto de integridad' => $data['integrity_secret'] ?? null], fn ($v) => filled($v));

        foreach (array_keys($secrets) as $name) {
            $changes[] = AuditLogger::change($name, null, 'actualizado');
        }

        if (filled($data['public_key'] ?? null) && $data['public_key'] !== $before['publicKey']) {
            $changes[] = AuditLogger::change('Llave pública', $before['publicKey'], $data['public_key']);
        }

        if ($row->is_active && $wasActive !== $row->environment) {
            $changes[] = AuditLogger::change('Entorno activo', $wasActive, $row->environment);
        }

        $this->audit->record('updated', 'payment_settings', $row->id, 'Wompi ('.$row->environment.')', $changes);

        return back()->with('success', 'Configuración de Wompi guardada. Aún no está validada: usa «Probar conexión».');
    }

    public function validateConnection(Request $request, WompiConnection $connection): JsonResponse
    {
        $data = $request->validate(['environment' => ['required', Rule::in(PaymentSettings::ENVIRONMENTS)]]);
        $row = $this->settings->row($data['environment']);

        abort_unless($row, 422, 'Guarda primero las credenciales de este entorno.');

        $result = $connection->validate($row);
        $this->audit->record('tested', 'payment_settings', $row->id, 'Wompi ('.$row->environment.')', [AuditLogger::change('Resultado', null, $result['ok'] ? 'conexión validada' : 'falló')], null, $result['ok'] ? AuditLogger::SUCCESS : AuditLogger::FAILED);

        return response()->json($result + ['settings' => $this->settings->present($row->refresh())])->header('Cache-Control', 'no-store');
    }

    public function history(Request $request, PaymentHistory $history): JsonResponse
    {
        return response()->json($history->page($request))->header('Cache-Control', 'no-store');
    }

    public function detail(int $transaction, PaymentHistory $history): JsonResponse
    {
        $detail = $history->detail($transaction);
        abort_unless($detail, 404);

        return response()->json($detail)->header('Cache-Control', 'no-store');
    }
}
