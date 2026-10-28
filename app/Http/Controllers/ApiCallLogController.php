<?php

namespace App\Http\Controllers;

use App\Models\ApiCallLog;
use App\Models\Workspace;
use App\Observability\ApiCallRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Historial de llamados» of Integraciones: the redacted record of the outbound API calls of the ACTIVE Workspace (`view-integrations`, module integrations). The
 * Workspace always comes from the session; a platform-level call (no Workspace) is visible only inside the administrative Workspace. What is stored was redacted
 * before it was saved (App\Observability\ApiRedactor), and this adds nothing to it.
 */
class ApiCallLogController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        // Before the migration there is no history yet: an empty page, not an error.
        if (! ApiCallRecorder::ready()) {
            return response()->json(['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1]);
        }

        $query = $this->scope($request)->orderByDesc('id');

        if (is_string($integration = $request->query('integration')) && $integration !== '') {
            $query->where('integration', substr($integration, 0, 32));
        }

        if (in_array($request->query('outcome'), ['success', 'http_error', 'timeout', 'connection_error'], true)) {
            $query->where('outcome', $request->query('outcome'));
        }

        if (is_string($code = $request->query('status')) && ctype_digit($code) && strlen($code) <= 3) {
            $query->where('status_code', (int) $code);
        }

        if (is_string($operation = $request->query('operation')) && $operation !== '') {
            $query->where('operation', substr($operation, 0, 64));
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (is_string($date = $request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $query->where('created_at', $operator, $key === 'from' ? $date.' 00:00:00' : $date.' 23:59:59');
            }
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString()->through(fn (ApiCallLog $log) => $this->summary($log));

        return response()->json($page)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $call): JsonResponse
    {
        abort_unless(ApiCallRecorder::ready(), 404);

        $log = $this->scope($request)->whereKey($call)->firstOrFail();
        $workspace = $log->workspace_id ? Workspace::find($log->workspace_id) : null;

        return response()->json($this->summary($log) + [
            'request' => ['headers' => json_decode((string) $log->request_headers, true) ?: [], 'body' => $log->request_body],
            'response' => ['headers' => json_decode((string) $log->response_headers, true) ?: [], 'body' => $log->response_body],
            'error' => $log->error_class || $log->error_message ? ['type' => $log->error_class, 'message' => $log->error_message, 'timeout' => $log->outcome === 'timeout', 'connection' => $log->outcome === 'connection_error'] : null,
            'context' => ['workspace' => $workspace?->code, 'resourceType' => $log->resource_type, 'resourceId' => $log->resource_id],
            'truncated' => $log->truncated,
        ])->header('Cache-Control', 'no-store');
    }

    /** Only the rows of the session's Workspace (and the platform's own calls, from the administrative Workspace). */
    private function scope(Request $request): Builder
    {
        $workspace = $request->attributes->get('workspace');

        return ApiCallLog::query()->where(function (Builder $q) use ($workspace) {
            $q->where('workspace_id', $workspace->id);

            if ($workspace->isAdministrative()) {
                $q->orWhereNull('workspace_id');
            }
        });
    }

    /** @return array<string, mixed> */
    private function summary(ApiCallLog $log): array
    {
        return [
            'id' => $log->id, 'at' => $log->created_at?->toIso8601String(), 'integration' => $log->integration, 'operation' => $log->operation, 'method' => $log->method,
            'host' => $log->host, 'path' => $log->path, 'status' => $log->status_code, 'outcome' => $log->outcome, 'durationMs' => $log->duration_ms, 'correlationId' => $log->correlation_id,
        ];
    }
}
