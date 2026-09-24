<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5443 (P1): report-only бэкфилл — план до копейки, теневой прогон через
 * триггеры, ни одной записи в легаси и в ядре. Только синтетические данные.
 */
class LedgerBackfillReportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $student;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 12:00:00');
        config(['features.money_ledger_core' => false]);
        $this->course = Course::factory()->create();
        $this->student = User::factory()->create();
        $teacher = Teacher::factory()->create();

        $this->ids['blocks'] = $this->pay(['amount' => '10000.00', 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 3, 'transaction_id' => 'T-1'])->id;
        $this->ids['full'] = $this->pay(['amount' => '5000.50', 'tariff' => 'full', 'transaction_id' => 'T-2'])->id;
        $this->ids['deposit'] = $this->pay(['amount' => '2000.00', 'tariff' => 'deposit'])->id;
        $this->ids['trial'] = $this->pay(['amount' => '500.00', 'tariff' => 'trial', 'discount_amount' => '100.00'])->id;
        $this->ids['direct'] = $this->pay(['amount' => '3000.00', 'tariff' => 'full', 'received_account' => Payment::RECEIVED_TEACHER, 'received_by_teacher_id' => $teacher->id, 'transaction_id' => 'T-3'])->id;
        $this->ids['direct_bad_account'] = $this->pay(['amount' => '1000.00', 'tariff' => 'full', 'received_account' => 'teacher', 'received_by_teacher_id' => $teacher->id])->id;
        $this->ids['dup_txn'] = $this->pay(['amount' => '700.00', 'tariff' => 'full', 'transaction_id' => 'T-2'])->id;
        $this->ids['unlinked_outflow'] = $this->pay(['amount' => '-400.00', 'tariff' => 'Расход'])->id;
        $this->ids['refund_ok'] = $this->pay(['amount' => '-3000.00', 'tariff' => 'Расход', 'refund_of_payment_id' => $this->ids['blocks']])->id;
        $this->ids['small'] = $this->pay(['amount' => '100.00', 'tariff' => 'full'])->id;
        $this->ids['refund_over'] = $this->pay(['amount' => '-150.00', 'tariff' => 'Расход', 'refund_of_payment_id' => $this->ids['small']])->id;
    }

    private function pay(array $attrs): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'paid',
            'is_conditional' => false,
            'received_account' => Payment::RECEIVED_SCHOOL,
        ], $attrs)));
    }

    /** @return array<string, mixed> */
    private function report(bool $shadow): array
    {
        $path = tempnam(sys_get_temp_dir(), 'h5443');
        $args = ['--json' => $path];
        if ($shadow) {
            $args['--shadow'] = true;
        }
        $this->artisan('money:ledger-backfill-report', $args)->assertExitCode(0);
        $json = json_decode((string) file_get_contents($path), true);
        unlink($path);

        return $json;
    }

    private function legacyFingerprint(): string
    {
        return md5(json_encode(DB::table('payments')->orderBy('id')->get()->all()));
    }

    public function test_plan_mode_balances_to_the_kopeck_and_writes_nothing(): void
    {
        $before = $this->legacyFingerprint();
        $r = $this->report(false);

        $this->assertSame($before, $this->legacyFingerprint());
        $this->assertSame(0, $r['db_writes_legacy']);
        $this->assertSame(0, $r['db_writes_ledger_rolled_back']);
        $this->assertSame(0, $r['totals']['drift_kopecks']);
        $this->assertSame($r['ledger_rows_before'], $r['ledger_rows_after']);

        // receipts 10000 + 5000.50 + 2000 + 500 + 3000 + 1000 + 700 + 100 − refunds 3000 − 150
        $this->assertSame(1_915_050, $r['totals']['legacy_net_kopecks']);
        // Распределено: 3 блока (10000) + депозит 2000 + пробное 500; остальное — явный остаток.
        $this->assertSame(1_250_000, $r['totals']['plan_allocated_kopecks']);
        $this->assertSame(2_230_050 - 1_250_000, $r['totals']['plan_residue_kopecks']);
        $this->assertSame(-40_000, $r['totals']['out_of_scope_kopecks']);

        $a = $r['anomalies'];
        $this->assertSame([$this->ids['unlinked_outflow']], $a['outflow_without_source_link']['payment_ids']);
        $this->assertSame([$this->ids['dup_txn']], $a['evidence_reused']['payment_ids']);
        $this->assertSame([$this->ids['direct_bad_account']], $a['received_account_not_teacher_personal']['payment_ids']);
        $this->assertSame([$this->ids['direct_bad_account']], $a['direct_receipt_without_evidence']['payment_ids']);
        $this->assertSame([$this->ids['small']], $a['legacy_refunds_exceed_source']['payment_ids']);
        $this->assertContains($this->ids['full'], $a['purpose_unknown_left_unallocated']['payment_ids']);
        $this->assertSame(1, $r['categories']['receipt_block']['families']);
    }

    public function test_shadow_mode_posts_through_triggers_and_rolls_everything_back(): void
    {
        $before = $this->legacyFingerprint();
        $r = $this->report(true);

        $this->assertSame($before, $this->legacyFingerprint());
        $this->assertFalse(config('features.money_ledger_core'), 'shadow never needs the live flag');
        $this->assertSame(0, $r['db_writes_legacy']);
        $this->assertGreaterThan(0, $r['db_writes_ledger_rolled_back']);
        $this->assertSame(['money_movements' => 0, 'money_allocations' => 0, 'money_obligations' => 0], $r['ledger_rows_after']);
        $this->assertSame(0, DB::table('money_movements')->count());

        $s = $r['shadow'];
        // 8 поступлений: 6 проведены, 2 отклонены ядром (прямой без доказательства; возврат сверх источника).
        $this->assertSame(6, $s['posted']);
        $this->assertSame(2, $s['rejected']);
        $this->assertSame([], $s['integrity_breaches']);
        $this->assertSame([$this->ids['small']], $r['anomalies']['shadow_rejected: ledger: refunds exceed the source payment']['payment_ids']);
        $this->assertSame([$this->ids['direct_bad_account']], $r['anomalies']['shadow_rejected: ledger: direct teacher receipt needs teacher and evidence']['payment_ids']);
        $this->assertArrayNotHasKey('shadow_net_mismatch', $r['anomalies']);

        // Нетто ядра = нетто легаси проведённых семей (минус отклонённые 1000 и 100−150).
        $this->assertSame(1_915_050 - 100_000 - (10_000 - 15_000), $s['ledger_net_kopecks']);
        // В срезе ядра нет дрейфа: деньги студентов = распределено + явный остаток.
        $ct = $s['control_totals'];
        $this->assertSame($ct['student_money_net_kopecks'], $ct['allocated_kopecks'] + $ct['unallocated_residue_kopecks']);
        $this->assertTrue($ct['balanced']);
    }

    public function test_linked_refund_to_a_non_receipt_is_reported_not_posted(): void
    {
        $orphan = $this->pay(['amount' => '-50.00', 'tariff' => 'Расход', 'refund_of_payment_id' => $this->ids['unlinked_outflow']])->id;

        $r = $this->report(true);

        $this->assertSame([$orphan], $r['anomalies']['linked_refund_source_not_a_paid_receipt']['payment_ids']);
        $this->assertSame(0, DB::table('money_movements')->count());
    }
}
