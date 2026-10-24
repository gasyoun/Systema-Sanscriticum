<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankStatementImport;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\FinanceSnapshot;
use App\Models\Group;
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
        $this->assertSame(70, $row['last_actual_transfer']['days_since']);
        $this->assertSame(12345.67, $row['last_actual_transfer']['amount_rub']);
        $this->assertTrue($row['last_actual_transfer']['is_historical_backfill']);
        $this->assertArrayNotHasKey('created_at', $row['last_actual_transfer']);
    }

    public function test_zero_value_backfill_is_not_reported_as_an_actual_transfer(): void
    {
        $teacher = Teacher::factory()->create();
        Teacher::factory()->count(22)->create();
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 12000,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-06-01',
        ]);
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 0,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-08-01',
        ]);

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $row = collect($report['teachers'])->firstWhere('teacher_id', $teacher->id);

        $this->assertSame('2026-06-01', $row['last_actual_transfer']['date']);
        $this->assertSame(12000.0, $row['last_actual_transfer']['amount_rub']);
    }

    public function test_uncovered_history_is_excluded_from_the_current_window_amount(): void
    {
        $teacher = Teacher::factory()->create([
            'name' => 'Трефилова Елена',
            'payout_currency' => 'RUB',
        ]);
        Teacher::factory()->count(22)->create();
        $this->seedPayable($teacher, '2026-09-02');
        $oldCourse = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::query()->create([
            'course_id' => $oldCourse->id,
            'number' => 1,
            'is_active' => true,
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-07-01',
        ]);
        Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $oldCourse->id,
            'status' => 'paid',
            'tariff' => 'block_1',
            'amount' => 50000,
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
        ]));
        $currentCourse = Course::query()->where('teacher_id', $teacher->id)
            ->where('id', '!=', $oldCourse->id)
            ->firstOrFail();
        Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $currentCourse->id,
            'status' => 'paid',
            'tariff' => 'block_1',
            'amount' => 1000,
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $teacher->id,
            'foreign_amount' => 1000,
            'foreign_currency' => 'RUB',
            'created_at' => '2026-09-15 10:00:00',
        ]));

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $row = collect($report['teachers'])->firstWhere('teacher_id', $teacher->id);

        $this->assertSame('held', $row['disposition']);
        $this->assertSame('partial_current_window', $row['amount_state']);
        $this->assertSame('current_window_only_excludes_unreconciled_prior', $row['amount_basis']);
        // School: 10,000 × 92% × 30% = 2,760.
        // Direct: 1,000 × 30% − 1,000 already held = −700.
        $this->assertSame(2060.0, $row['payable_rub']);
        $this->assertSame(50000.0, $row['excluded_prior_rub']);
        $this->assertSame(15560.0, $row['legacy_candidate_rub']);
        $this->assertContains('reconciliation:uncovered_pre_cutoff_revenue', $row['holds']);
    }

    public function test_configured_since_override_brings_teacher_without_payout_history_into_tables(): void
    {
        $teacher = Teacher::factory()->create([
            'name' => 'Гасунс Марцис',
            'payout_currency' => 'RUB',
        ]);
        Teacher::factory()->count(22)->create();
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
            'ends_at' => '2026-09-02',
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

        $row = collect(app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'))['teachers'])
            ->firstWhere('teacher_id', $teacher->id);
        $this->assertSame('outside_calculator', $row['disposition']);

        config()->set('payroll_readiness.teacher_since_overrides', [$teacher->id => '2026-01-01']);

        $row = collect(app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'))['teachers'])
            ->firstWhere('teacher_id', $teacher->id);
        // The fixture name matches the real rate timeline (Гасунс Марцис =
        // 100% × 92%), so 10,000 × 92% = 9,200 in the window; held by
        // missing evidence.
        $this->assertSame('held', $row['disposition']);
        $this->assertSame(9200.0, $row['payable_rub']);
        $this->assertNotEmpty($row['calculation']['blocks']);

        config()->set('payroll_readiness.teacher_since_overrides', [$teacher->id => '2026-12-01']);

        $row = collect(app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'))['teachers'])
            ->firstWhere('teacher_id', $teacher->id);
        $this->assertSame('outside_calculator', $row['disposition']);
    }

    public function test_fixed_seasonal_teacher_has_zero_when_no_block_completed_in_window(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Щербак Сергей Викторович']);
        Teacher::factory()->count(22)->create();
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::query()->create([
            'course_id' => $course->id,
            'number' => 1,
            'is_active' => true,
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-06-24',
        ]);
        Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'status' => 'paid',
            'tariff' => 'block_1',
            'amount' => 80000,
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
        ]));
        TeacherPayout::query()->create([
            'teacher_id' => $teacher->id,
            'amount' => 24000,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => '2026-03-15',
        ]);

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-09-28'));
        $row = collect($report['teachers'])->firstWhere('teacher_id', $teacher->id);

        $this->assertSame('zero', $row['disposition']);
        $this->assertSame(0.0, $row['payable_rub']);
        $this->assertCount(1, $row['calculation']['blocks']);
        $this->assertSame('no_completed_block_in_current_window', $row['amount_basis']);
        $this->assertContains('seasonal:no_completed_block_in_current_window', $row['holds']);
    }

    public function test_current_window_net_uses_final_amount_after_direct_receipt_offset(): void
    {
        $teacher = Teacher::factory()->create([
            'name' => 'Уша Санка',
            'payout_currency' => 'RUB',
        ]);
        Teacher::factory()->count(22)->create();
        $this->seedPayable($teacher, '2026-09-02');
        $course = Course::query()->where('teacher_id', $teacher->id)->firstOrFail();
        Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'status' => 'paid',
            'tariff' => 'block_1',
            'amount' => 1000,
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $teacher->id,
            'foreign_amount' => 1000,
            'foreign_currency' => 'RUB',
            'created_at' => '2026-09-15 10:00:00',
        ]));

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $row = collect($report['teachers'])->firstWhere('teacher_id', $teacher->id);

        // School: 10,000 × 92% × 20% = 1,840.
        // Direct: 1,000 × 20% − 1,000 already held = −800.
        $this->assertSame(1040.0, $row['payable_rub']);
        $this->assertSame(977.6, $row['net_after_npd_rub']);
        $this->assertSame('current_window_recomputed', $row['amount_basis']);
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
        $this->assertSame('incomplete', $report['evidence']['tochka_bank_credits']['status']);
        $this->assertSame('incomplete', $report['evidence']['paypal_student_notifications']['status']);
        $this->assertSame('incomplete', $report['evidence']['xoom_edgar']['status']);
        $this->assertArrayNotHasKey('amount', $report['evidence']['private_manifest']);
    }

    public function test_replayed_or_future_private_evidence_fails_closed(): void
    {
        Teacher::factory()->count(23)->create();
        $path = storage_path('framework/testing/payroll-replayed-evidence.json');
        config()->set('payroll_readiness.evidence_manifest_path', $path);
        $hash = hash('sha256', 'replayed');
        File::put($path, json_encode([
            'sources' => [
                'payout_sheets' => ['as_of' => '2026-10-02', 'sha256' => $hash],
                'xoom_edgar' => ['as_of' => 'not-a-date', 'sha256' => hash('sha256', 'xoom-edgar')],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => File::delete($path));

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));

        $this->assertSame('incomplete', $report['evidence']['private_manifest']['status']);
        $this->assertStringContainsString('replayed', $report['evidence']['private_manifest']['note']);
    }

    public function test_paypal_notification_gap_holds_only_teacher_lines_using_the_unverified_receipt(): void
    {
        $affected = Teacher::factory()->create(['name' => 'Трефилова Елена']);
        $clean = Teacher::factory()->create(['name' => 'Толчельников Иван']);
        Teacher::factory()->count(21)->create();
        $this->seedPayable($affected, '2026-09-02');
        $this->seedPayable($clean, '2026-09-02');
        $this->seedFreshEvidence();
        $affectedCourse = Course::query()->where('teacher_id', $affected->id)->firstOrFail();
        Payment::withoutEvents(fn () => Payment::query()->create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $affectedCourse->id,
            'status' => 'paid',
            'tariff' => 'block_1',
            'amount' => 9000,
            'foreign_amount' => 100,
            'foreign_currency' => 'EUR',
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'provider' => null,
            'created_at' => '2026-09-15 10:00:00',
        ]));

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $affectedRow = collect($report['teachers'])->firstWhere('teacher_id', $affected->id);
        $cleanRow = collect($report['teachers'])->firstWhere('teacher_id', $clean->id);

        $this->assertSame(1, $report['evidence']['paypal_student_notifications']['receipt_count']);
        $this->assertSame(1, $report['evidence']['paypal_student_notifications']['unresolved_count']);
        $this->assertSame('held', $affectedRow['disposition']);
        $this->assertTrue(collect($affectedRow['holds'])->contains(fn (string $hold): bool => str_contains($hold, 'paypal_student_receipt_missing_notification')));
        $this->assertSame('payable', $cleanRow['disposition']);
    }

    public function test_xoom_evidence_is_scoped_to_edgar_not_other_foreign_teachers(): void
    {
        $edgar = Teacher::factory()->create(['name' => 'Лейтан Эдгар', 'payout_currency' => 'EUR']);
        $other = Teacher::factory()->create(['name' => 'Костина Екатерина', 'payout_currency' => 'EUR']);
        Teacher::factory()->count(21)->create();
        $this->seedPayable($edgar, '2026-09-02');
        $this->seedPayable($other, '2026-09-02');
        $this->seedFreshEvidence(includeXoom: false);

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $edgarRow = collect($report['teachers'])->firstWhere('teacher_id', $edgar->id);
        $otherRow = collect($report['teachers'])->firstWhere('teacher_id', $other->id);

        $this->assertSame('xoom_mg', $edgarRow['channel']);
        $this->assertSame('held', $edgarRow['disposition']);
        $this->assertContains('evidence_xoom_edgar_incomplete', $edgarRow['holds']);
        $this->assertSame('paypal_mg', $otherRow['channel']);
        $this->assertSame('payable', $otherRow['disposition']);
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

    public function test_funding_pool_excludes_configured_tax_wallet_account(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Трефилова Елена']);
        Teacher::factory()->count(21)->create();
        $this->seedPayable($teacher, '2026-09-01');
        $this->seedFreshEvidence();

        // …123456 = operating account (1000 ₽), …877617 = tax wallet
        // (100000 ₽). H5554 gap [2]: only the operating account funds
        // payouts, so a 2760 ₽ payable must stay unfunded.
        Http::fake(['*' => Http::response([
            'Data' => ['Balance' => [
                [
                    'accountId' => '40702810000000123456/RUB',
                    'type' => 'ClosingAvailable',
                    'Amount' => ['amount' => 1000, 'currency' => 'RUB'],
                    'dateTime' => '2026-10-01T08:00:00+03:00',
                ],
                [
                    'accountId' => '408028103000000877617/RUB',
                    'type' => 'ClosingAvailable',
                    'Amount' => ['amount' => 100000, 'currency' => 'RUB'],
                    'dateTime' => '2026-10-01T08:00:00+03:00',
                ],
            ]],
        ])]);
        Cache::forget('tochka.open_banking.balances');

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $row = collect($report['teachers'])->firstWhere('teacher_id', $teacher->id);

        $this->assertSame('unfunded', $row['funding_state']);
        $this->assertSame(2760.0, $row['remaining_obligation']);
    }

    public function test_paid_without_access_holds_only_the_affected_teacher_line(): void
    {
        $affected = Teacher::factory()->create(['name' => 'Трефилова Елена']);
        $clean = Teacher::factory()->create(['name' => 'Толчельников Иван']);
        Teacher::factory()->count(21)->create();
        $this->seedPayable($affected, '2026-09-01');
        $this->seedPayable($clean, '2026-09-01');
        $affectedCourse = Course::query()->where('teacher_id', $affected->id)->firstOrFail();
        $group = Group::query()->create(['name' => 'Access integrity fixture']);
        $affectedCourse->groups()->attach($group->id);
        $this->seedFreshEvidence();
        Http::fake(['*' => Http::response([
            'Data' => ['Balance' => [[
                'accountId' => '40702810000000123456/RUB',
                'type' => 'ClosingAvailable',
                'Amount' => ['amount' => 10000, 'currency' => 'RUB'],
                'dateTime' => '2026-10-01T08:00:00+03:00',
            ]]],
        ])]);
        Cache::forget('tochka.open_banking.balances');

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-10-01'));
        $affectedRow = collect($report['teachers'])->firstWhere('teacher_id', $affected->id);
        $cleanRow = collect($report['teachers'])->firstWhere('teacher_id', $clean->id);

        $this->assertSame('held', $affectedRow['disposition']);
        $this->assertStringStartsWith('paid_without_access:payment_', $affectedRow['holds'][0]);
        $this->assertSame('payable', $cleanRow['disposition']);
        $this->assertCount(1, $report['line_exceptions']);
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

    private function seedFreshEvidence(bool $includeXoom = true): void
    {
        $path = storage_path('framework/testing/payroll-readiness-evidence.json');
        config()->set('payroll_readiness.evidence_manifest_path', $path);
        config()->set('features.money_bank_statement_credits', true);
        config()->set('features.money_daily_reconciliation', true);
        config()->set('services.tochka.token', 'fixture-token');
        File::put($path, json_encode([
            'generated_at' => '2026-10-01T08:00:00+03:00',
            'sources' => collect($includeXoom ? ['payout_sheets', 'xoom_edgar'] : ['payout_sheets'])
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
