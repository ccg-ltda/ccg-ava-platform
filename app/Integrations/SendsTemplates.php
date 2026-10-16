<?php

namespace App\Integrations;

use App\Models\Integration;

/**
 * An integration type that can send an APPROVED TEMPLATE message (the only kind WhatsApp lets a business start outside the contact's 24 h window).
 * Ava cannot ask Meta whether a template is approved: it sends the one the Workspace declared and reports Meta's refusal as a fixed reason.
 */
interface SendsTemplates
{
    /**
     * `$parameters` fill the body variables in order. Never throws for a refused or failed delivery; same outcome semantics as SendsMessages::send.
     *
     * @param  list<string>  $parameters
     */
    public function sendTemplate(Integration $integration, string $to, string $template, string $language, array $parameters, ?string $reference = null): SendResult;
}
