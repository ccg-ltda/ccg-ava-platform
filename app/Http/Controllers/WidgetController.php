<?php

namespace App\Http\Controllers;

use App\Models\ChatbotChannel;
use App\Services\PrivateImage;
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
    public function __construct(private readonly WebWidget $widget) {}

    public function config(Request $request, string $key): JsonResponse
    {
        $channel = $this->channel($request, $key);

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
        $data = $request->validate([
            'session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'message' => ['required', 'string', 'max:'.config('chatbots.widget.max_message')],
        ]);

        $result = $this->widget->relay($channel, $data['session_id'], trim($data['message']));

        return response()->json(
            $result['status'] === 200 ? ['reply' => $result['reply']] : ['message' => $result['message']],
            $result['status'],
        )->header('Cache-Control', 'no-store');
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
