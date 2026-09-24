<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\TeacherCompensationAssignment;
use App\Models\TeacherCompensationTerm;
use App\Models\TeacherPayout;
use App\Models\TeacherPayoutPackage;
use App\Models\TeacherPayoutPackageLine;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payout\PayoutPackageService;
use App\Services\Payout\PayoutWritesDisabled;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Artisan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5444 (P2) — стык с денежным ядром P1: удержание возврата ровно один раз
 * (D10/D11) и пара «прямое получение денег преподавателем» (D16), плюс
 * отчёт сверки со старыми выплатами.
 */
class PayoutRefundAndDirectReceiptTest extends TestCase
{
    use RefreshDatabase;

    private PayoutPackageService $packages;

    private LedgerService $ledger;

    private Teacher $teacher;

    private Course $course;

    private User $student;

    private TeacherCompensationAssignment $assignment;

    private TeacherCompensationTerm $term;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 12:00:00');
        config(['features.money_payout_packages' => true, 'features.money_ledger_core' => true]);

        $this->packages = app(PayoutPackageService::class);
        $this->ledger = app(LedgerService::class);
        $this->teacher = Teacher::factory()->create();
        $this->course = Course::factory()->create();
        $this->student = User::factory()->create();

        $this->term = TeacherCompensationTerm::create([
            'term_key' => 'term:d11:v1',
            'teacher_id' => $this->teacher->id,
            'version' => 1,
            'compensation_type' => TeacherCompensationTerm::TYPE_PERCENT,
            'rate_ppm' => 300_000,
            'effective_from' => '2026-01-01',
            'confirmed_by' => 1,
            'confirmed_at' => now(),
        ]);

