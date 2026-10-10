<?php

namespace App\Services;

use App\Audit\AuditPresenter;
use App\Integrations\IntegrationRegistry;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the Dashboard, computed from the tables the application really keeps (conversations, messages,
 * chatbots, memberships, integrations, executions and the audit log). It has no presentation and no permission checks:
 * the controller decides WHICH of these a user may ask for, and it only ever passes the Workspace that
 * `WorkspaceScope` resolved. Every query starts from `$target`: one Workspace, or null for "all" (a platform administrator
 * only). Nothing here invents a number: what has no source is simply not computed, and a count of zero is a real zero.
 *
 * The period is the one Reportes uses (`ReportPeriod`), so both pages agree; the other pages can call these methods too.
 */
class DashboardMetrics
{
    public function __construct(
        private readonly ChannelCatalog $channels,
        private readonly WorkspaceReport $report,
        private readonly IntegrationRegistry $integrationTypes,
    ) {}

    /**
     * Headline figures. Period figures come with those of the previous period of the same length (null change when
     * there is nothing to compare against); the others are the state right now.
     *
     * @return array<string, mixed>
     */
    public function kpis(?Workspace $target, ReportPeriod $period): array
    {
        $days = (int) config("reports.periods.{$period->key}.days");
        $now = $this->activity($target, $period->from, $period->to);
        $before = $this->activity($target, $period->from->subDays($days), $period->from->subSecond());

        return [
            'conversations' => $this->compare($now['conversations'], $before['conversations']),
            'messages' => $this->compare($now['received'] + $now['sent'], $before['received'] + $before['sent']),
            'messagesIn' => $now['received'],
            'byAi' => $now['ai'],
            'byAgents' => $now['agents'],
            'open' => $this->conversations($target)->where('handling', '!=', Conversation::RESOLVED)->count(),
            'pending' => $this->conversations($target)->where('handling', Conversation::PENDING)->count(),
            'assistants' => [
                'total' => $this->within(Chatbot::query(), $target)->count(),
                'active' => $this->within(Chatbot::query(), $target)->where('is_active', true)->count(),
            ],
            'users' => $this->report->memberCount($target),
        ];
    }

    /**
     * Conversations started, messages received and replies sent per bucket of the period. Counted per hour in the
     * database (cheap whatever the volume) and added up into the buckets in the Workspace's timezone; every timezone
     * offered by the application is a whole number of hours, so the hour never straddles two days.
     *
     * @return array{total: int, points: list<array<string, mixed>>}
     */
    public function series(?Workspace $target, ReportPeriod $period, WorkspaceSetting $settings): array
    {
        $created = $this->hourly($this->conversations($target), 'created_at', $period);
        $received = $this->hourly($this->messages($target)->where('direction', 'in'), 'sent_at', $period);
        $sent = $this->hourly($this->messages($target)->where('direction', 'out'), 'sent_at', $period);

        $bucket = fn (array $hours) => collect($hours)->reduce(function (array $carry, int $total, string $hour) use ($period) {
            $key = $period->bucketStart(CarbonImmutable::parse($hour, 'UTC'))->toDateString();
            $carry[$key] = ($carry[$key] ?? 0) + $total;

            return $carry;
        }, []);
        [$created, $received, $sent] = [$bucket($created), $bucket($received), $bucket($sent)];

        $points = collect($period->buckets())->map(fn (CarbonImmutable $start) => [
            'key' => $start->toDateString(),
            'label' => match ($period->granularity) {
                'month' => $start->locale('es')->isoFormat('MMM YYYY'),
                'week' => 'Semana del '.$settings->formatDate($start),
                default => $settings->formatDate($start),
            },
            'short' => $period->granularity === 'month' ? $start->locale('es')->isoFormat('MMM') : $start->format('d/m'),
            'values' => [
                'conversations' => $created[$start->toDateString()] ?? 0,
                'received' => $received[$start->toDateString()] ?? 0,
                'sent' => $sent[$start->toDateString()] ?? 0,
            ],
        ])->all();

        return ['total' => array_sum($created) + array_sum($received) + array_sum($sent), 'points' => $points];
    }

