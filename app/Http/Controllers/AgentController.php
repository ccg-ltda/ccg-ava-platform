<?php

namespace App\Http\Controllers;

use App\Integrations\ChannelIntegrationType;
use App\Integrations\IntegrationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What an automation (n8n) may read: the configuration of the chatbot its token belongs to, and nothing else. The
 * chatbot and its Workspace come from the token (see AuthenticateAgent), never from the request. Ava is the single
 * source of the chatbot's behavior: the workflow takes its instructions from here instead of keeping its own copy.
 */
class AgentController extends Controller
{
    public function config(Request $request, IntegrationRegistry $types): JsonResponse
    {
        $chatbot = $request->attributes->get('chatbot');
        $workspace = $request->attributes->get('workspace');

        // Only channels that are switched on; each adds what the automation may know about its account (never a credential).
        $channels = $chatbot->channels()->where('is_active', true)->with('integration')->get()
            ->mapWithKeys(function ($channel) use ($types) {
                $type = $channel->integration ? $types->get($channel->integration->type) : null;

                return [$channel->channel => $type instanceof ChannelIntegrationType ? $type->agentView($channel->integration) : (object) []];
            });

        return response()->json([
            'chatbot' => [
                'id' => $chatbot->id,
                'name' => $chatbot->name,
                'description' => $chatbot->description,
                'instructions' => $chatbot->instructions ?? '',
                'updated_at' => $chatbot->updated_at?->toIso8601String(),
            ],
            'workspace' => ['code' => $workspace->code, 'name' => $workspace->name],
            'channels' => $channels->isEmpty() ? (object) [] : $channels,
        ])->header('Cache-Control', 'no-store');
    }
}
