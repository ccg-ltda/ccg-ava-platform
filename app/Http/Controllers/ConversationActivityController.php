<?php

namespace App\Http\Controllers;

use App\Services\ConversationActivity;
use App\Services\ConversationInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Actividad técnica» of one conversation: a read-only JSON page for the side panel. The conversation is looked up inside the ACTIVE Workspace of the session, so an id of
 * another Workspace is a 404; the route also requires `view-technical-activity`, which no role has by default except `admin`.
 */
class ConversationActivityController extends Controller
{
    public function __construct(private readonly ConversationInbox $inbox, private readonly ConversationActivity $activity) {}

    public function show(Request $request, int $conversation): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['started', 'succeeded', 'rejected', 'failed', 'uncertain'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $workspace = $request->attributes->get('workspace');
        $found = $this->inbox->find($this->inbox->scope($workspace), $conversation);

        return response()->json($this->activity->page($found, $workspace->settingsOrDefault(), $filters, (int) ($filters['page'] ?? 1)));
    }
}
