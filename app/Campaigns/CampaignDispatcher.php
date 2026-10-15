<?php

namespace App\Campaigns;

use App\Models\Campaign;

/**
 * The integration point for REAL delivery of a campaign (WhatsApp, email, through the architecture of Ava and n8n). Nothing implements
 * it for real yet: `UnavailableCampaignDispatcher` is bound, says why and sends nothing. A real implementation must be added together
 * with its connection test against the real service; until then no campaign may become "sent".
 */
interface CampaignDispatcher
{
    /** Whether this campaign can be delivered for real and, when it cannot, a fixed human-readable reason. @return array{available: bool, reason: ?string} */
    public function availability(Campaign $campaign): array;
}
