<?php

namespace App\Http\Controllers;

use App\Payments\WompiEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The events URL of Wompi. Wompi only needs HTTP 200; any other status makes it retry (30 minutes, 3 hours, 24 hours). */
class WompiWebhookController extends Controller
{
    public function receive(Request $request, WompiEvents $events): JsonResponse
    {
        $result = $events->handle($request->json()->all(), $request->header('X-Event-Checksum'));

        return match ($result) {
            WompiEvents::OK => response()->json(['ok' => true]),
            WompiEvents::RETRY => response()->json(['ok' => false], 503),
            default => response()->json(['ok' => false], 400),
        };
    }
}
