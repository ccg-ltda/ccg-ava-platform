<?php

namespace Tests\Feature;

use App\Models\BookingRecord;
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
}
