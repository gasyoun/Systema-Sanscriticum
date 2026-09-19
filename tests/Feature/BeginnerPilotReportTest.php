<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\User;
use App\Services\Reports\BeginnerPilotReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BeginnerPilotReportTest extends TestCase
{
    use RefreshDatabase;

    private function payment(User $user, array $overrides = []): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $user->id, 'amount' => 500, 'tariff' => 'marathon_paid',
            'status' => 'paid', 'first_paid_at' => '2026-09-03 10:00:00', 'is_conditional' => false,
        ], $overrides)));
    }

    private function report(array $ids = []): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10'));

        return app(BeginnerPilotReport::class)->build(CarbonImmutable::parse('2026-09-01'), 30, $ids);
    }

    public function test_deduplicates_buyers_and_retains_refunded_history_and_unknown_dates(): void
    {
        $new = User::factory()->create();
        $this->payment($new);
        $this->payment($new);
        $old = User::factory()->create();
        $this->payment($old, ['first_paid_at' => '2026-08-01', 'status' => 'cancelled']);
        $this->payment($old);
        $unknown = User::factory()->create();
        $prior = $this->payment($unknown, ['first_paid_at' => null, 'status' => 'failed']);
        PaymentAudit::create(['payment_id' => $prior->id, 'action' => 'updated', 'changes' => ['status' => ['paid', 'failed']]]);
        $this->payment($unknown);
        $excluded = User::factory()->create();
        $this->payment($excluded, ['amount' => 0]);
        $this->payment($excluded, ['is_conditional' => true]);
        $this->payment($excluded, ['tariff' => 'deposit']);
        $this->payment($excluded, ['first_paid_at' => '2026-10-01']);
        $this->payment($new, ['amount' => -200, 'tariff' => 'Расход', 'refund_of_payment_id' => $prior->id, 'created_at' => '2026-09-05']);
        $this->payment($new, ['amount' => 100, 'tariff' => 'Расход', 'refund_of_payment_id' => $prior->id, 'created_at' => '2026-09-05']);
        $before = Payment::count();
        $result = $this->report();
        $this->assertSame(['first_time' => 1, 'returning' => 1, 'history_unknown' => 1], $result['buyers']);
        $this->assertSame(4, $result['reconciliation']['purchase_rows_with_paid_timestamp']);
        $this->assertSame(300.0, $result['reconciliation']['linked_refund_amount_rub']);
        $this->assertSame($before, Payment::count());
        $this->assertNull($result['first_task_starts']);
        $this->assertNull($result['support_minutes']);
        $this->assertNull($result['first_time_marathon_main_course_buyers']);
    }

    public function test_provenance_continuation_and_quiz_completion_are_not_delivery(): void
    {
        $main = Course::factory()->create();
        $other = Course::factory()->create();
        $lead = Lead::factory()->create(['utm_source' => 'vk', 'source' => null]);
        $lead->forceFill(['inferred_source' => 'telegram'])->saveQuietly();
        $buyer = User::factory()->create(['lead_id' => $lead->id]);
        $this->payment($buyer, ['lead_id' => $lead->id]);
        $this->payment($buyer, ['course_id' => $other->id, 'tariff' => 'full', 'first_paid_at' => '2026-09-04']);
        MarathonEnrollment::create(['lead_id' => $lead->id, 'track' => 'paid', 'day0_started_at' => '2026-09-03', 'day1_completed_at' => '2026-09-04']);
        $inferredLead = Lead::factory()->create(['utm_source' => null, 'source' => null]);
        $inferredLead->forceFill(['inferred_source' => 'youtube'])->saveQuietly();
        $inferred = User::factory()->create(['lead_id' => $inferredLead->id]);
        $this->payment($inferred);
        $unknown = User::factory()->create(['lead_id' => null]);
        $this->payment($unknown);
        $result = $this->report([$main->id]);
        $this->assertSame(['observed' => 1, 'inferred' => 1, 'unknown' => 1], $result['first_time_source_provenance']);
        $this->assertSame(0, $result['first_time_marathon_main_course_buyers']);
        $this->assertSame(0, $result['first_time_marathon_day1_quiz_completed']);
        $this->payment($buyer, ['course_id' => $main->id, 'tariff' => 'full', 'first_paid_at' => '2026-09-06']);
        MarathonEnrollment::where('lead_id', $lead->id)->update(['day1_engaged_at' => '2026-09-05']);
        $result = $this->report([$main->id]);
        $this->assertSame(1, $result['first_time_marathon_main_course_buyers']);
        $this->assertSame(1, $result['first_time_marathon_day1_quiz_completed']);
    }

    public function test_command_requires_valid_explicit_start_and_outputs_aggregate_json(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10'));
        $this->assertSame(1, Artisan::call('report:beginner-pilot'));
        $this->assertSame(1, Artisan::call('report:beginner-pilot', ['--from' => '2026-02-30']));
        $this->assertSame(1, Artisan::call('report:beginner-pilot', ['--from' => '2026-09-01', '--days' => '0']));
        $this->assertSame(1, Artisan::call('report:beginner-pilot', ['--from' => '2026-09-01', '--main-course' => ['999999']]));
        $this->assertSame(0, Artisan::call('report:beginner-pilot', ['--from' => '2026-09-01', '--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['buyers']['first_time']);
        $this->assertSame(CarbonImmutable::parse('2026-10-01', config('app.timezone'))->toIso8601String(), $result['window']['end_exclusive']);
    }
}
