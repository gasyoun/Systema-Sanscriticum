<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankStatementImport;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\FinanceSnapshot;
use App\Models\MoneyReconRun;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payroll\BackfillHistoryService;
use App\Services\Payroll\PayrollReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

    public function test_funding_shortfall_uses_due_date_then_teacher_id_and_shows_remaining_obligation(): void
    {
        $later = Teacher::factory()->create(['name' => 'Трефилова Елена']);
        $older = Teacher::factory()->create(['name' => 'Толчельников Иван']);
        Teacher::factory()->count(21)->create();
        $this->seedPayable($later, '2026-09-02');
        $this->seedPayable($older, '2026-09-01');
        $this->seedFreshEvidence();

        Http::fake(['*' => Http::response([
            'Data' => ['Balance' => [[
                'accountId' => '40702810000000123456/RUB',
                'type' => 'ClosingAvailable',
                'Amount' => ['amount' => 3000, 'currency' => 'RUB'],
                'dateTime' => '2026-10-01T08:00:00+03:00',
            ]]],
        ])]);
        Cache::forget('tochka.open_banking.balances');

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $payable = collect($report['teachers'])->where('disposition', 'payable')->values();

        $this->assertSame([$older->id, $later->id], $payable->pluck('teacher_id')->all());
        $this->assertSame(['funded', 'unfunded'], $payable->pluck('funding_state')->all());
        $this->assertSame(2760.0, $payable[1]['remaining_obligation']);
    }

    private function seedPayable(Teacher $teacher, string $completedOn): void
    {
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::query()->create([
            'course_id' => $course->id,
            'number' => 1,
            'is_active' => true,
            'starts_at' => '2026-08-01',
            'ends_at' => $completedOn,
        ]);
        Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'status' => 'paid',
            'tariff' => 'block_1',
            'amount' => 10000,
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
        ]));
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 1,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-07-31',
        ]);
    }

    private function seedFreshEvidence(): void
    {
        $path = storage_path('framework/testing/payroll-readiness-evidence.json');
        config()->set('payroll_readiness.evidence_manifest_path', $path);
        config()->set('features.money_bank_statement_credits', true);
        config()->set('features.money_daily_reconciliation', true);
        config()->set('services.tochka.token', 'fixture-token');
        File::put($path, json_encode([
            'generated_at' => '2026-10-01T08:00:00+03:00',
            'sources' => collect(['payout_sheets', 'bank_credit', 'paypal_xoom'])
                ->mapWithKeys(fn (string $key): array => [$key => ['as_of' => '2026-10-01', 'sha256' => hash('sha256', $key)]])
                ->all(),
        ], JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => File::delete($path));
        BankStatementImport::query()->create([
            'file_sha256' => hash('sha256', 'bank'),
            'file_name' => 'private-fixture.csv',
            'covers_from' => '2026-08-01',
            'covers_to' => '2026-10-01',
        ]);
        FinanceSnapshot::query()->create([
            'type' => FinanceSnapshot::TYPE_PAYPAL_BALANCE,
            'amount_minor' => 100000,
            'currency' => 'EUR',
            'entered_at' => '2026-10-01 08:00:00',
        ]);
        MoneyReconRun::query()->create([
            'business_date' => '2026-10-01',
            'mode' => 'manual',
            'status' => MoneyReconRun::COMPLETE,
            'input_fingerprint' => hash('sha256', 'input'),
            'totals_checksum' => hash('sha256', 'totals'),
            'sources' => [],
            'totals' => [],
            'classification' => [],
            'exceptions_opened' => 0,
        ]);
    }
}
