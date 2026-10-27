<?php

namespace App\Services;

use App\Models\ChatbotChannel;
use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What can honestly be said about the web chat of a Workspace, stage by stage. Each stage is shown as done only when it was OBSERVED:
 *  1. configured: the web integration is saved and switched on;
 *  2. widget loaded: the widget asked for its configuration from a page that is NOT this application (the Origin header names another site). The preview inside Zonno does
 *     not count, and neither does a request without an Origin. It is remembered in the cache, so it is lost when the cache is flushed (the stage then reads pending again);
 *  3. conversation started: a visitor's message really arrived (not a simulated one);
 *  4. reply delivered: the assistant or a person answered that visitor and the answer was not marked as failed.
 * «Conectado» needs all four.
 */
class WebChannelConnection
{
    private const CACHE = 'ava:web-widget-loaded:';

    /** Called when a widget asks for its configuration: remembers (at most every 10 minutes per channel) that it was seen on another site. */
    public function widgetSeen(ChatbotChannel $channel, ?string $origin): void
    {
        $host = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : null;
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '' || strcasecmp($host, (string) $own) === 0) {
            return;
        }

        if (Cache::add(self::CACHE.'throttle:'.$channel->id, 1, 600)) {
            Cache::forever(self::CACHE.$channel->id, now()->timestamp);
        }
    }

    public function loadedAt(Workspace $workspace): ?int
    {
        $ids = ChatbotChannel::where('workspace_id', $workspace->id)->where('channel', 'web')->pluck('id');
        $latest = $ids->map(fn ($id) => Cache::get(self::CACHE.$id))->filter()->max();

        return $latest === null ? null : (int) $latest;
    }

    /**
     * @return array{state: string, label: string, tone: string, webhook: null, stages: list<array{key: string, label: string, done: bool, detail: string}>}
     */
    public function status(Workspace $workspace, ?Integration $integration): array
    {
        $configured = $integration !== null && $integration->is_active;
        $loaded = $configured ? $this->loadedAt($workspace) : null;
        $conversation = $this->conversationStarted($workspace);
        $reply = $this->replyDelivered($workspace);

        $stages = [
            ['key' => 'configured', 'label' => 'Canal web configurado', 'done' => $configured,
                'detail' => $configured ? 'La integración web está guardada y activa.' : 'Falta guardar y activar la integración web.'],
            ['key' => 'widget_loaded', 'label' => 'Widget cargado en un sitio', 'done' => $loaded !== null,
                'detail' => $loaded !== null ? 'Un sitio externo ya cargó el widget.' : 'Pendiente: pega el script en tu sitio y ábrelo. La vista previa dentro de Zonno no cuenta, y este dato se pierde si se vacía la caché del servidor.'],
            ['key' => 'conversation', 'label' => 'Conversación iniciada', 'done' => $conversation,
                'detail' => $conversation ? 'Ya llegó al menos un mensaje real de un visitante.' : 'Pendiente: escribe un mensaje desde el widget de tu sitio.'],
            ['key' => 'reply', 'label' => 'Respuesta entregada al visitante', 'done' => $reply,
                'detail' => $reply ? 'El asistente o una persona ya respondió a un visitante.' : 'Pendiente: que el asistente (n8n) o una persona responda en esa conversación.'],
        ];

        [$state, $label, $tone] = match (true) {
            ! $configured => ['not_configured', 'Sin configurar', 'amber'],
            $conversation && $reply && $loaded !== null => ['connected', 'Widget con conversaciones y respuestas', 'green'],
            $reply => ['partial', 'Respuestas entregadas · widget sin registrar en un sitio', 'amber'],
            $conversation => ['partial', 'Conversación iniciada · falta una respuesta', 'amber'],
            $loaded !== null => ['partial', 'Widget cargado · esperando una conversación', 'amber'],
            default => ['pending', 'Configurado · el widget aún no se ha cargado en un sitio', 'blue'],
        };

        return ['state' => $state, 'label' => $label, 'tone' => $tone, 'webhook' => null, 'stages' => $stages];
    }

    private function conversationStarted(Workspace $workspace): bool
    {
        return DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.workspace_id', $workspace->id)->where('conversations.channel', 'web')
            ->where('messages.direction', 'in')->where('messages.simulated', false)->exists();
    }

    private function replyDelivered(Workspace $workspace): bool
    {
        return DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.workspace_id', $workspace->id)->where('conversations.channel', 'web')
            ->where('messages.direction', 'out')->where('messages.simulated', false)->whereNotIn('messages.sender', ['contact'])
            ->where(fn ($q) => $q->whereNull('messages.status')->orWhere('messages.status', '!=', 'failed'))->exists();
    }
}
