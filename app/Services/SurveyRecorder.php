<?php

namespace App\Services;

use App\Models\Survey;
use App\Models\SurveyDispatch;
use App\Models\SurveyResponse;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY writer of surveys, their dispatches and their responses. Every write is idempotent by an engine-supplied key (survey: `key`; dispatch and response:
 * `external_ref`, unique per Workspace), so a retry returns the stored row instead of creating another. Every reference (customer, location, conversation,
 * reservation, dispatch) must belong to the Workspace of the credential (ReportingLinks). Nothing is computed or filled in here: what the engine did not send stays null.
 */
class SurveyRecorder
{
    public function __construct(private readonly ReportingLinks $links) {}

    /** @return array{survey: Survey, created: bool} */
    public function define(Workspace $workspace, array $data): array
    {
        $survey = Survey::where('workspace_id', $workspace->id)->where('key', $data['key'])->first();
        $created = $survey === null;
        $survey ??= new Survey(['workspace_id' => $workspace->id, 'key' => $data['key']]);
        $survey->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'satisfaction',
            'status' => $data['status'] ?? 'active',
            'scale_min' => $data['scale_min'] ?? null,
            'scale_max' => $data['scale_max'] ?? null,
            'questions' => $data['questions'] ?? null,
        ])->save();

        return ['survey' => $survey, 'created' => $created];
    }

    /** @return array{dispatch: SurveyDispatch, created: bool} */
    public function dispatch(Workspace $workspace, array $data, ?string $engine): array
    {
        $existing = SurveyDispatch::where('workspace_id', $workspace->id)->where('external_ref', $data['external_ref'])->first();

        if ($existing) {
            return ['dispatch' => $existing, 'created' => false];
        }

        $survey = $this->survey($workspace, $data['survey_key']);
        $attributes = [
            'workspace_id' => $workspace->id, 'survey_id' => $survey->id, 'external_ref' => $data['external_ref'], 'engine' => $engine,
            'customer_id' => $this->links->customer($workspace, $data['customer_id'] ?? null),
            'conversation_id' => $this->links->conversation($workspace, $data['channel'] ?? null, $data['contact_id'] ?? null),
            'booking_record_id' => $this->links->booking($workspace, $data['booking_source'] ?? null, $data['booking_external_id'] ?? null),
            'location_id' => $this->links->location($workspace, $data['location_id'] ?? null),
            'channel' => $data['channel'] ?? null, 'status' => $data['status'] ?? 'sent', 'sent_at' => $data['sent_at'] ?? now(),
        ];

        try {
            return ['dispatch' => SurveyDispatch::create($attributes), 'created' => true];
        } catch (UniqueConstraintViolationException) {
            return ['dispatch' => SurveyDispatch::where('workspace_id', $workspace->id)->where('external_ref', $data['external_ref'])->firstOrFail(), 'created' => false];
        }
    }

    /**
     * A response inherits customer, conversation, reservation and location from its dispatch when the engine does not send them.
     *
     * @return array{response: SurveyResponse, created: bool}
     *
     * @throws ValidationException
     */
    public function respond(Workspace $workspace, array $data, ?string $engine): array
    {
        $existing = SurveyResponse::where('workspace_id', $workspace->id)->where('external_ref', $data['external_ref'])->first();

        if ($existing) {
            return ['response' => $existing, 'created' => false];
        }

        $survey = $this->survey($workspace, $data['survey_key']);
        $dispatch = isset($data['dispatch_ref'])
            ? SurveyDispatch::where('workspace_id', $workspace->id)->where('survey_id', $survey->id)->where('external_ref', $data['dispatch_ref'])->first()
            : null;

        if (isset($data['dispatch_ref']) && ! $dispatch) {
            throw ValidationException::withMessages(['dispatch_ref' => 'Ese envío no existe para esta encuesta en este Workspace.']);
        }

        $rating = $data['rating'] ?? null;

        if ($rating !== null && $survey->scale_min !== null && $survey->scale_max !== null && ($rating < $survey->scale_min || $rating > $survey->scale_max)) {
            throw ValidationException::withMessages(['rating' => "La calificación debe estar entre {$survey->scale_min} y {$survey->scale_max}."]);
        }

        $answers = $data['answers'] ?? null;
        $this->assertAnswers($survey, $answers);

        $attributes = [
            'workspace_id' => $workspace->id, 'survey_id' => $survey->id, 'dispatch_id' => $dispatch?->id, 'external_ref' => $data['external_ref'], 'engine' => $engine,
            'customer_id' => $this->links->customer($workspace, $data['customer_id'] ?? null) ?? $dispatch?->customer_id,
            'conversation_id' => $this->links->conversation($workspace, $data['channel'] ?? null, $data['contact_id'] ?? null) ?? $dispatch?->conversation_id,
            'booking_record_id' => $this->links->booking($workspace, $data['booking_source'] ?? null, $data['booking_external_id'] ?? null) ?? $dispatch?->booking_record_id,
            'location_id' => $this->links->location($workspace, $data['location_id'] ?? null) ?? $dispatch?->location_id,
            'rating' => $rating, 'comment' => $data['comment'] ?? null, 'answers' => $answers,
            'is_complete' => $data['is_complete'] ?? $this->complete($survey, $answers, $rating),
            'answered_at' => $data['answered_at'] ?? now(),
        ];

        try {
            $response = DB::transaction(function () use ($attributes, $dispatch) {
                $response = SurveyResponse::create($attributes);
                $dispatch?->update(['status' => 'answered']);

                return $response;
            });
        } catch (UniqueConstraintViolationException) {
            return ['response' => SurveyResponse::where('workspace_id', $workspace->id)->where('external_ref', $data['external_ref'])->firstOrFail(), 'created' => false];
        }

        return ['response' => $response, 'created' => true];
    }

    /** @throws ValidationException */
    private function survey(Workspace $workspace, string $key): Survey
    {
        $survey = Survey::where('workspace_id', $workspace->id)->where('key', $key)->first();

        if (! $survey) {
            throw ValidationException::withMessages(['survey_key' => 'Esa encuesta no está definida en este Workspace.']);
        }

        return $survey;
    }

    /** @throws ValidationException answers may only name questions the survey defines */
    private function assertAnswers(Survey $survey, ?array $answers): void
    {
        if ($answers === null || empty($survey->questions)) {
            return;
        }

        $unknown = array_diff(array_keys($answers), array_column($survey->questions, 'key'));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => 'La encuesta no tiene las preguntas: '.implode(', ', $unknown).'.']);
        }
    }

    /** Complete = every defined question has an answer; without questions, complete when it carries a score. */
    private function complete(Survey $survey, ?array $answers, mixed $rating): bool
    {
        if (empty($survey->questions)) {
            return $rating !== null || ! empty($answers);
        }

        foreach ($survey->questions as $question) {
            if (($answers[$question['key']] ?? null) === null || ($answers[$question['key']] ?? null) === '') {
                return false;
            }
        }

        return true;
    }
}
