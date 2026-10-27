<?php

namespace App\Http\Controllers;

use App\Services\MessageAttachments;
use App\Services\SupportDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        $session = $request->query('session');

        return response()->json($this->desk->thread($request->user(), $request->attributes->get('workspace'), is_string($session) && ctype_digit($session) ? (int) $session : null, $request->boolean('seen')))->header('Cache-Control', 'no-store');
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeDesk($request);

        $data = $request->validate([
            'message' => ['nullable', 'required_without:files', 'string', 'max:'.config('support.desk_max_message')],
            'session' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:'.config('support.attachments.max_files')],
            'files.*' => ['file'],
            'message_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ], [], ['message' => 'el mensaje']);

        $result = $this->desk->send($request->user(), $request->attributes->get('workspace'), trim((string) ($data['message'] ?? '')), $data['message_id'], $request->file('files', []), isset($data['session']) ? (int) $data['session'] : null);

        return response()->json($result + $this->desk->thread($request->user(), $request->attributes->get('workspace')))->header('Cache-Control', 'no-store');
    }

    /** The person ends their own session; it can not be reopened, the next message starts a new one. */
    public function close(Request $request): JsonResponse
    {
        $this->authorizeDesk($request);
        $data = $request->validate(['session' => ['required', 'integer']]);

        return response()->json($this->desk->finish($request->user(), $request->attributes->get('workspace'), (int) $data['session']))->header('Cache-Control', 'no-store');
    }

    /** An attachment of the person's own support conversation. */
    public function attachment(Request $request, int $attachment, MessageAttachments $files): Response
    {
        $this->authorizeDesk($request);

        return $files->response($this->desk->attachmentFor($request->user(), $request->attributes->get('workspace'), $attachment));
    }

    private function authorizeDesk(Request $request): void
    {
        abort_unless($this->desk->eligible($request), 403, 'El soporte está disponible solo para los administradores del Workspace.');
    }
}
