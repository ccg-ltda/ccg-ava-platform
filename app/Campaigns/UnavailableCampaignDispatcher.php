<?php

namespace App\Campaigns;

use App\Models\Campaign;

/** The dispatcher of today: no outbound channel for campaigns is connected, so nothing is ever sent and the interface says it. */
class UnavailableCampaignDispatcher implements CampaignDispatcher
{
    public function availability(Campaign $campaign): array
    {
        return ['available' => false, 'reason' => 'El envío real de campañas todavía no está conectado. Puedes preparar y simular la campaña; no sale ningún mensaje.'];
    }
}
