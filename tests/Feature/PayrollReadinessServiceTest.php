<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Services\Payroll\BackfillHistoryService;
use App\Services\Payroll\PayrollReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PayrollReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payroll_readiness.expected_teacher_count', 23);
        config()->set('payroll_readiness.evidence_manifest_path', storage_path('framework/testing/missing-payroll-evidence.json'));
        config()->set('services.tochka.token', '');
    }

    public function test_all_23_teachers_receive_an_explicit_census_disposition(): void
    {
        Teacher::factory()->count(23)->create();

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));

        $this->assertCount(23, $report['teachers']);
        $this->assertSame([], $report['census_exceptions']);
        $this->assertSame(['inactive' => 23], $report['totals']['by_disposition']);
        $this->assertTrue(collect($report['teachers'])->every(
            fn (array $row): bool => in_array($row['disposition'], ['payable', 'zero', 'inactive', 'held', 'outside_calculator'], true)
        ));
    }

    public function test_last_actual_transfer_uses_paid_at_not_later_backfill_creation_date(): void
    {
        $teacher = Teacher::factory()->create();
        Teacher::factory()->count(22)->create();
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 12345.67,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-07-23',
            'created_at' => '2026-09-27 10:00:00',
            'comment' => BackfillHistoryService::COMMENT_MARKER.' [fixture]: Xoom evidence',
            'breakdown' => ['source_quote' => 'private evidence #fixture'],
        ]);

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $row = collect($report['teachers'])->firstWhere('teacher_id', $teacher->id);

        $this->assertSame('2026-07-23', $row['last_actual_transfer']['date']);
        $this->assertSame(12345.67, $row['last_actual_transfer']['amount_rub']);
        $this->assertTrue($row['last_actual_transfer']['is_historical_backfill']);
        $this->assertArrayNotHasKey('created_at', $row['last_actual_transfer']);
    }

    public function test_repeated_cabinet_export_query_has_same_fingerprint_and_is_read_only(): void
    {
        Teacher::factory()->count(23)->create();
        $before = [Payment::query()->count(), TeacherPayout::query()->count()];
        $service = app(PayrollReadinessService::class);

        $cabinet = $service->build(Carbon::parse('2026-10-01'));
        $export = $service->build(Carbon::parse('2026-10-01'));

        $this->assertSame($cabinet['fingerprint'], $export['fingerprint']);
        $this->assertSame($cabinet['totals'], $export['totals']);
        $this->assertFalse($cabinet['read_only']['moved']);
        $this->assertSame($before, [Payment::query()->count(), TeacherPayout::query()->count()]);
    }

    public function test_missing_sources_are_incomplete_never_zero(): void
    {
        Teacher::factory()->count(23)->create();

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));

        $this->assertSame('incomplete', $report['evidence']['private_manifest']['status']);
        $this->assertSame('incomplete', $report['evidence']['bank_statement']['status']);
        $this->assertSame('incomplete', $report['evidence']['paypal_xoom']['status']);
        $this->assertArrayNotHasKey('amount', $report['evidence']['private_manifest']);
    }
}
