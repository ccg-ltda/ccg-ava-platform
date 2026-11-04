<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a customer answered. Written only by App\Services\SurveyRecorder. */
#[Fillable(['workspace_id', 'survey_id', 'dispatch_id', 'external_ref', 'engine', 'customer_id', 'conversation_id', 'booking_record_id', 'location_id', 'rating', 'comment', 'answers', 'is_complete', 'answered_at'])]
class SurveyResponse extends Model
{
    protected function casts(): array
    {
        return ['answers' => 'array', 'is_complete' => 'boolean', 'answered_at' => 'datetime', 'rating' => 'float'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function bookingRecord(): BelongsTo
    {
        return $this->belongsTo(BookingRecord::class);
    }
}