    /**
     * Who has the conversations that had activity in the period, as they are NOW (every state is present, even at zero).
     *
     * @return list<array{key: string, label: string, value: int}>
     */
    public function states(?Workspace $target, ReportPeriod $period): array
    {
        $counts = $this->conversations($target)->whereBetween('last_message_at', $this->range($period->from, $period->to))
            ->selectRaw('handling, count(*) as total')->groupBy('handling')->pluck('total', 'handling');

        return collect(Conversation::HANDLING)->map(fn (string $state) => [
            'key' => $state,
            'label' => Conversation::LABELS[$state],
            'value' => (int) ($counts[$state] ?? 0),
        ])->all();
    }

    /**
     * Conversations started in the period, by channel.
     *
     * @return list<array{key: string, label: string, value: int}>
     */
    public function channels(?Workspace $target, ReportPeriod $period): array
    {
        return $this->conversations($target)->whereBetween('created_at', $this->range($period->from, $period->to))
            ->selectRaw('channel, count(*) as total')->groupBy('channel')->orderByDesc('total')->pluck('total', 'channel')
            ->map(fn ($total, $channel) => ['key' => $channel, 'label' => config("chatbots.channels.{$channel}.label", $channel), 'value' => (int) $total])->values()->all();
    }

    /**
     * What needs a person: conversations waiting for an agent, replies nobody could confirm, and executions of an
     * assistant that failed for good in the period. Each is a real count; zero means nothing is waiting.
     *
     * @return array<string, int>
     */
    public function attention(?Workspace $target, ReportPeriod $period, bool $conversations, bool $assistants): array
    {
        return array_filter([
            'pending' => $conversations ? $this->conversations($target)->where('handling', Conversation::PENDING)->count() : null,
            'unconfirmed' => $conversations ? $this->messages($target)->where('status', Message::UNCONFIRMED)->count() : null,
            'failedExecutions' => $assistants ? $this->within(ChatbotExecution::query(), $target)->where('status', ChatbotExecution::FAILED)->whereBetween('created_at', $this->range($period->from, $period->to))->count() : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The connections of ONE Workspace and what Ava can honestly say about each: `verified` only when the last real
     * test succeeded (a test is a request to the provider, never a promise that it works now), `failed` when it failed,
     * `untested` when it was never tested, `configured` for a type that cannot be tested, `inactive` when it is off.
     * No credential is ever selected.
     *
     * @return list<array<string, mixed>>
     */
    public function integrations(Workspace $target, WorkspaceSetting $settings): array
    {
        return Integration::query()->where('workspace_id', $target->id)
            ->get(['id', 'name', 'type', 'is_active', 'last_tested_at', 'last_test_ok'])
            ->map(function (Integration $integration) use ($settings) {
                $type = $this->integrationTypes->get($integration->type);
                $state = match (true) {
                    ! $integration->is_active => 'inactive',
                    ! $type->supportsTest() => 'configured',
                    $integration->last_tested_at === null => 'untested',
                    $integration->last_test_ok === true => 'verified',
                    default => 'failed',
                };

                return [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'type' => $type->label(),
                    'state' => $state,
                    'testedAt' => $integration->last_tested_at ? $settings->formatDate($integration->last_tested_at).' '.$settings->formatTime($integration->last_tested_at) : null,
                ];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * The latest conversations by activity. `$withWorkspace` adds the Workspace name (for the "all" view).
     *
     * @return list<array<string, mixed>>
     */
    public function recentConversations(?Workspace $target, WorkspaceSetting $settings, int $limit = 6): array
    {
        return $this->conversations($target)->with('chatbot:id,name,workspace_id')->when(! $target, fn (Builder $query) => $query->with('chatbot.workspace:id,name'))
            ->whereNotNull('last_message_at')->orderByDesc('last_message_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'contact' => $conversation->contact_name ?: $conversation->contact_id,
                'channel' => config("chatbots.channels.{$conversation->channel}.label", $conversation->channel),
                'handling' => $conversation->handling,
                'assistant' => $conversation->chatbot->name,
                'workspaceId' => $conversation->workspace_id,
                'workspace' => $target ? null : $conversation->chatbot->workspace->name,
                'lastAt' => $settings->formatDate($conversation->last_message_at).' '.$settings->formatTime($conversation->last_message_at),
            ])->all();
    }

    /**
     * The latest administrative events (the audit log: who changed what). Only for who may read the audit page.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(?Workspace $target, WorkspaceSetting $settings, int $limit = 6): array
    {
        $present = new AuditPresenter($settings);

        return AuditLog::query()->when($target, fn (Builder $query) => $query->where('workspace_id', $target->id))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AuditLog $log) => collect($present->present($log))->only(['id', 'action', 'actionLabel', 'description', 'module', 'date', 'time'])->all()
                + ['user' => $log->user_name, 'workspace' => $target ? null : $log->workspace_name])->all();
    }

    /** Conversations of the DEMO environment inside the scope: the Dashboard says so, because they are simulated. */
    public function demoConversations(?Workspace $target): int
    {
        return $this->conversations($target)->whereNotNull('demo_key')->count();
    }

    /** @return array{conversations: int, received: int, sent: int, ai: int, agents: int} */
    private function activity(?Workspace $target, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $messages = $this->messages($target)->whereBetween('sent_at', $this->range($from, $to))
            ->selectRaw('direction, sender, count(*) as total')->groupBy('direction', 'sender')->get();
        $sum = fn (callable $matches) => (int) $messages->filter($matches)->sum('total');

        return [
            'conversations' => $this->conversations($target)->whereBetween('created_at', $this->range($from, $to))->count(),
            'received' => $sum(fn ($row) => $row->direction === 'in'),
            'sent' => $sum(fn ($row) => $row->direction === 'out'),
            'ai' => $sum(fn ($row) => $row->direction === 'out' && $row->sender === 'ai'),
            'agents' => $sum(fn ($row) => $row->direction === 'out' && $row->sender === 'agent'),
        ];
    }

    /** @return array{value: int, previous: int, change: ?int} change in whole percent, null without a base to compare */
    private function compare(int $value, int $previous): array
    {
        return ['value' => $value, 'previous' => $previous, 'change' => $previous > 0 ? (int) round(($value - $previous) / $previous * 100) : null];
    }

    /** @return array<string, int> hour (UTC, `Y-m-d H:00:00`) => rows created in it */
    private function hourly(Builder $query, string $column, ReportPeriod $period): array
    {
        $hour = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m-%d %H:00:00', {$column})" : "to_char({$column}, 'YYYY-MM-DD HH24:00:00')";

        return $query->whereBetween($column, $this->range($period->from, $period->to))
            ->selectRaw("{$hour} as hour, count(*) as total")->groupBy(DB::raw($hour))->pluck('total', 'hour')
            ->map(fn ($total) => (int) $total)->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->utc(), $to->utc()];
    }

    /** @return Builder<Conversation> */
    private function conversations(?Workspace $target): Builder
    {
        return $this->within(Conversation::query(), $target);
    }

    /** @return Builder<Message> */
    private function messages(?Workspace $target): Builder
    {
        return $this->within(Message::query(), $target);
    }

    /**
     * Restricts a query to the Workspace (every table here has `workspace_id`); null leaves it unrestricted, which only
     * the controller may ask for after `WorkspaceScope` approved an administrator of the platform.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private function within(Builder $query, ?Workspace $target): Builder
    {
        return $target ? $query->where($query->getModel()->getTable().'.workspace_id', $target->id) : $query;
    }
}