        $this->assignment = TeacherCompensationAssignment::create([
            'assignment_key' => 'assign:d11',
            'teacher_id' => $this->teacher->id,
            'term_id' => $this->term->id,
            'scope_kind' => TeacherCompensationAssignment::SCOPE_COURSE,
            'course_id' => $this->course->id,
            'effective_from' => '2026-01-01',
            'confirmed_by' => 1,
            'confirmed_at' => now(),
        ]);
    }

    private function package(): TeacherPayoutPackage
    {
        $package = $this->packages->draft($this->teacher->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->packages->addLine($package, "{$package->package_key}:base", TeacherPayoutPackageLine::KIND_BASE, 300_000, [
            'assignment_id' => $this->assignment->id,
            'term_id' => $this->term->id,
        ]);

        return $package;
    }

    /** Обязательство за блок + оплата + возврат по нему, всё через P1-ядро. */
    private function refundedBlock(bool $delivered = false, int $blockNumber = 1): array
    {
        $obligation = $this->ledger->openBlock(
            "obl:{$blockNumber}",
            $this->student->id,
            $this->course->id,
            $blockNumber,
            100_000,
        );

        $receipt = $this->ledger->receipt("rcpt:{$blockNumber}", 100_000, $this->student->id, $this->course->id, now());
        $this->ledger->allocate($receipt, $obligation, 100_000, "alloc:{$blockNumber}");

        if ($delivered) {
            $this->ledger->markDelivered($obligation, now()->subDay());
        }

        // Возврат за неоказанный блок явно называет обязательство, которое уменьшает (D10).
        $refund = $this->ledger->refund($receipt, "rfnd:{$blockNumber}", 100_000, now(), [$obligation->id => -100_000]);

        return [$obligation->refresh(), $refund];
    }

    public function test_a_refund_reduces_salary_exactly_once(): void
    {
        [$obligation, $refund] = $this->refundedBlock();
        $package = $this->package();

        $first = $this->packages->deductRefund($package, $refund->id, $obligation->id, 30_000);
        $second = $this->packages->deductRefund($package, $refund->id, $obligation->id, 30_000);

        // Повтор по стабильному ключу — тот же факт, не второе удержание.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(-30_000, (int) $package->refresh()->refund_adjustment_kopecks);
        $this->assertSame(270_000, (int) $package->total_kopecks);
    }

    public function test_the_database_refuses_a_second_deduction_of_the_same_refund(): void
    {
        [$obligation, $refund] = $this->refundedBlock();
        $package = $this->package();
        $this->packages->deductRefund($package, $refund->id, $obligation->id, 30_000);

        // Другой ключ строки, та же пара «возврат + обязательство» — unique-индекс.
        $this->expectException(QueryException::class);

        DB::table('teacher_payout_package_lines')->insert([
            'line_key' => 'sneaky-second-deduction',
            'package_id' => $package->id,
            'kind' => 'refund_adjustment',
            'amount_kopecks' => -30_000,
            'refund_movement_id' => $refund->id,
            'obligation_id' => $obligation->id,
        ]);
    }

    public function test_a_delivered_block_is_never_deducted_from_salary(): void
    {
        [$obligation, $refund] = $this->refundedBlock(delivered: true);
        $package = $this->package();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('a delivered obligation is never deducted from salary');

        $this->packages->deductRefund($package, $refund->id, $obligation->id, 30_000);
    }

    public function test_a_direct_receipt_is_offset_against_the_payout_once(): void
    {
        [$receipt] = $this->ledger->directTeacherReceipt(
            'direct:1', 50_000, $this->student->id, $this->course->id, $this->teacher->id,
            'RUB', 50_000, 'photo:receipt:1', now(),
        );

        $package = $this->package();
        $line = $this->packages->offsetDirectReceipt($package, $receipt->id, 50_000, 'photo:receipt:1:offset');

        $this->assertSame(-50_000, (int) $line->amount_kopecks);
        $this->assertSame(250_000, (int) $package->refresh()->total_kopecks);

        // То же доказательство второй раз — unique на движении.
        $this->expectException(QueryException::class);
        DB::table('teacher_payout_package_lines')->insert([
            'line_key' => 'second-offset',
            'package_id' => $package->id,
            'kind' => 'direct_receipt_offset',
            'amount_kopecks' => -50_000,
            'direct_receipt_movement_id' => $receipt->id,
            'evidence_key' => 'photo:receipt:1:again',
        ]);
    }

    public function test_an_offset_cannot_exceed_what_the_teacher_actually_received(): void
    {
        [$receipt] = $this->ledger->directTeacherReceipt(
            'direct:2', 50_000, $this->student->id, $this->course->id, $this->teacher->id,
            'RUB', 50_000, 'photo:receipt:2', now(),
        );

        $package = $this->package();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot exceed the money the teacher received');

        $this->packages->offsetDirectReceipt($package, $receipt->id, 60_000, 'photo:receipt:2:offset');
    }

    public function test_the_legacy_comparison_names_an_unresolved_rate_instead_of_guessing(): void
    {
        // Легаси-выплата преподавателю БЕЗ датированного назначения — форма H5250.
        $offline = Teacher::factory()->create();
        TeacherPayout::create([
            'teacher_id' => $offline->id,
            'amount' => 12_000,
            'paid_at' => '2026-09-10',
            'comment' => 'офлайн, ставка спорна',
        ]);

        $this->artisan('money:payout-package-compare', ['--json' => true])
            ->assertSuccessful();

        $output = Artisan::output();
        $this->assertStringContainsString('unresolved_rate', $output);
        $this->assertStringNotContainsString('"delta_kopecks": 0', $output);
    }

    public function test_the_comparison_ties_a_matching_package_to_its_legacy_row(): void
    {
        $package = $this->package();
        $this->packages->approve($package, 1);

        TeacherPayout::create([
            'teacher_id' => $this->teacher->id,
            'amount' => 3_000, // 300 000 копеек
            'paid_at' => '2026-09-10',
        ]);

        $this->artisan('money:payout-package-compare', ['--json' => true])->assertSuccessful();

        $output = Artisan::output();
        $this->assertStringContainsString('"class": "tie"', $output);
    }

    public function test_writes_are_off_without_the_flag(): void
    {
        config(['features.money_payout_packages' => false]);

        $this->expectException(PayoutWritesDisabled::class);
        $this->packages->draft($this->teacher->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    }
}
