<?php

namespace App\Campaigns;

use App\Audit\AuditLogger;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that changes the status of a campaign or writes its recipients. Every step is a conditional update on the current
 * status, so a double click, two tabs or a repeated request can never prepare or simulate twice. Nothing here sends a message: the
 * most it does is `simulate`, a dry run that records what WOULD be addressed. Real delivery belongs to a CampaignDispatcher that does not exist yet.
 */
class CampaignManager
{
    public function __construct(private readonly CampaignAudience $audience, private readonly MessageTemplates $templates, private readonly AuditLogger $audit) {}

    /**
     * Creates or edits a draft. A prepared, simulated or cancelled campaign is history and is never edited.
     *
     * @param  array<string, mixed>  $data  name, message_template_id, body, channel, audience, filters, customer_ids
     */
    public function saveDraft(Workspace $workspace, array $data, ?Campaign $campaign, ?User $user): Campaign
    {
        if ($campaign && ! $campaign->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Solo se puede editar una campaña en borrador.']);
        }

        $campaign ??= new Campaign(['workspace_id' => $workspace->id]);
        $campaign->workspace_id = $workspace->id;
        $campaign->created_by ??= $user?->id;
        $campaign->fill($data)->save();

        return $campaign;
    }

    /** Freezes the audience: one row per customer, excluded ones with their reason. Refused when nobody is eligible or the message is not valid. */
    public function prepare(Campaign $campaign): Campaign
    {
        if ($this->templates->unknown($campaign->body) !== []) {
            throw ValidationException::withMessages(['status' => 'El mensaje usa variables que Ava no conoce.']);
        }

        return DB::transaction(function () use ($campaign) {
            $claimed = Campaign::whereKey($campaign->id)->where('workspace_id', $campaign->workspace_id)->where('status', Campaign::DRAFT)
                ->update(['status' => Campaign::PREPARED, 'prepared_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages(['status' => 'La campaña ya no está en borrador.']);
            }

            $campaign->refresh();
            $eligible = 0;

            $this->audience->candidates($campaign)->orderBy('customers.id')->chunkById(500, function ($customers) use ($campaign, &$eligible) {
                $now = now();
                $rows = $customers->map(function (Customer $customer) use ($campaign, &$eligible, $now) {
                    $reason = $this->audience->exclusion($customer, $campaign);
                    $eligible += $reason === null ? 1 : 0;

                    return [
                        'workspace_id' => $campaign->workspace_id,
                        'campaign_id' => $campaign->id,
                        'customer_id' => $customer->id,
                        'status' => $reason === null ? CampaignRecipient::PENDING : CampaignRecipient::EXCLUDED,
                        'exclusion_reason' => $reason,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                CampaignRecipient::insert($rows);
            }, 'customers.id', 'id');

            if ($eligible === 0) {
                throw ValidationException::withMessages(['status' => 'Ningún cliente cumple las condiciones para recibir esta campaña. Revisa el consentimiento, los filtros y los datos que usa el mensaje.']);
            }

            $this->audit->record('prepared', 'campaign', $campaign->id, $campaign->name, [AuditLogger::change('Estado', 'Borrador', 'Preparada'), AuditLogger::change('Destinatarios elegibles', null, (string) $eligible)], $campaign->workspace);

            return $campaign;
        });
    }

    /** A dry run over a prepared campaign: marks the pending recipients as simulated. It sends NOTHING and does not touch any channel. */
    public function simulate(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign) {
            $claimed = Campaign::whereKey($campaign->id)->where('workspace_id', $campaign->workspace_id)->where('status', Campaign::PREPARED)
                ->update(['status' => Campaign::SIMULATED, 'simulated_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages(['status' => 'Solo se puede simular una campaña preparada, y una sola vez.']);
            }

            $count = CampaignRecipient::where('campaign_id', $campaign->id)->where('workspace_id', $campaign->workspace_id)->where('status', CampaignRecipient::PENDING)
                ->update(['status' => CampaignRecipient::SIMULATED, 'processed_at' => now()]);

            $this->audit->record('simulated', 'campaign', $campaign->id, $campaign->name, [AuditLogger::change('Estado', 'Preparada', 'Simulada'), AuditLogger::change('Destinatarios simulados', null, (string) $count)], $campaign->workspace);

            return $campaign->refresh();
        });
    }

    /** Drops a draft or prepared campaign (it stays in the history). */
    public function cancel(Campaign $campaign): Campaign
    {
        $before = $campaign->status;
        $claimed = Campaign::whereKey($campaign->id)->where('workspace_id', $campaign->workspace_id)->whereIn('status', [Campaign::DRAFT, Campaign::PREPARED])
            ->update(['status' => Campaign::CANCELLED, 'cancelled_at' => now()]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages(['status' => 'Solo se puede cancelar una campaña en borrador o preparada.']);
        }

        $this->audit->record('cancelled', 'campaign', $campaign->id, $campaign->name, [AuditLogger::change('Estado', config("campaigns.statuses.{$before}"), 'Cancelada')], $campaign->workspace);

        return $campaign->refresh();
    }
}
