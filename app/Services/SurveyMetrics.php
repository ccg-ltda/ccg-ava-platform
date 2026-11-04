<?php

namespace App\Services;

use App\Models\SurveyDispatch;
use App\Models\SurveyResponse;
use App\Models\Workspace;
use App\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures of Reportes > Encuestas, computed only from persisted dispatches and responses of ONE Workspace. Definitions (written in docs/RECOMENDACIONES.md):
 *  - Sent: dispatches whose `sent_at` falls in the period and that were not `failed`.
 *  - Responses: responses whose `answered_at` falls in the period. Incomplete ones (`is_complete = false`) are counted as responses but excluded from the score
 *    average and the satisfaction share only when they carry no score; a response with a score always counts for the score.
 *  - Response rate: dispatches in the period that have at least one response / sent, as a percentage; null when nothing was sent (never 0 of 0).
 *  - Average score: mean of `rating` over responses with a score. Surveys of different scales are NOT mixed: when the period holds more than one scale, the average
 *    and the satisfaction are given per survey instead of one combined figure.
 *  - Satisfaction: share of scored responses whose score is in the upper part of their scale (>= 80% of the maximum, e.g. 4 and 5 out of 5); null without scored responses.
 * Filters: sede (`locations`), and the period. The location comes from the response (or its dispatch).
 */
class SurveyMetrics
{
    /**
     * @param  ?list<int>  $locationIds
     * @return array<string, mixed>
     */
    public function summary(Workspace $workspace, ReportPeriod $period, ?array $locationIds = null): array
    {
        $dispatches = fn (): Builder => SurveyDispatch::query()->where('workspace_id', $workspace->id)->where('status', '!=', 'failed')
            ->where('sent_at', '>=', $period->startUtc())->where('sent_at', '<', $period->endExclusiveUtc())
            ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds));
        $responses = fn (): Builder => SurveyResponse::query()->where('survey_responses.workspace_id', $workspace->id)
            ->where('answered_at', '>=', $period->startUtc())->where('answered_at', '<', $period->endExclusiveUtc())
            ->when($locationIds !== null, fn ($q) => $q->whereIn('survey_responses.location_id', $locationIds));

        $sent = (int) $dispatches()->count();
        $answeredDispatches = (int) $dispatches()->whereIn('id', SurveyResponse::query()->where('workspace_id', $workspace->id)->whereNotNull('dispatch_id')->select('dispatch_id'))->count();
        $total = (int) $responses()->count();
        $scored = $responses()->join('surveys', 'surveys.id', '=', 'survey_responses.survey_id')->whereNotNull('rating')->whereNotNull('surveys.scale_max')
            ->get(['survey_responses.rating', 'survey_responses.survey_id', 'survey_responses.answered_at', 'surveys.scale_max', 'surveys.name', 'surveys.scale_min']);
        $scales = $scored->pluck('scale_max')->unique();

        return [
            'sent' => $sent,
            'responses' => $total,
            'incomplete' => (int) $responses()->where('is_complete', false)->count(),
            'responseRate' => $sent > 0 ? round($answeredDispatches / $sent * 100, 1) : null,
            'average' => $scales->count() === 1 ? round((float) $scored->avg('rating'), 2) : null,
            'satisfaction' => $scales->count() === 1 ? $this->satisfaction($scored) : null,
            'scale' => $scales->count() === 1 ? ['min' => (int) $scored->first()->scale_min, 'max' => (int) $scales->first()] : null,
            'bySurvey' => $scored->groupBy('survey_id')->map(fn ($rows) => [
                'name' => $rows->first()->name, 'responses' => $rows->count(), 'average' => round((float) $rows->avg('rating'), 2), 'scaleMax' => (int) $rows->first()->scale_max, 'satisfaction' => $this->satisfaction($rows),
            ])->values()->all(),
            'distribution' => $scales->count() === 1 ? $scored->countBy(fn ($row) => (string) (int) round((float) $row->rating))->sortKeys()->map(fn ($value, $key) => ['key' => (string) $key, 'label' => (string) $key, 'value' => (int) $value])->values()->all() : [],
            'evolution' => $responses()->get(['answered_at', 'rating'])->groupBy(fn ($row) => $period->bucketStart($row->answered_at->toImmutable())->toDateString())->map(fn ($rows, $day) => ['key' => $day, 'label' => $day, 'value' => $rows->count()])->values()->all(),
            'byLocation' => $this->byLocation($workspace, $responses()),
            'byService' => $this->byBooking($responses(), 'service_name'),
            'byProfessional' => $this->byBooking($responses(), 'professional_name'),
            'comments' => $responses()->whereNotNull('comment')->where('comment', '!=', '')->orderByDesc('answered_at')->limit(10)->get(['comment', 'rating', 'answered_at'])
                ->map(fn ($row) => ['comment' => $row->comment, 'rating' => $row->rating, 'at' => $row->answered_at->toIso8601String()])->all(),
        ];
    }

    /** @param  Collection<int, mixed>  $rows */
    private function satisfaction($rows): ?float
    {
        if ($rows->isEmpty()) {
            return null;
        }

        return round($rows->filter(fn ($row) => (float) $row->rating >= 0.8 * (float) $row->scale_max)->count() / $rows->count() * 100, 1);
    }

    /** @return list<array<string, mixed>> */
    private function byLocation(Workspace $workspace, Builder $responses): array
    {
        $names = $workspace->locations()->pluck('name', 'id');

        return $responses->whereNotNull('survey_responses.location_id')->selectRaw('survey_responses.location_id as id, count(*) as total, avg(rating) as average')->groupBy('survey_responses.location_id')->orderByDesc('total')->get()
            ->map(fn ($row) => ['key' => (string) $row->id, 'label' => $names[$row->id] ?? '#'.$row->id, 'value' => (int) $row->total, 'average' => $row->average !== null ? round((float) $row->average, 2) : null])->all();
    }

    /** @return list<array<string, mixed>> service or professional of the linked reservation; responses without a reservation are not attributed to anyone */
    private function byBooking(Builder $responses, string $column): array
    {
        return $responses->join('booking_records', 'booking_records.id', '=', 'survey_responses.booking_record_id')->whereNotNull("booking_records.{$column}")
            ->selectRaw("booking_records.{$column} as label, count(*) as total, avg(survey_responses.rating) as average")->groupBy("booking_records.{$column}")->orderByDesc('total')->limit(8)->get()
            ->map(fn ($row) => ['key' => $row->label, 'label' => $row->label, 'value' => (int) $row->total, 'average' => $row->average !== null ? round((float) $row->average, 2) : null])->all();
    }
}
