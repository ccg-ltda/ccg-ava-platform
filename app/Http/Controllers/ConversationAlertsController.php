<?php

namespace App\Http\Controllers;

use App\Services\ConversationAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cheap question the team's browsers ask every few seconds: «anything new since this message id?». It sits in the conversations group (module + `view-conversations`), reads only the
 * ACTIVE Workspace and answers identifiers and kinds, never message content (see ConversationAlerts). Without a cursor it only says where «now» is.
 */
class ConversationAlertsController extends Controller
{
    public function __construct(private readonly ConversationAlerts $alerts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $after = $request->query('after');

        $body = is_string($after) && ctype_digit($after) && strlen($after) <= 18
            ? $this->alerts->since($workspace, (int) $after)
            : ['cursor' => $this->alerts->latest($workspace), 'events' => []];

        return response()->json($body)->header('Cache-Control', 'no-store');
    }
}
