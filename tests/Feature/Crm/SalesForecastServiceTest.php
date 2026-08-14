<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Course;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\FollowUpTask;
use App\Models\Payment;
use App\Models\User;
use App\Services\Crm\SalesForecastService;
use App\Services\Reports\OrderPaymentConversionService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private DealStage $new;

    private DealStage $talk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));
        config()->set('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);
        config()->set('crm_forecast.horizon_days', 30);
        config()->set('crm_forecast.history_available_from', '2026-07-25');
        config()->set('crm_forecast.backtest_windows_days', [30, 90]);
        config()->set('crm_forecast.stage_probabilities', [
            'new' => ['mid' => 0.10, 'low' => 0.05, 'high' => 0.20],
            'in_progress' => ['mid' => 0.35, 'low' => 0.20, 'high' => 0.50],
            'negotiation' => ['mid' => 0.50, 'low' => 0.40, 'high' => 0.60],
            'won' => ['mid' => 1.0, 'low' => 1.0, 'high' => 1.0],
            'lost' => ['mid' => 0.0, 'low' => 0.0, 'high' => 0.0],
        ]);

        $this->course = Course::factory()->create(['title' => 'Санскрит']);
        $this->new = DealStage::query()->where('key', 'new')->firstOrFail();
        $this->talk = DealStage::query()->where('key', 'negotiation')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): SalesForecastService
    {
        return app(SalesForecastService::class);
    }

    private function paid(array $attrs, ?int $createdBy = null): Payment
    {
        $payment = Payment::withoutEvents(fn (): Payment => Payment::create(array_merge([
            'course_id' => $this->course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
            'created_at' => '2026-08-01 10:00:00',
        ], $attrs)));

        if ($createdBy !== null) {
            $payment->forceFill(['created_by_user_id' => $createdBy])->saveQuietly();
        }

        return $payment;
    }

    public function test_weighted_forecast_uses_config_probabilities(): void
    {
        Deal::factory()->create([
            'course_id' => $this->course->id,
            'amount' => 10000,
            'stage_id' => $this->new->id,
        ]);
        Deal::factory()->create([
            'course_id' => $this->course->id,
            'amount' => 20000,
            'stage_id' => $this->talk->id,
        ]);

        $snap = $this->service()->snapshot();
        $this->assertSame(30000.0, $snap['pipeline']['open_amount']);
        $this->assertEqualsWithDelta(11000.0, $snap['pipeline']['weighted_mid'], 0.01); // 10000*0.10 + 20000*0.50
        $this->assertEqualsWithDelta(8500.0, $snap['pipeline']['weighted_low'], 0.01);
        $this->assertEqualsWithDelta(14000.0, $snap['pipeline']['weighted_high'], 0.01);
    }

    public function test_open_pipeline_amount_reconciles_with_open_deals(): void
    {
        Deal::factory()->create(['amount' => 1234.5, 'stage_id' => $this->new->id]);
        Deal::factory()->won()->create(['amount' => 99999]);

        $fromDeals = round((float) Deal::query()->open()->sum('amount'), 2);
        $fromService = $this->service()->snapshot()['pipeline']['open_amount'];

        $this->assertSame($fromDeals, $fromService);
        $this->assertSame(1234.5, $fromService);
    }

    public function test_actuals_reuse_conversion_qualifying_denominator(): void
    {
        $buyer = User::factory()->create();
        $this->paid(['user_id' => $buyer->id, 'amount' => 4800, 'created_at' => '2026-08-02 09:00:00']);
        $this->paid([
            'user_id' => $buyer->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'created_at' => '2026-08-02 09:00:00',
        ]);
        $this->paid([
            'user_id' => $buyer->id,
            'amount' => 500,
            'is_conditional' => true,
            'created_at' => '2026-08-02 09:00:00',
        ]);
        $this->paid(['user_id' => $buyer->id, 'amount' => 700, 'created_at' => '2026-06-01 09:00:00']);

        $from = Carbon::parse('2026-07-15 12:00:00');
        $to = Carbon::parse('2026-08-14 12:00:00');
        $viaConversion = app(OrderPaymentConversionService::class)->qualifyingPaidRevenue($from, $to);
        $viaForecast = $this->service()->snapshot()['actuals_previous_horizon'];

        $this->assertSame($viaConversion['paid_amount'], $viaForecast['paid_amount']);
        $this->assertSame($viaConversion['paid_count'], $viaForecast['paid_count']);
        $this->assertSame(4800.0, $viaForecast['paid_amount']);
        $this->assertSame(1, $viaForecast['paid_count']);
    }

    public function test_next_action_coverage_counts_open_follow_ups_only(): void
    {
        $open = Deal::factory()->create(['stage_id' => $this->new->id, 'amount' => 1000]);
        $other = Deal::factory()->create(['stage_id' => $this->new->id, 'amount' => 1000]);
        FollowUpTask::factory()->create(['deal_id' => $open->id, 'done_at' => null]);
        FollowUpTask::factory()->done()->create(['deal_id' => $other->id]);

        $cov = $this->service()->snapshot()['pipeline']['next_action'];
        $this->assertSame(2, $cov['open_deals']);
        $this->assertSame(1, $cov['with_open_task']);
        $this->assertSame(50.0, $cov['coverage_pct']);
    }

    public function test_manager_scope_hides_other_managers_pipeline_and_actuals(): void
    {
        $anna = User::factory()->create(['name' => 'Анна', 'role' => Roles::MANAGER]);
        $boris = User::factory()->create(['name' => 'Борис', 'role' => Roles::MANAGER]);
        $buyer = User::factory()->create();

        Deal::factory()->create([
            'amount' => 10000,
            'stage_id' => $this->new->id,
            'assigned_to' => $anna->id,
        ]);
        Deal::factory()->create([
            'amount' => 20000,
            'stage_id' => $this->talk->id,
            'assigned_to' => $boris->id,
        ]);
        $this->paid(['user_id' => $buyer->id, 'amount' => 3000, 'created_at' => '2026-08-02 10:00:00'], $anna->id);
        $this->paid(['user_id' => $buyer->id, 'amount' => 9000, 'created_at' => '2026-08-02 10:00:00'], $boris->id);

        $annaSnap = $this->service()->snapshot(onlyManagerId: $anna->id);
        $this->assertSame(10000.0, $annaSnap['pipeline']['open_amount']);
        $this->assertSame(1, $annaSnap['pipeline']['deal_count']);
        $this->assertSame(3000.0, $annaSnap['actuals_previous_horizon']['paid_amount']);
        $this->assertFalse(collect($annaSnap['pipeline']['by_manager'])->contains('manager', 'Борис'));

        $adminSnap = $this->service()->snapshot();
        $this->assertSame(30000.0, $adminSnap['pipeline']['open_amount']);
        $this->assertSame(12000.0, $adminSnap['actuals_previous_horizon']['paid_amount']);
    }

    public function test_backtest_labels_pre_history_windows_unavailable_without_inventing_forecast(): void
    {
        config()->set('crm_forecast.history_available_from', '2026-08-01');
        Deal::factory()->create(['amount' => 10000, 'stage_id' => $this->new->id, 'created_at' => '2026-07-01 10:00:00']);

        $rows = collect($this->service()->snapshot()['backtest']);
        $old = $rows->firstWhere('window_days', 90);
        $this->assertSame('unavailable', $old['status']);
        $this->assertNull($old['forecast_mid']);
        $this->assertNull($old['error']);
        $this->assertStringContainsString('No snapshot was fabricated', (string) $old['reason']);
    }

    public function test_backtest_available_window_compares_reconstructed_forecast_to_actuals(): void
    {
        config()->set('crm_forecast.history_available_from', '2026-01-01');
        config()->set('crm_forecast.backtest_windows_days', [30]);

        Deal::factory()->create([
            'amount' => 10000,
            'stage_id' => $this->new->id,
            'created_at' => '2026-06-01 10:00:00',
        ]);
        $buyer = User::factory()->create();
        $this->paid(['user_id' => $buyer->id, 'amount' => 4000, 'created_at' => '2026-08-02 10:00:00']);

        $row = $this->service()->snapshot()['backtest'][0];
        $this->assertSame('available', $row['status']);
        $this->assertEqualsWithDelta(1000.0, $row['forecast_mid'], 0.01);
        $this->assertSame(4000.0, $row['actual_amount']);
        $this->assertEqualsWithDelta(3000.0, $row['error'], 0.01);
    }

    public function test_snapshot_does_not_write_payments(): void
    {
        $buyer = User::factory()->create();
        $payment = $this->paid(['user_id' => $buyer->id, 'amount' => 1111]);
        $before = $payment->fresh()->toArray();
        $count = Payment::query()->count();

        $this->service()->snapshot();

        $this->assertSame($count, Payment::query()->count());
        $this->assertSame($before, $payment->fresh()->toArray());
    }

    public function test_changing_config_probability_changes_forecast(): void
    {
        Deal::factory()->create(['amount' => 10000, 'stage_id' => $this->new->id]);
        $first = $this->service()->snapshot()['pipeline']['weighted_mid'];

        config()->set('crm_forecast.stage_probabilities.new.mid', 0.40);
        $second = $this->service()->snapshot()['pipeline']['weighted_mid'];

        $this->assertEqualsWithDelta(1000.0, $first, 0.01);
        $this->assertEqualsWithDelta(4000.0, $second, 0.01);
    }
}
