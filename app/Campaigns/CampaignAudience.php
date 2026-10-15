<?php

namespace App\Campaigns;

use App\Customers\CustomerSearch;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a campaign addresses and who is left out, with the reason. The one rule for eligibility (preview, preparation and tests all
 * ask it): the customer must be active, have granted marketing consent (unknown counts as NO, revoked is an opt-out), have the contact
 * datum the channel needs and have every datum the message uses.
 */
class CampaignAudience
{
    public function __construct(private readonly CustomerSearch $search, private readonly MessageTemplates $templates) {}

    /** The customers the campaign points at, before eligibility: every customer of the filters, or the ones picked by hand. Always of its Workspace. */
    public function candidates(Campaign $campaign): Builder
    {
        $query = $this->search->query($campaign->workspace, $campaign->audience === 'selected' ? [] : ($campaign->filters ?? []));

        return $campaign->audience === 'selected' ? $query->whereIn('customers.id', $campaign->customer_ids ?? []) : $query;
    }

    /** @return ?string a key of config/campaigns.php `exclusions`, or null when the customer is eligible */
    public function exclusion(Customer $customer, Campaign $campaign): ?string
    {
        $requires = config("campaigns.channels.{$campaign->channel}.requires");

        return match (true) {
            ! $customer->is_active => 'inactive',
            $customer->marketing_consent === Customer::CONSENT_REVOKED => 'opted_out',
            $customer->marketing_consent !== Customer::CONSENT_GRANTED => 'no_consent',
            ($requires === 'phone' && $customer->phone_normalized === null) || ($requires === 'email' && $customer->email_normalized === null) => 'no_contact',
            $this->templates->missingFor($customer, $campaign->body) !== [] => 'missing_variable',
            default => null,
        };
    }

    /**
     * Counts of the audience as it stands now (what "Revisar" shows before confirming).
     *
     * @return array{total: int, eligible: int, excluded: array<string, int>}
     */
    public function summary(Campaign $campaign): array
    {
        $summary = ['total' => 0, 'eligible' => 0, 'excluded' => []];

        $this->candidates($campaign)->orderBy('customers.id')->chunkById(500, function ($customers) use ($campaign, &$summary) {
            foreach ($customers as $customer) {
                $summary['total']++;

                if ($reason = $this->exclusion($customer, $campaign)) {
                    $summary['excluded'][$reason] = ($summary['excluded'][$reason] ?? 0) + 1;
                } else {
                    $summary['eligible']++;
                }
            }
        }, 'customers.id', 'id');

        return $summary;
    }
}
