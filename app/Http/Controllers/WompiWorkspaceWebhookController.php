<?php

namespace App\Http\Controllers;

use App\Payments\Gateways\WompiEventHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The events URL of ONE Workspace's Wompi connection (`POST /api/webhooks/payments/wompi/{token}`). Separate from the platform's own Wompi (`POST /api/webhooks/wompi`).
 * Wompi only needs HTTP 200; any other status makes it retry (30 minutes, 3 hours, 24 hours).
 */
class WompiWorkspaceWebhookController extends Controller
{
    public function receive(Request $request, string $token, WompiEventHandler $events): JsonResponse
    {
        return match ($events->handle($token, $request->json()->all(), $request->header('X-Event-Checksum'))) {
            WompiEventHandler::OK => response()->json(['ok' => true]),
            WompiEventHandler::NOT_FOUND => response()->json(['ok' => false], 404),
            WompiEventHandler::RETRY => response()->json(['ok' => false], 503),
            default => response()->json(['ok' => false], 400),
        };
    }
}
