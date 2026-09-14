<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Services\Payroll\BackfillHistoryService;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H4597 — payroll:backfill-history: dry-run по умолчанию, идемпотентность,
 * fail-closed при расхождении живой базы с манифестом, и главный инвариант —
 * заведённый breakdown реально работает как paidShareKeys (отсечка движка).
 */
class PayrollBackfillHistoryTest extends TestCase
{
    use RefreshDatabase;

    private BackfillHistoryService $service;

    private Teacher $teacher;

    private Course $courseA;

    private Course $courseB;

    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BackfillHistoryService::class);

        $this->studentId = \App\Models\User::factory()->create()->id;
        $this->teacher = Teacher::factory()->create(['name' => 'Тест Преподаватель']);
        $this->courseA = Course::factory()->create([
            'teacher_id' => $this->teacher->id, 'title' => 'Курс А',
            'salary_type' => 'percent', 'salary_value' => 60,
        ]);
        $this->courseB = Course::factory()->create([
            'teacher_id' => $this->teacher->id, 'title' => 'Курс Б',
            'salary_type' => 'percent', 'salary_value' => 30,
        ]);
        foreach ([$this->courseA, $this->courseB] as $c) {
            CourseBlock::create(['course_id' => $c->id, 'number' => 1, 'is_active' => true, 'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31']);
            CourseBlock::create(['course_id' => $c->id, 'number' => 2, 'is_active' => true, 'starts_at' => '2026-02-01', 'ends_at' => '2026-02-28']);
        }
    }

    private function pay(Course $course, int $block, float $amount, string $received): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'course_id' => $course->id,
            'user_id' => $this->studentId,
            'amount' => $amount,
            'start_block' => $block,
            'end_block' => $block,
            'tariff' => 'block_'.$block,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'first_paid_at' => $received,
            'created_at' => $received,
        ]));
    }

    private function manifest(Payment $p1, Payment $p2): array
    {
        return [
            'dump_fingerprint' => ['moved' => false],
            'rows' => [
                [
                    'inventory_id' => 'T1',
                    'teacher_id' => $this->teacher->id,
                    'paid_at' => '2026-03-01',
                    'cutoff' => '2026-03-01',
                    'status' => 'keyed',
                    'period_month' => '2026-03',
                    'amount' => 20000.0,
                    'amount_foreign' => 200.0,
                    'payout_currency' => 'EUR',
                    'rate_pct' => 60.0,
                    'bank_slice_pct' => 92.0,
                    'base_rub' => 36231.88,
                    'checksum_school_rub' => 20000.0,
                    'expected_school_rub' => 20209.85,
                    'checksum_delta_pct' => 1.04,
                    'source_quote' => 'тестовая строка',
                    'comment' => 'H4597 backfill: тест',
                    'targets' => [
                        ['course_id' => $this->courseA->id, 'block' => 1],
                        ['course_id' => $this->courseB->id, 'block' => 1],
                    ],
                    'shares' => [
                        [
                            'course_id' => $this->courseA->id, 'block_number' => 1,
                            'payment_id' => $p1->id, 'user_id' => $this->studentId,
                            'share' => 20000.0, 'amount' => 20000.0,
                            'receipt' => '2026-01-15', 'covered' => 1,
                        ],
                        [
                            'course_id' => $this->courseB->id, 'block_number' => 1,
                            'payment_id' => $p2->id, 'user_id' => $this->studentId,
                            'share' => 16000.0, 'amount' => 16000.0,
                            'receipt' => '2026-01-20', 'covered' => 1,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_dry_run_writes_nothing(): void
    {
        $p1 = $this->pay($this->courseA, 1, 20000.0, '2026-01-15');
        $p2 = $this->pay($this->courseB, 1, 16000.0, '2026-01-20');

        $plan = $this->service->plan($this->manifest($p1, $p2));

        $this->assertCount(1, $plan['rows']);
        $this->assertSame(0, TeacherPayout::query()->count(), 'dry-run не должен писать');
    }

    public function test_apply_creates_rows_and_paid_share_keys_cut_engine(): void
    {
        $p1 = $this->pay($this->courseA, 1, 20000.0, '2026-01-15');
        $p2 = $this->pay($this->courseB, 1, 16000.0, '2026-01-20');
        $manifest = $this->manifest($p1, $p2);

        $plan = $this->service->plan($manifest);
        $created = $this->service->apply($plan);
        $this->assertSame(1, $created);

        $payout = TeacherPayout::query()->sole();
        $this->assertSame(20000.0, (float) $payout->amount);
        $this->assertSame('EUR', $payout->payout_currency);
        $this->assertSame(100.0, (float) $payout->exchange_rate);

        // Главный инвариант: breakdown читается paidShareKeys — доли отсечены.
        $keys = app(TeacherSalaryService::class)->paidShareKeys($this->teacher);
        $this->assertArrayHasKey($this->courseA->id.':1:'.$p1->id, $keys);
        $this->assertArrayHasKey($this->courseB->id.':1:'.$p2->id, $keys);
    }

    public function test_idempotent_second_run_skips(): void
    {
        $p1 = $this->pay($this->courseA, 1, 20000.0, '2026-01-15');
        $p2 = $this->pay($this->courseB, 1, 16000.0, '2026-01-20');
        $manifest = $this->manifest($p1, $p2);

        $this->service->apply($this->service->plan($manifest));
        $second = $this->service->plan($manifest);

        $this->assertSame([], $second['rows']);
        $this->assertCount(1, $second['skipped']);
        $this->assertSame(1, TeacherPayout::query()->count());
    }

    public function test_fail_closed_when_payment_changed(): void
    {
        $p1 = $this->pay($this->courseA, 1, 20000.0, '2026-01-15');
        $p2 = $this->pay($this->courseB, 1, 16000.0, '2026-01-20');
        $manifest = $this->manifest($p1, $p2);

        // База «уехала» после дампа: сумма платежа изменена.
        Payment::withoutEvents(fn () => $p2->update(['amount' => 999.0]));

        $this->expectException(\RuntimeException::class);
        $this->service->plan($manifest);
    }

    public function test_fail_closed_when_manifest_marks_moved(): void
    {
        $p1 = $this->pay($this->courseA, 1, 20000.0, '2026-01-15');
        $p2 = $this->pay($this->courseB, 1, 16000.0, '2026-01-20');
        $manifest = $this->manifest($p1, $p2);
        $manifest['dump_fingerprint']['moved'] = true;

        $this->expectException(\RuntimeException::class);
        $this->service->plan($manifest);
    }

    public function test_fail_closed_when_receipt_after_cutoff(): void
    {
        $p1 = $this->pay($this->courseA, 1, 20000.0, '2026-01-15');
        $p2 = $this->pay($this->courseB, 1, 16000.0, '2026-01-20');
        $manifest = $this->manifest($p1, $p2);
        $manifest['rows'][0]['cutoff'] = '2026-01-10'; // раньше прихода доли

        $this->expectException(\RuntimeException::class);
        $this->service->plan($manifest);
    }

    public function test_parked_rows_carry_no_keys(): void
    {
        $manifest = $this->manifest(
            $this->pay($this->courseA, 1, 20000.0, '2026-01-15'),
            $this->pay($this->courseB, 1, 16000.0, '2026-01-20'),
        );
        $manifest['rows'][] = [
            'inventory_id' => 'X1', 'teacher_id' => $this->teacher->id,
            'paid_at' => '2025-06-18', 'status' => 'parked',
            'amount' => null, 'amount_foreign' => 603.99, 'payout_currency' => 'EUR',
            'source_quote' => 'xoom row', 'park_reason' => 'tier-b',
        ];

        $plan = $this->service->plan($manifest);
        $this->assertCount(1, $plan['rows'], 'паркованная строка не попадает в план');
        $this->service->apply($plan);
        $keys = app(TeacherSalaryService::class)->paidShareKeys($this->teacher);
        $this->assertCount(2, $keys, 'ключи только от keyed-строки');
        $this->assertSame(Carbon::parse('2026-03-01')->toDateString(), TeacherPayout::sole()->paid_at->toDateString());
    }
}
