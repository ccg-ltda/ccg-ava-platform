<?php

namespace App\Services;

use App\Models\AgentToolCall;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\WorkspaceSetting;
use App\Orders\CommerceSchema;
use Illuminate\Support\Collection;

/**
 * What was done from a conversation, so the person who takes over can understand what happened without reading every message: the appointments and
 * the orders that tools of the assistant (or a person) tied to THIS conversation, with the state Ava has now. Read only. It follows the same
 * rules as the modules themselves: each kind shows only when the Workspace's plan includes the module AND the user has its permission, so reading a
 * conversation never opens a back door to bookings or orders. Nothing here is the contact's private content: no phone, no address, no notes.
 */
class ConversationOperations
{
    private const LIMIT = 10;

    private const TOOL_LIMIT = 15;

    public function __construct(private readonly ModuleAccess $modules, private readonly CommerceSchema $commerce, private readonly AgentToolTrace $trace) {}

    /**
     * @param  list<string>  $permissions  the permissions of the user in the ACTIVE Workspace
     * @return list<array<string, mixed>> newest first, at most LIMIT of each kind
     */
    public function for(Conversation $conversation, array $permissions, WorkspaceSetting $settings): array
    {
        return $this->appointments($conversation, $permissions, $settings)->concat($this->orders($conversation, $permissions, $settings))
            ->sortByDesc('at')->values()->map(fn (array $item) => array_diff_key($item, ['at' => true]))->all();
    }

    /**
     * The business tools the assistant ran through Ava in this conversation (newest first), as the trace recorded them: what was asked, whether it was done,
     * refused, failed or is unknown, and whether it was a retry. Nothing about the contact and nothing the assistant reasoned. Empty until the trace exists.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(Conversation $conversation, WorkspaceSetting $settings): array
    {
        if (! $this->trace->ready()) {
            return [];
        }

        return AgentToolCall::where('workspace_id', $conversation->workspace_id)->where('conversation_id', $conversation->id)->orderByDesc('id')->limit(self::TOOL_LIMIT)->get()
            ->map(fn (AgentToolCall $call) => [
                'id' => $call->id,
                'label' => config('chatbots.tools')[$call->tool]['label'] ?? $call->tool,
                'kind' => $call->kind,
                'status' => $call->status,
                'statusLabel' => config("chatbots.tool_statuses.{$call->status}"),
                'replayed' => $call->replayed,
                'when' => $settings->formatDate($call->created_at).' '.$settings->formatTime($call->created_at),
            ])->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function appointments(Conversation $conversation, array $permissions, WorkspaceSetting $settings): Collection
    {
        $workspace = $conversation->chatbot->workspace;

        if (! in_array('view-bookings', $permissions, true) || ! $this->modules->allows($workspace, 'bookings')) {
            return collect();
        }

        return $workspace->appointments()->where('conversation_id', $conversation->id)->orderByDesc('starts_at')->limit(self::LIMIT)->get()
            ->map(fn (Appointment $appointment) => [
                'kind' => 'appointment',
                'id' => $appointment->id,
                'title' => $appointment->service_name,
                'when' => $settings->formatDate($appointment->starts_at).' '.$settings->formatTime($appointment->starts_at),
                'status' => $appointment->status,
                'statusLabel' => Appointment::LABELS[$appointment->status],
                'detail' => $appointment->location_name,
                'at' => $appointment->created_at?->timestamp ?? 0,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function orders(Conversation $conversation, array $permissions, WorkspaceSetting $settings): Collection
    {
        $workspace = $conversation->chatbot->workspace;

        if (! in_array('view-orders', $permissions, true) || ! $this->modules->allows($workspace, 'orders') || ! $this->commerce->conversationLinkReady()) {
            return collect();
        }

        return $workspace->orders()->where('conversation_id', $conversation->id)->orderByDesc('number')->limit(self::LIMIT)->get()
            ->map(fn (Order $order) => [
                'kind' => 'order',
                'id' => $order->id,
                'title' => "Pedido #{$order->number}",
                'when' => $settings->formatDate($order->created_at).' '.$settings->formatTime($order->created_at),
                'status' => $order->status,
                'statusLabel' => config("orders.statuses.{$order->status}"),
                'detail' => number_format((float) $order->total, 2, '.', ',').' '.$order->currency,
                'at' => $order->created_at?->timestamp ?? 0,
            ]);
    }
}
