<?php

namespace App\Http\Controllers;

use App\Integrations\ChannelIntegrationType;
use App\Integrations\IntegrationRegistry;
use App\Services\ChatbotInstructions;
use App\Services\ModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What an automation (n8n) may read: the configuration of the chatbot its token belongs to, and nothing else. The
 * chatbot and its Workspace come from the token (see AuthenticateAgent), never from the request. Ava is the single
 * source of the chatbot's behavior: the workflow takes its instructions from here instead of keeping its own copy.
 */
class AgentController extends Controller
{
    public function config(Request $request, IntegrationRegistry $types, ModuleAccess $modules): JsonResponse
    {
        $chatbot = $request->attributes->get('chatbot');
        $workspace = $request->attributes->get('workspace');

        // Only channels that are switched on; each adds what the automation may know about its account (never a credential).
        $channels = $chatbot->channels()->where('is_active', true)->with('integration')->get()
            ->mapWithKeys(function ($channel) use ($types) {
                $type = $channel->integration ? $types->get($channel->integration->type) : null;

                return [$channel->channel => $type instanceof ChannelIntegrationType ? $type->agentView($channel->integration) : (object) []];
            });

        // n8n just read this version: that is the confirmation that the workflow has it (see ChatbotInstructions::status).
        if (ChatbotInstructions::ready()) {
            app(ChatbotInstructions::class)->markSynced($chatbot->id, (int) $chatbot->instructions_version);
        }

        return response()->json([
            'chatbot' => [
                'id' => $chatbot->id,
                'name' => $chatbot->name,
                'description' => $chatbot->description,
                // The four blocks joined (ChatbotInstructions): the same text every execution carries. `instructions_version` lets the workflow know which one it has.
                'instructions' => app(ChatbotInstructions::class)->effective($chatbot),
                'instructions_version' => ChatbotInstructions::ready() ? (int) $chatbot->instructions_version : null,
                // `single` (one prompt) or `modular` (three blocks). `instructions` is always the complete text of the ACTIVE mode, so a workflow written before the modes keeps working;
                // `instruction_blocks` gives the modular blocks apart, in order, for a workflow that wants them separately.
                'instructions_mode' => app(ChatbotInstructions::class)->mode($chatbot),
                'instruction_blocks' => app(ChatbotInstructions::class)->activeBlocks($chatbot),
                'updated_at' => $chatbot->updated_at?->toIso8601String(),
            ],
            'workspace' => ['code' => $workspace->code, 'name' => $workspace->name],
            // What the assistant may never do and what Ava does not do yet: the same lines that travel with every execution.
            'guidelines' => config('chatbots.guidelines'),
            'channels' => $channels->isEmpty() ? (object) [] : $channels,
            // The booking tools exist only for a Workspace whose plan includes Servicios y Reservas: n8n discovers them here.
            'booking' => $modules->allowsAll($workspace, ['service_catalog', 'professionals', 'bookings']) ? [
                'available' => true,
                'endpoints' => collect(['services', 'locations', 'professionals', 'availability', 'appointments'])->mapWithKeys(fn (string $name) => [$name => url("/api/agent/booking/{$name}")])->all(),
            ] : ['available' => false],
            // The order tools exist only for a Workspace whose plan includes the catalog and Pedidos. The assistant sends products and quantities, never prices or totals.
            'orders' => $modules->allowsAll($workspace, ['product_catalog', 'orders']) ? [
                'available' => true,
                'endpoints' => collect(['options', 'products', 'orders'])->mapWithKeys(fn (string $name) => [$name => url($name === 'orders' ? '/api/agent/orders' : "/api/agent/orders/{$name}")])->all(),
            ] : ['available' => false],
        ])->header('Cache-Control', 'no-store');
    }
}
