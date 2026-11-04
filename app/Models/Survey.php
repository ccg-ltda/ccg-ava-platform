<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Definition of a survey (questions and scale). Written only by App\Services\SurveyRecorder. */
#[Fillable(['workspace_id', 'key', 'name', 'description', 'type', 'status', 'scale_min', 'scale_max', 'questions'])]
class Survey extends Model
{
    public const TYPES = ['satisfaction', 'nps', 'custom'];

    public const QUESTION_TYPES = ['rating', 'text', 'choice', 'boolean', 'number'];

    protected function casts(): array
    {
        return ['questions' => 'array'];
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(SurveyDispatch::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }
}
