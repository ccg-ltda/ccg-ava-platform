<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWebhookRequest;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Webhooks de salida, dentro de Integraciones. Everything belongs to the ACTIVE Workspace taken from the request context: an endpoint or a delivery id of another Workspace is a
 * 404, whatever the browser sends. The page itself is IntegrationController::index (it carries the `webhooks` prop); this controller only changes things and serves the
 * delivery history. The secret is flashed once (like the token of an agent) and never read back.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function store(SaveWebhookRequest $request): RedirectResponse
    {
        $created = $this->webhooks->create($request->attributes->get('workspace'), $request->validated(), $request->user());
        $request->session()->flash('webhook_secret', ['id' => $created['endpoint']->id, 'name' => $created['endpoint']->name, 'secret' => $created['secret']]);

        return $this->back('Webhook creado. Copia el secreto ahora: no se vuelve a mostrar.');
    }

    public function update(SaveWebhookRequest $request, int $webhook): RedirectResponse
    {
        $this->webhooks->update($request->current, $request->validated());

        return $this->back('Webhook actualizado.');
    }

    public function activate(Request $request, int $webhook): RedirectResponse
    {
        $this->webhooks->setActive($this->find($request, $webhook), true);

        return $this->back('Webhook activado.');
    }

    public function deactivate(Request $request, int $webhook): RedirectResponse
    {
        $this->webhooks->setActive($this->find($request, $webhook), false);

        return $this->back('Webhook desactivado. No se enviarán más eventos hasta que lo actives.');
    }

    public function rotate(Request $request, int $webhook): RedirectResponse
    {
        $endpoint = $this->find($request, $webhook);
        $secret = $this->webhooks->rotate($endpoint);
        $request->session()->flash('webhook_secret', ['id' => $endpoint->id, 'name' => $endpoint->name, 'secret' => $secret]);

        return $this->back('Secreto rotado. El anterior sigue firmando un segundo encabezado durante '.config('webhooks.limits.secret_grace_hours').' h.');
    }

    public function test(Request $request, int $webhook): RedirectResponse
    {
        $this->webhooks->sendTest($this->find($request, $webhook));

        return $this->back('Prueba enviada. Revisa el resultado en el historial de entregas.');
    }

    public function destroy(Request $request, int $webhook): RedirectResponse
    {
        $endpoint = $this->find($request, $webhook);
        $name = $endpoint->name;
        $this->webhooks->destroy($endpoint);

        return $this->back("El webhook «{$name}» se eliminó junto con su historial de entregas.");
    }

    /** One page of the delivery history of the Workspace (all its webhooks, or the one in `webhook`). Filters are validated and applied in the server. */
    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'webhook' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'string', Rule::in([...array_keys(config('webhooks.events')), config('webhooks.test_event')])],
            'status' => ['nullable', 'string', Rule::in(['pending', 'sending', 'delivered', 'failed', 'abandoned'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json($this->webhooks->history($request->attributes->get('workspace'), $filters));
    }

    public function deliveries(Request $request, int $webhook): JsonResponse
    {
        $this->find($request, $webhook);
        $request->merge(['webhook' => $webhook]);

        return $this->history($request);
    }

    public function retry(Request $request, int $webhook, int $delivery): RedirectResponse
    {
        $this->webhooks->retry($this->find($request, $webhook)->deliveries()->findOrFail($delivery));

        return $this->back('Entrega reprogramada. Se reenvía con el mismo identificador de evento.');
    }

    /** The endpoint of the ACTIVE Workspace, or a 404: other Workspaces' ids do not exist from here. */
    private function find(Request $request, int $id): WebhookEndpoint
    {
        return $request->attributes->get('workspace')->webhookEndpoints()->findOrFail($id);
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->back(fallback: route('integrations.index'))->with('success', $message);
    }
}
