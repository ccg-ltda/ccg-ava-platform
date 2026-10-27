<?php

namespace App\Http\Controllers;

use App\Models\ChatbotChannel;
use App\Services\PrivateImage;
use App\Services\SupportContext;
use App\Services\SupportDesk;
use App\Services\WebChannelConnection;
use App\Services\WebWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public endpoints of the web widget (no session, CORS open to any site). The public key in the URL is the only thing
 * the caller chooses; it names one chatbot channel and everything else is resolved by the server (see WebWidget).
 */
class WidgetController extends Controller
{
    public function __construct(private readonly WebWidget $widget, private readonly SupportContext $context) {}

    public function config(Request $request, string $key): JsonResponse
    {
        $channel = $this->channel($request, $key);
        // Evidence for the state of the channel in Integraciones: the widget really loaded on another site.
        app(WebChannelConnection::class)->widgetSeen($channel, $request->header('Origin'));

        return response()->json($this->widget->appearance($channel, $key))->header('Cache-Control', 'no-store');
    }

    public function avatar(Request $request, string $key): Response|StreamedResponse
    {
        $channel = $this->channel($request, $key);

        return PrivateImage::response($channel->chatbot->avatar_path, $request);
    }

    public function message(Request $request, string $key): JsonResponse
    {
        $channel = $this->channel($request, $key);
        // Only the chat widget talks to the automation; a WhatsApp button is just a link.
        abort_unless($channel->channel === 'web', 404);
        $data = $request->validate([
            'session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/', 'not_regex:'.SupportDesk::RESERVED_KEY],
            'message' => ['required', 'string', 'max:'.config('chatbots.widget.max_message')],
            'message_id' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ]);

        // A signed-in member of a client Workspace writing to CCG's support: the conversation is theirs (and the team sees who they are). Anyone else keeps their anonymous session.
        $who = $this->context->resolve($request, $channel);

        $result = $this->widget->relay($channel, $who['contact_id'] ?? $data['session_id'], trim($data['message']), $data['message_id'] ?? null, $who['contact_name'] ?? null);

        return response()->json(
            $result['status'] === 200 ? $this->answer($result) : ['message' => $result['message']],
            $result['status'],
        )->header('Cache-Control', 'no-store');
    }

    /** The visitor's answer: just `reply` while the AI is the one answering (the original contract), plus `handling` when a person is. */
    private function answer(array $result): array
    {
        return ['reply' => $result['reply']] + ($result['handling'] === 'ai' ? [] : ['handling' => $result['handling']]) + (($result['pending'] ?? false) ? ['pending' => true] : []);
    }

    /** What a human agent wrote to this visitor since the last time it asked, and who is answering now. */
    public function poll(Request $request, string $key): JsonResponse
    {
        $channel = $this->channel($request, $key);
        abort_unless($channel->channel === 'web', 404);
        $data = $request->validate(['session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/', 'not_regex:'.SupportDesk::RESERVED_KEY]]);

        $who = $this->context->resolve($request, $channel);

        return response()->json($this->widget->collect($channel, $who['contact_id'] ?? $data['session_id']))->header('Cache-Control', 'no-store');
    }

    /** The live channel behind the key, or a 404 that does not say why; 403 when the site may not embed the widget. */
    private function channel(Request $request, string $key): ChatbotChannel
    {
        $channel = $this->widget->resolve($key);

        abort_unless($channel, 404);
        abort_unless($this->widget->originAllowed($channel, $request->header('Origin')), 403, 'Este sitio no está autorizado para usar el widget.');

        return $channel;
    }
}
