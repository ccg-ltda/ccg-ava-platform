<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Workspace;
use App\Reports\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The figures of Reportes for the operational modules (Agenda, Pedidos and Clientes), computed from the tables those modules keep and from nothing else.
 * Like DashboardMetrics it has no presentation and no permission checks: the controller decides who may ask and passes ONE Workspace (the one
 * WorkspaceScope resolved), and every query starts from it, so a figure can never include another Workspace. A count of zero is a real zero; a figure that
 * cannot be computed honestly (money in two currencies, a price nobody recorded) is left out or flagged, never invented.
 */
class OperationsMetrics
{
    /**
     * Appointments whose start falls inside the period, by state, service, professional and way in, plus what is still ahead. `recordedValue` is the sum of
     * the price recorded on the appointments that were carried out; appointments of a service without price add nothing (and are counted separately).
     *
     * @return array<string, mixed>
     */
    public function bookings(Workspace $workspace, ReportPeriod $period): array
    {
        $inPeriod = fn () => Appointment::query()->where('workspace_id', $workspace->id)->whereBetween('starts_at', [$period->from, $period->to]);
        $byStatus = $inPeriod()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $completed = $inPeriod()->where('status', Appointment::COMPLETED);
        $priced = (clone $completed)->whereNotNull('price_amount');

        return [
            'kpis' => [
                'total' => (int) $byStatus->sum(),
                'completed' => (int) ($byStatus[Appointment::COMPLETED] ?? 0),
                'cancelled' => (int) ($byStatus[Appointment::CANCELLED] ?? 0),
                'noShow' => (int) ($byStatus[Appointment::NO_SHOW] ?? 0),
                'upcoming' => Appointment::query()->where('workspace_id', $workspace->id)->where('starts_at', '>', $period->to)->whereIn('status', Appointment::BLOCKING)->count(),
                'recordedValue' => round((float) (clone $priced)->sum('price_amount'), 2),
                'withoutPrice' => (clone $completed)->whereNull('price_amount')->count(),
                'currency' => (clone $priced)->value('price_currency') ?? $workspace->settingsOrDefault()->currency,
            ],
            'byStatus' => collect(Appointment::LABELS)->map(fn (string $label, string $status) => ['key' => $status, 'label' => $label, 'value' => (int) ($byStatus[$status] ?? 0)])->values()->all(),
            'byService' => $this->ranking($inPeriod()->whereNotIn('status', [Appointment::CANCELLED]), 'service_name'),
            'byProfessional' => $this->ranking($inPeriod()->whereNotIn('status', [Appointment::CANCELLED])->whereNotNull('professional_name'), 'professional_name'),
            'byOrigin' => $inPeriod()->selectRaw('channel, count(*) as total')->groupBy('channel')->orderByDesc('total')->pluck('total', 'channel')
                ->map(fn ($total, $channel) => ['key' => $channel, 'label' => ['admin' => 'Agenda del negocio', 'web' => 'Página de reservas', 'chat' => 'Asistente'][$channel] ?? $channel, 'value' => (int) $total])->values()->all(),
        ];
    }

    /**
     * Orders created inside the period. Sales are the orders that were not cancelled; the average ticket is computed only over those, and when the orders of the
     * period were taken in more than one currency no total is given (adding them would be meaningless).
     *
     * @return array<string, mixed>
     */
    public function orders(Workspace $workspace, ReportPeriod $period): array
    {
        $inPeriod = fn () => Order::query()->where('workspace_id', $workspace->id)->whereBetween('created_at', [$period->from, $period->to]);
        $byStatus = $inPeriod()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $sold = fn () => $inPeriod()->where('status', '!=', 'cancelled');
        $currencies = $sold()->distinct()->pluck('currency');
        $soldCount = $sold()->count();
        $single = $currencies->count() <= 1;

        return [
            'kpis' => [
                'total' => (int) $byStatus->sum(),
                'delivered' => (int) ($byStatus['delivered'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                'open' => (int) $byStatus->except(config('orders.terminal'))->sum(),
                'sales' => $single ? round((float) $sold()->sum('total'), 2) : null,
                'averageTicket' => $single && $soldCount > 0 ? round((float) $sold()->sum('total') / $soldCount, 2) : null,
                'currency' => $currencies->first() ?? $workspace->settingsOrDefault()->currency,
                'mixedCurrencies' => ! $single,
            ],
            'byStatus' => collect(config('orders.statuses'))->map(fn (string $label, string $status) => ['key' => $status, 'label' => $label, 'value' => (int) ($byStatus[$status] ?? 0)])->values()->all(),
            'byMethod' => $inPeriod()->selectRaw('delivery_method, count(*) as total')->groupBy('delivery_method')->orderByDesc('total')->pluck('total', 'delivery_method')
                ->map(fn ($total, $method) => ['key' => $method, 'label' => config("orders.methods.{$method}", $method), 'value' => (int) $total])->values()->all(),
            // Units sold of each product (what the order kept: its name at that moment), over the orders that were not cancelled.
            'topProducts' => DB::table('order_lines')
                ->join('orders', fn ($join) => $join->on('orders.id', '=', 'order_lines.order_id')->on('orders.workspace_id', '=', 'order_lines.workspace_id'))
                ->where('orders.workspace_id', $workspace->id)->where('orders.status', '!=', 'cancelled')->whereBetween('orders.created_at', [$period->from, $period->to])
                ->selectRaw('order_lines.product_name as name, sum(order_lines.quantity) as units')->groupBy('order_lines.product_name')->orderByDesc('units')->orderBy('name')->limit(6)->get()
                ->map(fn ($row) => ['key' => $row->name, 'label' => $row->name, 'value' => (int) $row->units])->all(),
        ];
    }

    /**
     * The customers of the Workspace: how many there are now, how many joined in the period, and by consent and origin. Contact data never leaves here.
     *
     * @return array<string, mixed>
     */
    public function customers(Workspace $workspace, ReportPeriod $period): array
    {
        $all = fn () => Customer::query()->where('workspace_id', $workspace->id);
        $consent = $all()->selectRaw('marketing_consent, count(*) as total')->groupBy('marketing_consent')->pluck('total', 'marketing_consent');

        return [
            'kpis' => [
                'total' => $all()->count(),
                'active' => $all()->where('is_active', true)->count(),
                'new' => $all()->whereBetween('created_at', [$period->from, $period->to])->count(),
                'reachable' => $all()->where('is_active', true)->where('marketing_consent', Customer::CONSENT_GRANTED)->count(),
            ],
            'byConsent' => collect(config('customers.consents'))->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => (int) ($consent[$key] ?? 0)])->values()->all(),
            'bySource' => $all()->selectRaw('source, count(*) as total')->groupBy('source')->orderByDesc('total')->pluck('total', 'source')
                ->map(fn ($total, $source) => ['key' => $source, 'label' => config("customers.sources.{$source}", $source), 'value' => (int) $total])->values()->all(),
        ];
    }

    /** @return list<array{key: string, label: string, value: int}> the six most frequent values of a column */
    private function ranking($query, string $column): array
    {
        return $query->selectRaw("{$column} as name, count(*) as total")->groupBy($column)->orderByDesc('total')->orderBy($column)->limit(6)->get()
            ->map(fn ($row) => ['key' => (string) $row->name, 'label' => (string) $row->name, 'value' => (int) $row->total])->all();
    }
}
