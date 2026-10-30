<?php

namespace App\Http\Controllers;

use App\Models\InboundEndpoint;
use App\Webhooks\InboundWebhooks;
use App\Webhooks\InboundWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Management of the INBOUND webhook endpoints (the URLs the customer's system calls), inside Integraciones. Everything belongs to the ACTIVE Workspace taken from the request
 * context: an endpoint id of another Workspace is a 404 whatever the browser sends. The page itself is IntegrationController::index (the `inboundWebhooks` prop). The secret is
 * flashed once (like the one of an outbound webhook) and never read back.
 */
class InboundWebhookController extends Controller
{
    public function __construct(private readonly InboundWebhookService $inbound) {}

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $name = $this->validName($request, $workspace);
        $created = $this->inbound->create($workspace, $name, $request->user());
        $request->session()->flash('inbound_secret', ['id' => $created['endpoint']->id, 'name' => $created['endpoint']->name, 'secret' => $created['secret']]);

        return $this->back('Endpoint creado. Copia el secreto ahora: no se vuelve a mostrar.');
    }

    public function update(Request $request, int $inbound): RedirectResponse
    {
        $endpoint = $this->find($request, $inbound);
        $this->inbound->rename($endpoint, $this->validName($request, $endpoint->workspace, $endpoint->id));

        return $this->back('Endpoint actualizado.');
    }

    public function activate(Request $request, int $inbound): RedirectResponse
    {
        $this->inbound->setActive($this->find($request, $inbound), true);

        return $this->back('Endpoint activado.');
    }

    public function deactivate(Request $request, int $inbound): RedirectResponse
    {
        $this->inbound->setActive($this->find($request, $inbound), false);

        return $this->back('Endpoint desactivado. La dirección responde 404 hasta que lo actives.');
    }

    public function rotate(Request $request, int $inbound): RedirectResponse
    {
        $endpoint = $this->find($request, $inbound);
        $secret = $this->inbound->rotate($endpoint);
        $request->session()->flash('inbound_secret', ['id' => $endpoint->id, 'name' => $endpoint->name, 'secret' => $secret]);

        return $this->back('Secreto rotado. El anterior se sigue aceptando durante '.config('webhooks.limits.secret_grace_hours').' h.');
    }

    public function destroy(Request $request, int $inbound): RedirectResponse
    {
        $endpoint = $this->find($request, $inbound);
        $name = $endpoint->name;
        $this->inbound->destroy($endpoint);

        return $this->back("El endpoint «{$name}» se eliminó junto con su historial. Su dirección dejó de existir.");
    }

    public function events(Request $request, int $inbound): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return response()->json($this->inbound->events($this->find($request, $inbound), (int) $request->query('page', 1)));
    }

    private function validName(Request $request, $workspace, ?int $ignore = null): string
    {
        // Before the migration the table does not exist: say so instead of failing inside the uniqueness rule.
        InboundWebhooks::ready() || throw ValidationException::withMessages(['name' => 'Los endpoints entrantes todavía no están disponibles en este entorno (falta aplicar la migración).']);

        return (string) $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('inbound_endpoints', 'name')->where('workspace_id', $workspace->id)->ignore($ignore)],
        ], ['name.required' => 'El nombre es obligatorio.', 'name.unique' => 'Ya tienes un endpoint con ese nombre.'])['name'];
    }

    private function find(Request $request, int $id): InboundEndpoint
    {
        return $request->attributes->get('workspace')->inboundEndpoints()->with('workspace')->findOrFail($id);
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->back(fallback: route('integrations.index', ['tab' => 'webhooks']))->with('success', $message);
    }
}
