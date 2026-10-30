<?php

namespace App\Http\Controllers;

use App\Webhooks\InboundWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/webhooks/in/{key}`: the URL of an inbound endpoint. No session, no CSRF, no user: the signature is the credential (see InboundWebhooks). It sits next to the provider
 * webhooks (Meta, Wompi) and replaces none of them; it has its own limits (per key and per IP) and the 1 MB body cap of the provider surface.
 */
class InboundWebhookReceiveController extends Controller
{
    public function receive(Request $request, string $key, InboundWebhooks $inbound): JsonResponse
    {
        [$status, $answer] = $inbound->receive(
            $key, $request->getContent(), $request->header('X-Ava-Timestamp'), $request->header('X-Ava-Signature'), $request->header('X-Ava-Event-Id'),
        );

        return response()->json($answer, $status);
    }
}
