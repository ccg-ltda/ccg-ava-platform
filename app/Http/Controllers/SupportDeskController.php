<?php

namespace App\Http\Controllers;

use App\Services\SupportDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The support panel of the administrators of a client Workspace (JSON for the floating panel). Both routes need a signed-in member whose ROLE is administrator of the
 * ACTIVE Workspace of the session (SupportDesk::eligible); the Workspace, the person and the destination are all decided here from the session, never from the request,
 * and a person can only read and write the conversation that is theirs.
 */
class SupportDeskController extends Controller
{
    public function __construct(private readonly SupportDesk $desk) {}

    public function thread(Request $request): JsonResponse
    {
        $this->authorizeDesk($request);

        return response()->json($this->desk->thread($request->user(), $request->attributes->get('workspace')))->header('Cache-Control', 'no-store');
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeDesk($request);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.config('support.desk_max_message')],
            'message_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ], [], ['message' => 'el mensaje']);

        $result = $this->desk->send($request->user(), $request->attributes->get('workspace'), trim($data['message']), $data['message_id']);

        return response()->json($result + $this->desk->thread($request->user(), $request->attributes->get('workspace')))->header('Cache-Control', 'no-store');
    }

    private function authorizeDesk(Request $request): void
    {
        abort_unless($this->desk->eligible($request), 403, 'El soporte está disponible solo para los administradores del Workspace.');
    }
}
