<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
use App\Models\Conversation;
use App\Models\Survey;
use App\Models\SurveyDispatch;
use App\Models\SurveyResponse;
use App\Models\Workspace;
use App\Reports\ReportPeriod;
use App\Services\AgentAccess;
use App\Services\ConsolidatedBookings;
use App\Services\SurveyMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** Reservations of any system and surveys reported by the engine: idempotent, isolated by Workspace, counted once and computed only from stored rows. */
class ReportingIngestionTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'reports'];

    private Workspace $workspace;

    private Workspace $other;

    private string $token;

    private string $otherToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        [$this->workspace, $this->token] = $this->tenant('REPORTA');
        [$this->other, $this->otherToken] = $this->tenant('OTRO');
    }

    /** @return array{Workspace, string} */
    private function tenant(string $code): array
    {
        $workspace = $this->bookingWorkspace($code, self::MODULES);
        $bot = $workspace->chatbots()->create(['name' => 'Bot']);

        return [$workspace, app(AgentAccess::class)->generate($bot)];
    }

    private function agent(string $path, array $data, ?string $token = null)
    {
        return $this->flushHeaders()->withToken($token ?? $this->token)->withHeaders(['X-Ava-Engine' => 'n8n'])->postJson("/api/agent{$path}", $data);
    }

    private function booking(array $extra = []): array
    {
        return $extra + ['source' => 'booker', 'external_id' => 'BK-100', 'status' => 'booked', 'starts_at' => '2026-10-12T15:00:00Z', 'service_name' => 'Corte', 'professional_name' => 'Ana'];
    }

    public function test_a_booker_reservation_is_stored_once_and_follows_its_lifecycle(): void
    {
        $this->agent('/booking-records', $this->booking(['updated_at' => '2026-10-09T10:00:00Z']))->assertCreated();
        $this->agent('/booking-records', $this->booking(['updated_at' => '2026-10-09T10:00:00Z']))->assertOk()->assertJsonPath('created', false);
        $this->agent('/booking-records', $this->booking(['status' => 'cancelled', 'updated_at' => '2026-10-11T10:00:00Z']))->assertOk();
        // A late retry of the older "booked" report must not reopen the cancellation.
        $this->agent('/booking-records', $this->booking(['updated_at' => '2026-10-10T10:00:00Z']))->assertOk()->assertJsonPath('ignored', true);

        $this->assertSame(1, BookingRecord::count());
        $record = BookingRecord::firstOrFail();
        $this->assertSame(['cancelled', 'n8n', $this->workspace->id], [$record->status, $record->engine, $record->workspace_id]);
        $this->assertNotNull($record->cancelled_at);
    }

    public function test_references_to_another_workspace_are_rejected_and_the_workspace_is_never_taken_from_the_payload(): void
    {
        $foreign = $this->other->locations()->create(['name' => 'Ajena']);
        $this->agent('/booking-records', $this->booking(['location_id' => $foreign->id]))->assertStatus(422);
        $this->agent('/booking-records', $this->booking(['workspace_id' => $this->other->id]))->assertCreated();

        $this->assertSame([$this->workspace->id], BookingRecord::pluck('workspace_id')->all());
        $this->agent('/booking-records', $this->booking())->assertOk();
        $this->agent('/booking-records', $this->booking(), $this->otherToken)->assertCreated();
        $this->assertSame(2, BookingRecord::count(), 'The same external id in another Workspace is its own reservation.');
    }

    public function test_consolidated_report_separates_sources_and_counts_a_reservation_that_is_also_an_appointment_once(): void
    {
        $service = $this->makeService($this->workspace);
        $customer = $this->workspace->bookingCustomers()->create(['name' => 'Laura', 'phone' => '573001112233']);
        $appointment = $this->workspace->appointments()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'service_name' => 'Corte', 'starts_at' => '2026-10-12 14:00:00', 'ends_at' => '2026-10-12 15:00:00', 'blocks_until' => '2026-10-12 15:00:00',
            'status' => 'confirmed', 'provider' => 'booker', 'external_id' => 'BK-100', 'sync_status' => 'synced', 'channel' => 'chat', 'token_hash' => hash('sha256', 'x'),
        ]);
        $this->workspace->appointments()->create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'service_name' => 'Tinte', 'starts_at' => '2026-10-12 16:00:00', 'ends_at' => '2026-10-12 17:00:00', 'blocks_until' => '2026-10-12 17:00:00',
            'status' => 'confirmed', 'provider' => 'internal', 'sync_status' => 'none', 'channel' => 'admin', 'token_hash' => hash('sha256', 'y'),
        ]);
        $this->agent('/booking-records', $this->booking(['status' => 'confirmed']))->assertCreated();
        $this->agent('/booking-records', $this->booking(['external_id' => 'BK-200', 'status' => 'completed']))->assertCreated();

        $this->assertSame($appointment->id, BookingRecord::where('external_id', 'BK-100')->value('appointment_id'));

        $period = ReportPeriod::make('7d', null, 'UTC');
        $summary = app(ConsolidatedBookings::class)->summary($this->workspace, $period);
        $sources = collect($summary['bySource'])->pluck('value', 'key');

        $this->assertSame(3, $summary['total'], 'BK-100 is counted through its record only: 2 from Booker + 1 internal appointment.');
        $this->assertSame([2, 1, 0], [$sources['booker'], $sources['ava'], $sources['lava']]);
        $this->assertSame(1, app(ConsolidatedBookings::class)->summary($this->workspace, $period, ['source' => 'ava'])['total']);
        $this->assertSame(1, app(ConsolidatedBookings::class)->summary($this->workspace, $period, ['status' => 'completed'])['total']);
        $this->assertSame(0, app(ConsolidatedBookings::class)->summary($this->other, $period)['total'], 'Another Workspace sees none of it.');
    }

    private function defineSurvey(): void
    {
        $this->agent('/surveys', ['key' => 'post-visita', 'name' => 'Satisfacción', 'type' => 'satisfaction', 'scale_min' => 1, 'scale_max' => 5,
            'questions' => [['key' => 'atencion', 'label' => '¿Cómo fue la atención?', 'type' => 'rating'], ['key' => 'volveria', 'label' => '¿Volvería?', 'type' => 'boolean']]])->assertCreated();
    }

    public function test_a_survey_response_is_stored_once_linked_to_the_reservation_and_the_dispatch(): void
    {
        $this->defineSurvey();
        $this->agent('/booking-records', $this->booking())->assertCreated();
        $this->agent('/surveys/dispatches', ['survey_key' => 'post-visita', 'external_ref' => 'disp-1', 'booking_source' => 'booker', 'booking_external_id' => 'BK-100'])->assertCreated();
        $this->agent('/surveys/dispatches', ['survey_key' => 'post-visita', 'external_ref' => 'disp-1'])->assertOk()->assertJsonPath('created', false);

        $answer = ['survey_key' => 'post-visita', 'external_ref' => 'resp-1', 'dispatch_ref' => 'disp-1', 'rating' => 5, 'comment' => 'Excelente', 'answers' => ['atencion' => 5, 'volveria' => true]];
        $this->agent('/surveys/responses', $answer)->assertCreated()->assertJsonPath('is_complete', true);
        $this->agent('/surveys/responses', $answer)->assertOk()->assertJsonPath('created', false);

        $this->assertSame([1, 1, 1], [SurveyResponse::count(), SurveyDispatch::count(), Survey::count()]);
        $response = SurveyResponse::firstOrFail();
        $this->assertSame(BookingRecord::firstOrFail()->id, $response->booking_record_id, 'The response inherits the reservation of its dispatch.');
        $this->assertSame('answered', SurveyDispatch::firstOrFail()->status);
    }

    public function test_invalid_survey_data_is_rejected(): void
    {
        $this->defineSurvey();

        $this->agent('/surveys/responses', ['survey_key' => 'post-visita', 'external_ref' => 'r-bad1', 'rating' => 9])->assertStatus(422);
        $this->agent('/surveys/responses', ['survey_key' => 'post-visita', 'external_ref' => 'r-bad2', 'answers' => ['no_existe' => 1]])->assertStatus(422);
        $this->agent('/surveys/responses', ['survey_key' => 'nadie', 'external_ref' => 'r-bad3', 'rating' => 3])->assertStatus(422);
        $this->agent('/surveys/responses', ['survey_key' => 'post-visita', 'external_ref' => 'r-bad4', 'dispatch_ref' => 'no-existe', 'rating' => 3])->assertStatus(422);
        $this->agent('/surveys/responses', ['survey_key' => 'post-visita', 'external_ref' => 'r-bad5', 'rating' => 3], $this->otherToken)->assertStatus(422);
        $this->assertSame(0, SurveyResponse::count());
    }

    public function test_metrics_come_from_stored_rows_with_rate_satisfaction_and_incomplete_responses(): void
    {
        $this->defineSurvey();
        $this->agent('/surveys/dispatches', ['survey_key' => 'post-visita', 'external_ref' => 'disp-1'])->assertCreated();
        $this->agent('/surveys/dispatches', ['survey_key' => 'post-visita', 'external_ref' => 'disp-2'])->assertCreated();
        $this->agent('/surveys/responses', ['survey_key' => 'post-visita', 'external_ref' => 'resp-1', 'dispatch_ref' => 'disp-1', 'rating' => 5, 'answers' => ['atencion' => 5, 'volveria' => true]])->assertCreated();
        $this->agent('/surveys/responses', ['survey_key' => 'post-visita', 'external_ref' => 'resp-2', 'rating' => 2, 'answers' => ['atencion' => 2]])->assertCreated()->assertJsonPath('is_complete', false);

        $summary = app(SurveyMetrics::class)->summary($this->workspace, ReportPeriod::make('7d', null, 'UTC'));

        $this->assertSame([2, 2, 1, 50.0], [$summary['sent'], $summary['responses'], $summary['incomplete'], $summary['responseRate']]);
        $this->assertSame([3.5, 50.0], [$summary['average'], $summary['satisfaction']]);
        $empty = app(SurveyMetrics::class)->summary($this->other, ReportPeriod::make('7d', null, 'UTC'));
        $this->assertSame([0, 0, null, null, null], [$empty['sent'], $empty['responses'], $empty['responseRate'], $empty['average'], $empty['satisfaction']], 'No data is not a zero.');
    }
    // --- one contract for every engine: progress, resume, types, duplicates ---------------------------------------------------------------

    /** The same call as `agent()`, as another engine says it (Lava Studio): nothing may depend on it. */
    private function agentAs(string $engine, string $path, array $data, ?string $token = null)
    {
        return $this->flushHeaders()->withToken($token ?? $this->token)->withHeaders(['X-Ava-Engine' => $engine])->postJson("/api/agent{$path}", $data);
    }

    private function progress(string $surveyKey, string $ref, ?string $token = null)
    {
        return $this->flushHeaders()->withToken($token ?? $this->token)->getJson('/api/agent/surveys/responses/progress?'.http_build_query(['survey_key' => $surveyKey, 'external_ref' => $ref]));
    }

    private function defineChoiceSurvey(): void
    {
        $this->agent('/surveys', ['key' => 'mixta', 'name' => 'Mixta', 'scale_min' => 1, 'scale_max' => 5, 'questions' => [
            ['key' => 'nota', 'label' => 'Nota', 'type' => 'rating'],
            ['key' => 'canal', 'label' => '¿Cómo nos conociste?', 'type' => 'choice', 'options' => ['Redes', 'Amigos', 'Otro']],
            ['key' => 'volveria', 'label' => '¿Volverías?', 'type' => 'boolean'],
            ['key' => 'cuantas', 'label' => '¿Cuántas veces vienes al mes?', 'type' => 'number'],
            ['key' => 'comentario', 'label' => 'Cuéntanos más', 'type' => 'text'],
        ]])->assertCreated();
    }

    public function test_an_interrupted_survey_resumes_where_it_stopped_and_finishes_once(): void
    {
        $this->defineChoiceSurvey();
        $this->workspace->chatbots()->firstOrFail()->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233']);
        $this->agent('/surveys/dispatches', ['survey_key' => 'mixta', 'external_ref' => 'disp-m1', 'channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertCreated();
        $base = ['survey_key' => 'mixta', 'external_ref' => 'resp-m1', 'dispatch_ref' => 'disp-m1'];

        // First pieces: two answers, then the customer disappears.
        $this->agent('/surveys/responses', $base + ['answers' => ['nota' => 4, 'canal' => 'Redes']])->assertCreated()->assertJsonPath('is_complete', false);
        $this->assertSame('started', SurveyDispatch::firstOrFail()->status);

        // The engine (or another one) comes back and asks where it was.
        $this->progress('mixta', 'resp-m1')->assertOk()->assertJsonPath('is_complete', false)->assertJsonPath('answers.canal', 'Redes')
            ->assertJsonPath('next_question.key', 'volveria')->assertJsonPath('remaining', ['volveria', 'cuantas', 'comentario']);

        // The same piece again changes nothing worse than itself; the rest completes it.
        $this->agent('/surveys/responses', $base + ['answers' => ['nota' => 4]])->assertOk()->assertJsonPath('created', false)->assertJsonPath('is_complete', false);
        $this->agentAs('lava', '/surveys/responses', $base + ['answers' => ['volveria' => true, 'cuantas' => 2, 'comentario' => 'Todo bien']])->assertOk()->assertJsonPath('is_complete', true)->assertJsonPath('updated', true);

        $this->assertSame(1, SurveyResponse::count());
        $this->assertSame(['nota' => 4, 'canal' => 'Redes', 'volveria' => true, 'cuantas' => 2, 'comentario' => 'Todo bien'], SurveyResponse::firstOrFail()->answers);
        $this->assertSame('answered', SurveyDispatch::firstOrFail()->status);
        $this->assertSame(Conversation::where('contact_id', '573001112233')->value('id'), SurveyResponse::firstOrFail()->conversation_id, 'the response keeps the conversation of its dispatch');

        // Complete is final: a late duplicate with other values touches nothing.
        $this->agent('/surveys/responses', $base + ['answers' => ['nota' => 1, 'volveria' => false]])->assertOk()->assertJsonPath('updated', false);
        $this->assertSame(4, SurveyResponse::firstOrFail()->answers['nota']);
        $this->progress('mixta', 'resp-m1')->assertOk()->assertJsonPath('is_complete', true)->assertJsonPath('next_question', null)->assertJsonPath('remaining', []);
    }

    public function test_both_engines_get_the_same_answers_and_the_engine_is_only_a_label(): void
    {
        $this->defineChoiceSurvey();
        $answers = ['nota' => 5, 'canal' => 'Amigos', 'volveria' => true, 'cuantas' => 3, 'comentario' => 'Excelente'];

        foreach (['n8n' => 'r-n8n-1', 'lava' => 'r-lava-1'] as $engine => $ref) {
            $this->agentAs($engine, '/surveys/dispatches', ['survey_key' => 'mixta', 'external_ref' => "d-{$ref}"])->assertCreated();
            $this->agentAs($engine, '/surveys/responses', ['survey_key' => 'mixta', 'external_ref' => $ref, 'dispatch_ref' => "d-{$ref}", 'answers' => $answers])->assertCreated()->assertJsonPath('is_complete', true);
        }

        $rows = SurveyResponse::orderBy('id')->get();
        $this->assertSame(['n8n', 'lava'], $rows->pluck('engine')->all());
        $this->assertSame($rows[0]->answers, $rows[1]->answers);
        $this->assertSame([true, true], $rows->pluck('is_complete')->all());
        $this->assertSame(['answered', 'answered'], SurveyDispatch::orderBy('id')->pluck('status')->all());

        // An unknown engine is stored as none, never as whatever the caller wrote.
        $this->agentAs('inventado', '/surveys/responses', ['survey_key' => 'mixta', 'external_ref' => 'r-x-1', 'answers' => $answers])->assertCreated();
        $this->assertNull(SurveyResponse::where('external_ref', 'r-x-1')->value('engine'));
    }

    public function test_every_answer_is_checked_against_the_type_of_its_question(): void
    {
        $this->defineChoiceSurvey();
        $send = fn (array $answers, string $ref) => $this->agent('/surveys/responses', ['survey_key' => 'mixta', 'external_ref' => $ref, 'answers' => $answers]);

        $send(['nota' => 9], 'tipo-001')->assertStatus(422)->assertJsonValidationErrors('answers.nota');
        $send(['nota' => 'alta'], 'tipo-002')->assertStatus(422)->assertJsonValidationErrors('answers.nota');
        $send(['canal' => 'Radio'], 'tipo-003')->assertStatus(422)->assertJsonValidationErrors('answers.canal');
        $send(['canal' => ['Redes']], 'tipo-004')->assertStatus(422)->assertJsonValidationErrors('answers.canal');
        $send(['volveria' => 'si'], 'tipo-005')->assertStatus(422)->assertJsonValidationErrors('answers.volveria');
        $send(['cuantas' => 'muchas'], 'tipo-006')->assertStatus(422)->assertJsonValidationErrors('answers.cuantas');
        $send(['comentario' => 123], 'tipo-007')->assertStatus(422)->assertJsonValidationErrors('answers.comentario');
        $this->assertSame(0, SurveyResponse::count(), 'a refused answer stores nothing');

        $send(['nota' => 3, 'canal' => 'Otro', 'volveria' => false, 'cuantas' => 0, 'comentario' => 'ok', 'otra' => null], 'tipo-008')->assertStatus(422); // a question the survey does not have
        $send(['nota' => 3, 'canal' => 'Otro', 'volveria' => false, 'cuantas' => 0, 'comentario' => 'ok'], 'tipo-009')->assertCreated()->assertJsonPath('is_complete', true);
    }

    public function test_a_reference_belongs_to_one_survey_and_one_workspace(): void
    {
        $this->defineChoiceSurvey();
        $this->defineSurvey();
        $this->agent('/surveys/responses', ['survey_key' => 'mixta', 'external_ref' => 'ref-compartida', 'answers' => ['nota' => 2]])->assertCreated();

        $this->agent('/surveys/responses', ['survey_key' => 'post-visita', 'external_ref' => 'ref-compartida', 'rating' => 3])->assertStatus(422)->assertJsonValidationErrors('external_ref');
        // Another Workspace's token neither reads nor extends it.
        $this->progress('mixta', 'ref-compartida', $this->otherToken)->assertStatus(422);
        $this->assertSame(1, SurveyResponse::count());
        $this->progress('mixta', 'no-existe-1')->assertStatus(422);
    }

    public function test_the_survey_contract_needs_the_reports_module_of_the_plan(): void
    {
        $this->defineChoiceSurvey();
        $this->agent('/surveys/responses', ['survey_key' => 'mixta', 'external_ref' => 'plan-0001', 'answers' => ['nota' => 2]])->assertCreated();

        $this->contractModules($this->workspace, ['dashboard', 'assistants', 'conversations']); // no Reportes
        $this->agent('/surveys/responses', ['survey_key' => 'mixta', 'external_ref' => 'plan-0002', 'answers' => ['nota' => 2]])->assertForbidden();
        $this->progress('mixta', 'plan-0001')->assertForbidden();
        $this->assertSame(1, SurveyResponse::count());
    }
}
