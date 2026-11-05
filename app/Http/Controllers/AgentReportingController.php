<?php

namespace App\Http\Controllers;

use App\Models\BookingRecord;
use App\Models\Survey;
use App\Models\SurveyDispatch;
use App\Services\BookingRecords;
use App\Services\SurveyRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where an automation engine (n8n, later Lava Studio) records what feeds the reports: reservations of any system and surveys with their dispatches and answers.
 * The Workspace comes from the chatbot token, never from the payload; every id in the payload must belong to it. All writes are idempotent (see BookingRecords and
 * SurveyRecorder), so a retry answers 200 with the stored row instead of creating another.
 */
class AgentReportingController extends Controller
{
    public function __construct(private readonly BookingRecords $bookings, private readonly SurveyRecorder $surveys) {}

    public function booking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(BookingRecord::SOURCES)],
            'external_id' => ['required', 'string', 'max:128'],
            'status' => ['required', Rule::in(BookingRecord::STATUSES)],
            'starts_at' => ['required', 'date'],
            'booked_at' => ['nullable', 'date'],
            'cancelled_at' => ['nullable', 'date'],
            'updated_at' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer'],
            'location_ref' => ['nullable', 'string', 'max:64'],
            'service_name' => ['nullable', 'string', 'max:160'],
            'professional_name' => ['nullable', 'string', 'max:160'],
            'customer_id' => ['nullable', 'integer'],
            'origin' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $this->bookings->report($request->attributes->get('workspace'), $data, $this->engine($request));

        return response()->json(['id' => $result['record']->id, 'created' => $result['created'], 'ignored' => $result['ignored'], 'status' => $result['record']->status], $result['created'] ? 201 : 200);
    }

    public function define(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{2,64}$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', Rule::in(Survey::TYPES)],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'scale_min' => ['nullable', 'integer', 'min:0', 'max:1000', 'required_with:scale_max'],
            'scale_max' => ['nullable', 'integer', 'min:1', 'max:1000', 'gt:scale_min', 'required_with:scale_min'],
            'questions' => ['nullable', 'array', 'max:50'],
            'questions.*.key' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/', 'distinct'],
            'questions.*.label' => ['required', 'string', 'max:300'],
            'questions.*.type' => ['required', Rule::in(Survey::QUESTION_TYPES)],
            'questions.*.options' => ['nullable', 'array', 'max:30'],
            'questions.*.options.*' => ['string', 'max:120'],
        ]);

        $result = $this->surveys->define($request->attributes->get('workspace'), $data);

        return response()->json(['id' => $result['survey']->id, 'key' => $result['survey']->key, 'created' => $result['created']], $result['created'] ? 201 : 200);
    }

    public function dispatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'survey_key' => ['required', 'string', 'max:64'],
            'external_ref' => ['required', 'string', 'regex:/^[A-Za-z0-9:._-]{4,120}$/'],
            'customer_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'max:20'],
            'contact_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/', 'required_with:channel'],
            'booking_source' => ['nullable', Rule::in(BookingRecord::SOURCES)],
            'booking_external_id' => ['nullable', 'string', 'max:128'],
            'location_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(SurveyDispatch::STATUSES)],
            'sent_at' => ['nullable', 'date'],
        ]);

        $result = $this->surveys->dispatch($request->attributes->get('workspace'), $data, $this->engine($request));

        return response()->json(['id' => $result['dispatch']->id, 'external_ref' => $result['dispatch']->external_ref, 'created' => $result['created']], $result['created'] ? 201 : 200);
    }

    public function respond(Request $request): JsonResponse
    {
        $data = $request->validate([
            'survey_key' => ['required', 'string', 'max:64'],
            'external_ref' => ['required', 'string', 'regex:/^[A-Za-z0-9:._-]{4,120}$/'],
            'dispatch_ref' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'max:20'],
            'contact_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/', 'required_with:channel'],
            'booking_source' => ['nullable', Rule::in(BookingRecord::SOURCES)],
            'booking_external_id' => ['nullable', 'string', 'max:128'],
            'location_id' => ['nullable', 'integer'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:4000'],
            'answers' => ['nullable', 'array', 'max:50'],
            'is_complete' => ['nullable', 'boolean'],
            'answered_at' => ['nullable', 'date'],
        ]);

        $result = $this->surveys->respond($request->attributes->get('workspace'), $data, $this->engine($request));

        return response()->json(['id' => $result['response']->id, 'external_ref' => $result['response']->external_ref, 'created' => $result['created'], 'updated' => $result['updated'], 'is_complete' => $result['response']->is_complete], $result['created'] ? 201 : 200);
    }

    /** Where a response stands (what is stored, what is left to ask), so any engine resumes an interrupted survey instead of starting over. */
    public function progress(Request $request): JsonResponse
    {
        $data = $request->validate(['survey_key' => ['required', 'string', 'max:64'], 'external_ref' => ['required', 'string', 'regex:/^[A-Za-z0-9:._-]{4,120}$/']]);

        return response()->json($this->surveys->progress($request->attributes->get('workspace'), $data['survey_key'], $data['external_ref']))->header('Cache-Control', 'no-store');
    }

    /** The engine the caller declares (`X-Ava-Engine`), only among the known ones. */
    private function engine(Request $request): ?string
    {
        $declared = strtolower((string) $request->header('X-Ava-Engine'));

        return array_key_exists($declared, (array) config('chatbots.engines')) ? $declared : null;
    }
}
