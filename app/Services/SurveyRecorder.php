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
 *
 * ONE contract for every engine (n8n, Lava Studio) and every channel: the engine runs the conversation and sends the questions through the channel it has; Ava keeps the survey, the
 * dispatch and what was answered. A response can be sent in pieces: the same `external_ref` again MERGES the new answers into the stored, still incomplete response (the engine can resume
 * after an interruption by reading `progress()`); once it is complete it never changes again, so a retry or a late duplicate answers 200 and touches nothing. Each answer is checked
 * against the type of its question (rating inside the scale, a choice among the options, a boolean, a number, text).
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
     * @return array{response: SurveyResponse, created: bool, updated: bool}
     *
     * @throws ValidationException
     */
    public function respond(Workspace $workspace, array $data, ?string $engine): array
    {
        $existing = SurveyResponse::where('workspace_id', $workspace->id)->where('external_ref', $data['external_ref'])->first();

        if ($existing) {
            return $this->resume($workspace, $existing, $data);
        }

        $survey = $this->survey($workspace, $data['survey_key']);
        $dispatch = isset($data['dispatch_ref'])
            ? SurveyDispatch::where('workspace_id', $workspace->id)->where('survey_id', $survey->id)->where('external_ref', $data['dispatch_ref'])->first()
            : null;

        if (isset($data['dispatch_ref']) && ! $dispatch) {
            throw ValidationException::withMessages(['dispatch_ref' => 'Ese envío no existe para esta encuesta en este Workspace.']);
        }

        $rating = $data['rating'] ?? null;
        $this->assertRating($survey, $rating);

        $answers = $data['answers'] ?? null;
        $this->assertAnswers($survey, $answers);
        $complete = $data['is_complete'] ?? $this->complete($survey, $answers, $rating);

        $attributes = [
            'workspace_id' => $workspace->id, 'survey_id' => $survey->id, 'dispatch_id' => $dispatch?->id, 'external_ref' => $data['external_ref'], 'engine' => $engine,
            'customer_id' => $this->links->customer($workspace, $data['customer_id'] ?? null) ?? $dispatch?->customer_id,
            'conversation_id' => $this->links->conversation($workspace, $data['channel'] ?? null, $data['contact_id'] ?? null) ?? $dispatch?->conversation_id,
            'booking_record_id' => $this->links->booking($workspace, $data['booking_source'] ?? null, $data['booking_external_id'] ?? null) ?? $dispatch?->booking_record_id,
            'location_id' => $this->links->location($workspace, $data['location_id'] ?? null) ?? $dispatch?->location_id,
            'rating' => $rating, 'comment' => $data['comment'] ?? null, 'answers' => $answers,
            'is_complete' => $complete,
            'answered_at' => $data['answered_at'] ?? now(),
        ];

        try {
            $response = DB::transaction(function () use ($attributes, $dispatch, $complete) {
                $response = SurveyResponse::create($attributes);
                $this->advance($dispatch, $complete);

                return $response;
            });
        } catch (UniqueConstraintViolationException) {
            // Two deliveries at the same instant: the database let one create it; this one continues from what is stored.
            return $this->resume($workspace, SurveyResponse::where('workspace_id', $workspace->id)->where('external_ref', $data['external_ref'])->firstOrFail(), $data);
        }

        return ['response' => $response, 'created' => true, 'updated' => false];
    }

    /**
     * The same response again. Complete = final (nothing changes). Incomplete = the new answers are merged into the stored ones (the engine resumes where it stopped), the score and
     * comment are replaced only when sent, and completeness is recomputed from the merged answers unless the engine says it is done.
     *
     * @return array{response: SurveyResponse, created: bool, updated: bool}
     *
     * @throws ValidationException
     */
    private function resume(Workspace $workspace, SurveyResponse $existing, array $data): array
    {
        $survey = Survey::where('workspace_id', $workspace->id)->findOrFail($existing->survey_id);

        if ($survey->key !== $data['survey_key']) {
            throw ValidationException::withMessages(['external_ref' => 'Esa referencia ya pertenece a otra encuesta.']);
        }

        if ($existing->is_complete) {
            return ['response' => $existing, 'created' => false, 'updated' => false];
        }

        return DB::transaction(function () use ($workspace, $existing, $survey, $data) {
            // Re-read under a lock: two pieces of the same response arriving together are merged one after the other, never one over the other.
            $current = SurveyResponse::where('workspace_id', $workspace->id)->whereKey($existing->id)->lockForUpdate()->firstOrFail();

            if ($current->is_complete) {
                return ['response' => $current, 'created' => false, 'updated' => false];
            }

            $rating = $data['rating'] ?? null;
            $this->assertRating($survey, $rating);
            $incoming = $data['answers'] ?? null;
            $this->assertAnswers($survey, $incoming);

            $answers = array_replace((array) $current->answers, (array) $incoming);
            $rating ??= $current->rating;
            $complete = $data['is_complete'] ?? $this->complete($survey, $answers, $rating);
            $current->forceFill([
                'answers' => $answers === [] ? null : $answers, 'rating' => $rating, 'comment' => $data['comment'] ?? $current->comment, 'is_complete' => $complete, 'answered_at' => $data['answered_at'] ?? now(),
            ])->save();

            $this->advance($current->dispatch_id ? SurveyDispatch::where('workspace_id', $workspace->id)->find($current->dispatch_id) : null, $complete);

            return ['response' => $current, 'created' => false, 'updated' => true];
        });
    }

    /**
     * Where an interrupted response stands, so the engine resumes instead of starting over: what is stored, whether it is complete and the questions still unanswered (in the order the
     * survey defines them). The same answer for every engine and channel.
     *
     * @return array{survey_key: string, external_ref: string, is_complete: bool, answers: array<string, mixed>, rating: ?float, next_question: ?array<string, mixed>, remaining: list<string>}
     *
     * @throws ValidationException
     */
    public function progress(Workspace $workspace, string $surveyKey, string $externalRef): array
    {
        $survey = $this->survey($workspace, $surveyKey);
        $response = SurveyResponse::where('workspace_id', $workspace->id)->where('survey_id', $survey->id)->where('external_ref', $externalRef)->first();

        if (! $response) {
            throw ValidationException::withMessages(['external_ref' => 'Esa respuesta no existe para esta encuesta en este Workspace.']);
        }

        $answers = (array) $response->answers;
        $pending = $response->is_complete ? [] : array_values(array_filter((array) $survey->questions, fn (array $q) => ($answers[$q['key']] ?? null) === null || $answers[$q['key']] === ''));

        return [
            'survey_key' => $survey->key, 'external_ref' => $response->external_ref, 'is_complete' => (bool) $response->is_complete, 'answers' => $answers, 'rating' => $response->rating,
            'next_question' => $pending === [] ? null : array_intersect_key($pending[0], array_flip(['key', 'label', 'type', 'options'])),
            'remaining' => array_column($pending, 'key'),
        ];
    }

    /** The dispatch follows its response: «started» while the customer is answering, «answered» once it is complete (never back). */
    private function advance(?SurveyDispatch $dispatch, bool $complete): void
    {
        if ($dispatch === null || $dispatch->status === 'answered') {
            return;
        }

        $dispatch->update(['status' => $complete ? 'answered' : 'started']);
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

    /** @throws ValidationException */
    private function assertRating(Survey $survey, mixed $rating): void
    {
        if ($rating !== null && $survey->scale_min !== null && $survey->scale_max !== null && ($rating < $survey->scale_min || $rating > $survey->scale_max)) {
            throw ValidationException::withMessages(['rating' => "La calificación debe estar entre {$survey->scale_min} y {$survey->scale_max}."]);
        }
    }

    /** @throws ValidationException answers may only name questions the survey defines, and each must be an answer of the kind its question asks for */
    private function assertAnswers(Survey $survey, ?array $answers): void
    {
        if ($answers === null || empty($survey->questions)) {
            return;
        }

        $questions = array_column($survey->questions, null, 'key');
        $unknown = array_diff(array_keys($answers), array_keys($questions));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => 'La encuesta no tiene las preguntas: '.implode(', ', $unknown).'.']);
        }

        foreach ($answers as $key => $value) {
            if ($value !== null && ($problem = $this->answerProblem($survey, $questions[$key], $value)) !== null) {
                throw ValidationException::withMessages(["answers.{$key}" => $problem]);
            }
        }
    }

    /** Why this value is not an answer to that question, or null. A survey with no scale or a question with no options accepts any value of the right kind. */
    private function answerProblem(Survey $survey, array $question, mixed $value): ?string
    {
        $outOfScale = $survey->scale_min !== null && $survey->scale_max !== null && is_numeric($value) && ($value < $survey->scale_min || $value > $survey->scale_max);

        return match ($question['type']) {
            'rating' => ! is_numeric($value) || is_bool($value) ? 'Debe ser un número.' : ($outOfScale ? "Debe estar entre {$survey->scale_min} y {$survey->scale_max}." : null),
            'number' => ! is_numeric($value) || is_bool($value) ? 'Debe ser un número.' : null,
            'boolean' => ! is_bool($value) ? 'Debe ser verdadero o falso.' : null,
            'text' => ! is_string($value) ? 'Debe ser texto.' : (mb_strlen($value) > 4000 ? 'El texto es demasiado largo (máximo 4000 caracteres).' : null),
            'choice' => ! is_string($value) ? 'Debe ser una de las opciones.' : (! empty($question['options']) && ! in_array($value, $question['options'], true) ? 'No es una de las opciones de la pregunta.' : null),
            default => null,
        };
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
