<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A survey sent to a customer. Written only by App\Services\SurveyRecorder. */
#[Fillable(['workspace_id', 'survey_id', 'external_ref', 'engine', 'customer_id', 'conversation_id', 'booking_record_id', 'location_id', 'channel', 'status', 'sent_at'])]
class SurveyDispatch extends Model
{
    public const STATUSES = ['sent', 'failed', 'answered'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
