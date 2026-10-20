<?php

namespace App\Http\Controllers;

use App\Campaigns\CampaignAudience;
use App\Campaigns\CampaignDispatcher;
use App\Campaigns\CampaignManager;
use App\Campaigns\MessageTemplates;
use App\Customers\CustomerSearch;
use App\Http\Requests\SaveCampaignRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\Workspace;
use App\Services\ListPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campañas: templates and campaigns of the active Workspace. A campaign is prepared (audience frozen and checked) and can be
 * simulated; nothing is sent (CampaignDispatcher). Every status change goes through CampaignManager.
 */
class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignManager $manager,
        private readonly CampaignAudience $audience,
        private readonly CampaignDispatcher $dispatcher,
        private readonly MessageTemplates $templates,
        private readonly CustomerSearch $search,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $page = ListPagination::paginate($workspace->campaigns()->withCount(['recipients as eligible_count' => fn ($q) => $q->where('status', '!=', CampaignRecipient::EXCLUDED)])->orderByDesc('id'), $request, 'campaigns');

        return Inertia::render('Campaigns/Index', [
            'templates' => $workspace->messageTemplates()->orderByDesc('is_active')->orderBy('name')->get()->map(fn (MessageTemplate $template) => [
                'id' => $template->id, 'name' => $template->name, 'category' => $template->category, 'body' => $template->body, 'isActive' => $template->is_active,
            ])->all(),
            'campaigns' => [
                'data' => $page->getCollection()->map(fn (Campaign $campaign) => [
                    'id' => $campaign->id, 'name' => $campaign->name, 'channel' => $campaign->channel, 'status' => $campaign->status,
                    'recipients' => $campaign->isDraft() ? null : $campaign->eligible_count, 'createdAt' => $campaign->created_at->toIso8601String(),
                ])->values()->all(),
                'meta' => ListPagination::meta($page),
            ],
            'perPageOptions' => ListPagination::OPTIONS,
            'variables' => collect(config('campaigns.variables'))->map(fn (array $variable, string $key) => ['key' => $key, 'label' => $variable['label']])->values()->all(),
            'availability' => $this->templates->availability($workspace),
            'sample' => $this->sample($workspace),
            'channels' => collect(config('campaigns.channels'))->map(fn (array $channel) => $channel['label'])->all(),
            'statuses' => config('campaigns.statuses'),
            'bodyMax' => config('campaigns.body_max'),
        ]);
    }

    public function show(Request $request, int $campaign): Response
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->campaigns()->with('workspace')->findOrFail($campaign);
        $editable = $model->isDraft();

        return Inertia::render('Campaigns/Show', [
            'campaign' => [
                'id' => $model->id, 'name' => $model->name, 'body' => $model->body, 'channel' => $model->channel, 'audience' => $model->audience, 'status' => $model->status,
                'templateId' => $model->message_template_id, 'filters' => $model->filters ?? [], 'customerIds' => $model->customer_ids ?? [],
                'preparedAt' => $model->prepared_at?->toIso8601String(), 'simulatedAt' => $model->simulated_at?->toIso8601String(), 'cancelledAt' => $model->cancelled_at?->toIso8601String(),
            ],
            'summary' => $editable ? $this->audience->summary($model) : $this->frozenSummary($model),
            'recipients' => fn () => $editable ? null : $this->recipientsPage($request, $model),
            'picker' => fn () => $model->status !== Campaign::CANCELLED && $model->audience === 'selected' ? $this->picker($request, $workspace, $model) : null,
            'delivery' => $this->dispatcher->availability($model),
            'templates' => $workspace->messageTemplates()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'body'])->all(),
            'availability' => $this->templates->availability($workspace),
            'sample' => $this->sample($workspace),
            'options' => [
                'channels' => collect(config('campaigns.channels'))->map(fn (array $channel) => $channel['label'])->all(),
                'sources' => config('customers.sources'),
                'identityChannels' => collect(config('customers.identity_channels'))->mapWithKeys(fn (string $channel) => [$channel => config("chatbots.channels.{$channel}.label", ucfirst($channel))])->all(),
                'tags' => $this->search->tags($workspace),
                'exclusions' => config('campaigns.exclusions'),
                'statuses' => config('campaigns.statuses'),
                'maxSelected' => config('campaigns.max_selected'),
                'bodyMax' => config('campaigns.body_max'),
            ],
            'perPageOptions' => ListPagination::OPTIONS,
        ]);
    }

    public function store(SaveCampaignRequest $request): RedirectResponse
    {
        $campaign = $this->manager->saveDraft($request->attributes->get('workspace'), $request->campaign(), null, $request->user());

        return redirect()->route('campaigns.show', $campaign->id)->with('success', 'Campaña creada como borrador');
    }

    public function update(SaveCampaignRequest $request, int $campaign): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->campaigns()->findOrFail($campaign);
        $wasDraft = $model->isDraft();
        $this->manager->saveDraft($workspace, $request->campaign(), $model, $request->user());

        return back()->with('success', $wasDraft ? 'Borrador guardado correctamente' : 'Campaña guardada como borrador: vuelve a prepararla para fijar los destinatarios');
    }

    public function prepare(Request $request, int $campaign): RedirectResponse
    {
        $this->manager->prepare($request->attributes->get('workspace')->campaigns()->with('workspace')->findOrFail($campaign));

        return back()->with('success', 'Campaña preparada: los destinatarios quedaron fijados y revisados');
    }

    public function simulate(Request $request, int $campaign): RedirectResponse
    {
        $this->manager->simulate($request->attributes->get('workspace')->campaigns()->with('workspace')->findOrFail($campaign));

        return back()->with('success', 'Simulación registrada. No se envió ningún mensaje.');
    }

    public function cancel(Request $request, int $campaign): RedirectResponse
    {
        $this->manager->cancel($request->attributes->get('workspace')->campaigns()->with('workspace')->findOrFail($campaign));

        return back()->with('success', 'Campaña cancelada');
    }

    /** A few customers to preview a message with: the variables are filled with their real data. @return list<array<string, mixed>> */
    private function sample(Workspace $workspace): array
    {
        return $workspace->customers()->where('is_active', true)->orderBy('id')->limit(5)->get()->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->fullName(),
            'values' => collect(array_keys(config('campaigns.variables')))->mapWithKeys(fn (string $variable) => [$variable => $this->templates->valueFor($customer, $variable)])->all(),
        ])->all();
    }

    /** What was fixed when the campaign was prepared. @return array{total: int, eligible: int, excluded: array<string, int>} */
    private function frozenSummary(Campaign $campaign): array
    {
        $rows = $campaign->recipients()->selectRaw('status, exclusion_reason, count(*) as total')->groupBy('status', 'exclusion_reason')->get();
        $excluded = $rows->where('status', CampaignRecipient::EXCLUDED)->mapWithKeys(fn ($row) => [$row->exclusion_reason => (int) $row->total])->all();

        return ['total' => (int) $rows->sum('total'), 'eligible' => (int) $rows->where('status', '!=', CampaignRecipient::EXCLUDED)->sum('total'), 'excluded' => $excluded];
    }

    /** @return array<string, mixed> */
    private function recipientsPage(Request $request, Campaign $campaign): array
    {
        $page = ListPagination::paginate($campaign->recipients()->with('customer:id,first_name,last_name')->orderBy('id'), $request, 'recipients');

        return [
            'data' => $page->getCollection()->map(fn (CampaignRecipient $recipient) => [
                'id' => $recipient->id, 'customerId' => $recipient->customer_id, 'name' => $recipient->customer->fullName(), 'status' => $recipient->status, 'reason' => $recipient->exclusion_reason, 'error' => $recipient->error,
            ])->values()->all(),
            'meta' => ListPagination::meta($page),
        ];
    }

    /** The customers a person can pick by hand: those of the Workspace matching the text, each with whether the campaign would reach them. @return array<string, mixed> */
    private function picker(Request $request, Workspace $workspace, Campaign $campaign): array
    {
        $search = trim((string) $request->query('pick', ''));
        $page = ListPagination::paginate($this->search->query($workspace, ['search' => $search])->orderBy('first_name')->orderBy('id'), $request, 'picker');

        return [
            'search' => $search,
            'data' => $page->getCollection()->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => $customer->fullName(), 'phone' => $customer->phone, 'email' => $customer->email, 'reason' => $this->audience->exclusion($customer, $campaign)])->values()->all(),
            'meta' => ListPagination::meta($page),
        ];
    }
}
