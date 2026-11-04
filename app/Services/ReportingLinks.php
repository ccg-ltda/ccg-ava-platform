<?php

namespace App\Services;

use App\Models\BookingRecord;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the references an engine sends (customer, location, conversation, reservation) INSIDE one Workspace. A reference that is not of that Workspace is
 * rejected exactly like one that does not exist, so a caller can neither link to nor learn about another Workspace's rows. The Workspace itself always comes
 * from the credential, never from the payload.
 */
class ReportingLinks
{
    /** @throws ValidationException */
    public function customer(Workspace $workspace, mixed $id): ?int
    {
        return $id === null ? null : $this->owned($workspace->customers()->whereKey($id)->value('id'), 'customer_id');
    }

    /** @throws ValidationException */
    public function location(Workspace $workspace, mixed $id): ?int
    {
        return $id === null ? null : $this->owned($workspace->locations()->whereKey($id)->value('id'), 'location_id');
    }

    /** @throws ValidationException the conversation of that channel and contact, among the Workspace's own */
    public function conversation(Workspace $workspace, ?string $channel, ?string $contact): ?int
    {
        if ($channel === null || $contact === null) {
            return null;
        }

        return $this->owned(Conversation::where('workspace_id', $workspace->id)->where('channel', $channel)->where('contact_id', $contact)->value('id'), 'contact_id');
    }

    /** @throws ValidationException */
    public function booking(Workspace $workspace, ?string $source, ?string $externalId): ?int
    {
        if ($externalId === null) {
            return null;
        }

        return $this->owned(BookingRecord::where('workspace_id', $workspace->id)->when($source, fn ($q) => $q->where('source', $source))->where('external_id', $externalId)->value('id'), 'booking_external_id');
    }

    /** @throws ValidationException */
    private function owned(mixed $id, string $field): int
    {
        if ($id === null) {
            throw ValidationException::withMessages([$field => 'La referencia no existe en este Workspace.']);
        }

        return (int) $id;
    }
}
