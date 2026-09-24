<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\Course;
use App\Models\MoneyAllocation;
use App\Models\MoneyMovement;
use App\Models\MoneyObligation;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Ledger\LedgerInvariantViolation;
use App\Services\Ledger\LedgerProjection;
use App\Services\Ledger\LedgerReplayConflict;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\LedgerWritesDisabled;
use App\Support\Kopecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H5443 (P1) — денежное ядро: инварианты БД и сервиса.
 *
 * Два слоя проверяются раздельно: сервис (понятные отказы, блокировки,
 * повтор по ключу) и триггеры БД (сырые INSERT/UPDATE/DELETE в обход
 * сервиса). Суммы — целые копейки.
 */
class LedgerCoreTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private LedgerProjection $projection;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 12:00:00');
        config(['features.money_ledger_core' => true]);

        $this->ledger = app(LedgerService::class);
        $this->projection = app(LedgerProjection::class);
        $this->student = User::factory()->create();
        $this->course = Course::factory()->create();
    }

    private function receipt(int $kopecks, string $key = 'r1', array $opts = []): MoneyMovement
    {
        return $this->ledger->receipt($key, $kopecks, $this->student->id, $this->course->id, now(), $opts);
    }

    private function rawMovement(array $attrs): void
    {
        DB::table('money_movements')->insert(array_merge([
            'movement_key' => 'raw-'.uniqid('', true),
            'type' => MoneyMovement::RECEIPT,
            'amount_kopecks' => 100,
            'user_id' => $this->student->id,
            'occurred_at' => now(),
            'created_at' => now(),
        ], $attrs));
    }

    private function assertLedgerRejects(string $message, callable $fn): void
    {
        try {
            $fn();
        } catch (QueryException|LedgerInvariantViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }
        $this->fail("Ожидался отказ «{$message}»");
    }

    // --- флаг, теневой прогон, миграция ------------------------------------

    public function test_flag_off_refuses_every_write(): void
    {
        config(['features.money_ledger_core' => false]);

        $this->expectException(LedgerWritesDisabled::class);
        $this->receipt(1000);
    }

    public function test_shadow_run_writes_through_triggers_and_always_rolls_back(): void
    {
        config(['features.money_ledger_core' => false]);

        $id = $this->ledger->shadow(function (LedgerService $l) {
            $r = $l->receipt('shadow', 5000, $this->student->id, $this->course->id, now());
            $this->assertSame(5000, $this->projection->refundableRemaining($r));

            return $r->id;
        });

        $this->assertIsInt($id);
        $this->assertSame(0, MoneyMovement::query()->count());
        $this->assertFalse($this->ledger->writable());
    }

    /** Число триггеров ядра — SQLite локально/CI, MariaDB в scratch-доказательстве. */
    private function ledgerTriggerCount(): int
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'money_%')->count()
            : DB::table('information_schema.triggers')->where('trigger_schema', DB::getDatabaseName())->where('trigger_name', 'like', 'money\\_%')->count();
    }

    public function test_migration_rolls_back_and_reapplies_without_touching_legacy_tables(): void
    {
        $paymentsColumns = Schema::getColumnListing('payments');
        $migration = require database_path('migrations/2026_09_24_150000_create_money_ledger_core_tables.php');

        $migration->down();
        foreach (['money_movements', 'money_allocations', 'money_obligations'] as $t) {
            $this->assertFalse(Schema::hasTable($t), $t);
        }
        $this->assertSame(0, $this->ledgerTriggerCount());

        $migration->up();
        $this->assertSame(9, $this->ledgerTriggerCount());
        $this->assertSame($paymentsColumns, Schema::getColumnListing('payments'));
        $this->assertSame(1000, $this->receipt(1000)->amount_kopecks);
    }

    // --- неизменяемость (D9) --------------------------------------------------

    public function test_posted_movement_cannot_be_updated_or_deleted_even_by_raw_sql(): void
    {
        $r = $this->receipt(10000);

        $this->assertLedgerRejects('posted movements are immutable', fn () => DB::table('money_movements')->where('id', $r->id)->update(['amount_kopecks' => 1]));
        $this->assertLedgerRejects('posted movements are immutable', fn () => DB::table('money_movements')->where('id', $r->id)->delete());
        $this->assertLedgerRejects('posted movements are immutable', fn () => $r->update(['reason' => 'x']));
        $this->assertLedgerRejects('posted movements are immutable', fn () => $r->delete());
        $this->assertSame(10000, (int) DB::table('money_movements')->where('id', $r->id)->value('amount_kopecks'));
    }

    public function test_allocations_are_immutable_and_obligation_terms_are_frozen(): void
    {
        $r = $this->receipt(10000);
        $block = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 10000);
        $a = $this->ledger->allocate($r, $block, 10000, 'a1');

        $this->assertLedgerRejects('posted allocations are immutable', fn () => DB::table('money_allocations')->where('id', $a->id)->update(['amount_kopecks' => 1]));
        $this->assertLedgerRejects('posted allocations are immutable', fn () => DB::table('money_allocations')->where('id', $a->id)->delete());
        $this->assertLedgerRejects('obligation terms are immutable', fn () => DB::table('money_obligations')->where('id', $block->id)->update(['price_kopecks' => 1, 'list_price_kopecks' => 1]));
        $this->assertLedgerRejects('obligations are never deleted', fn () => DB::table('money_obligations')->where('id', $block->id)->delete());

        $this->ledger->markDelivered($block, now());
        $this->assertLedgerRejects('delivery is write-once', fn () => DB::table('money_obligations')->where('id', $block->id)->update(['delivered_at' => null]));
        $this->assertLedgerRejects('write-once', fn () => $this->ledger->markDelivered($block->fresh(), now()->addDay()));
    }

    // --- форма и знак движения ---------------------------------------------

    public function test_trigger_enforces_sign_and_shape_for_raw_inserts(): void
    {
        $teacher = Teacher::factory()->create();

        $this->assertLedgerRejects('amount must be non-zero', fn () => $this->rawMovement(['amount_kopecks' => 0]));
        $this->assertLedgerRejects('inflow must be positive', fn () => $this->rawMovement(['amount_kopecks' => -100]));
        $this->assertLedgerRejects('outflow must be negative', fn () => $this->rawMovement(['type' => 'payout', 'amount_kopecks' => 100, 'teacher_id' => $teacher->id]));
        $this->assertLedgerRejects('unknown movement type', fn () => $this->rawMovement(['type' => 'gift']));
        $this->assertLedgerRejects('refund must name its source payment', fn () => $this->rawMovement(['type' => 'refund', 'amount_kopecks' => -100]));
        $this->assertLedgerRejects('payout needs a teacher', fn () => $this->rawMovement(['type' => 'payout', 'amount_kopecks' => -100]));
        $this->assertLedgerRejects('compensation needs reason and approver', fn () => $this->rawMovement(['type' => 'compensation', 'amount_kopecks' => -100]));
        $this->assertLedgerRejects('direct teacher receipt needs', fn () => $this->rawMovement(['type' => 'direct_teacher_receipt', 'teacher_id' => $teacher->id, 'received_account' => 'teacher_personal', 'source_currency' => 'RUB']));
        $this->assertLedgerRejects('teacher_personal account only', fn () => $this->rawMovement(['received_account' => 'teacher_personal']));
        $this->assertLedgerRejects('amount must be a positive number of kopecks', fn () => $this->receipt(0));
        $this->assertSame(0, MoneyMovement::query()->count());
    }

    // --- предел возврата (D12) -----------------------------------------------

    public function test_refund_is_capped_by_the_source_payment_in_service_and_in_database(): void
    {
        $r = $this->receipt(10000);

        $this->ledger->refund($r, 'f1', 6000, now());
        $this->assertLedgerRejects('refunds exceed the source payment', fn () => $this->ledger->refund($r, 'f2', 4001, now()));

        // Сырой INSERT в обход сервиса отбивает триггер.
        $this->assertLedgerRejects('refunds exceed the source payment', fn () => $this->rawMovement([
            'type' => 'refund', 'amount_kopecks' => -4001, 'refund_of_movement_id' => $r->id, 'cap_anchor_id' => $r->id,
        ]));
        // Подмена якоря, чтобы обойти предел, тоже отбивается.
        $this->assertLedgerRejects('refund-cap anchor mismatch', fn () => $this->rawMovement([
            'type' => 'refund', 'amount_kopecks' => -4001, 'refund_of_movement_id' => $r->id, 'cap_anchor_id' => null,
        ]));

        $this->ledger->refund($r, 'f3', 4000, now());
        $this->assertSame(0, $this->projection->refundableRemaining($r));
    }

    public function test_refunded_receipt_cannot_be_reversed_until_its_refunds_are(): void
    {
        $r = $this->receipt(10000);
        $f = $this->ledger->refund($r, 'f1', 3000, now());

        $this->assertLedgerRejects('refunds exceed the source payment', fn () => $this->ledger->reverse($r, 'rev-r', 'ошибка'));
        $this->assertSame(0, MoneyMovement::query()->where('type', 'reversal')->count());

        $this->ledger->reverse($f, 'rev-f', 'возврат отменён');
        $this->ledger->reverse($r, 'rev-r', 'ошибка');
        $this->assertSame(0, $this->projection->refundableRemaining($r));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    public function test_compensation_is_separate_and_does_not_consume_the_refund_cap(): void
    {
        $r = $this->receipt(10000);
        $admin = User::factory()->create();

        $c = $this->ledger->compensation('c1', 2500, $this->student->id, $this->course->id, 'перенос по вине школы', $admin->id, now(), $r);

        $this->assertSame(MoneyMovement::COMPENSATION, $c->type);
        $this->assertSame(10000, $this->projection->refundableRemaining($r));
        $this->assertSame(-2500, $this->projection->studentCourseSummary($this->student->id, $this->course->id)['compensated_kopecks']);
    }

    // --- доказательство и повтор ----------------------------------------------

    public function test_evidence_is_consumed_once(): void
    {
        $this->receipt(1000, 'r1', ['evidence_key' => 'tochka:TX-1']);

        $this->assertLedgerRejects('evidence already consumed', fn () => $this->receipt(1000, 'r2', ['evidence_key' => 'tochka:TX-1']));
        $this->assertSame(1, MoneyMovement::query()->count());
    }

    public function test_replay_with_same_key_is_idempotent_and_a_different_payload_conflicts(): void
    {
        $a = $this->receipt(1000, 'pay-42');
        $b = $this->receipt(1000, 'pay-42');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, MoneyMovement::query()->count());

        $this->expectException(LedgerReplayConflict::class);
        $this->receipt(1500, 'pay-42');
    }

    public function test_refund_replay_does_not_hit_the_cap(): void
    {
        $r = $this->receipt(10000);
        $f1 = $this->ledger->refund($r, 'f1', 10000, now());
        $f2 = $this->ledger->refund($r, 'f1', 10000, now());

        $this->assertSame($f1->id, $f2->id);
        $this->assertSame(0, $this->projection->refundableRemaining($r));
    }

    // --- сторно и корректировка (D9) --------------------------------------

    public function test_reversal_is_once_only_mirrors_the_original_and_removes_its_allocations(): void
    {
        $r = $this->receipt(8000);
        $b = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 8000);
        $this->ledger->allocate($r, $b, 8000, 'a1');

        $rev = $this->ledger->reverse($r, 'rev', 'двойная запись');

        $this->assertSame(-8000, $rev->amount_kopecks);
        $this->assertSame(0, $this->projection->chainNet($r->id));
        $this->assertSame(0, $this->projection->obligationAllocated($b->id));
        $this->assertLedgerRejects('never reversed', fn () => $this->ledger->reverse($rev, 'rev-rev', 'x'));
        $this->assertLedgerRejects('already reversed', fn () => $this->ledger->reverse($r, 'rev-2', 'x'));
        $this->assertLedgerRejects('reversal must mirror', fn () => $this->rawMovement([
            'type' => 'reversal', 'amount_kopecks' => -1, 'reverses_movement_id' => $b->id + 1000, 'root_movement_id' => $r->id,
        ]));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    public function test_correction_of_a_refunded_receipt_keeps_the_cap_and_totals_to_the_kopeck(): void
    {
        $r = $this->receipt(10000);
        $this->ledger->refund($r, 'f1', 4000, now());

        [$corr, $rev] = $this->ledger->correct($r, 'corr', 9000, 'сумма в выписке 90 ₽');

        $this->assertSame(9000, $corr->amount_kopecks);
        $this->assertSame(-10000, $rev->amount_kopecks);
        // Итог = оригинал + сторно + новая строка.
        $this->assertSame(9000, $this->projection->chainNet($r->id));
        $this->assertSame(5000, $this->projection->refundableRemaining($r));

        // Корректировка ниже уже возвращённого откатывается целиком — без висящего сторно.
        $before = MoneyMovement::query()->count();
        $this->assertLedgerRejects('refunds exceed the source payment', fn () => $this->ledger->correct($corr, 'corr-2', 3999, 'x'));
        $this->assertSame($before, MoneyMovement::query()->count());
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    public function test_correction_of_a_refund_reverses_first_and_respects_the_cap(): void
    {
        $r = $this->receipt(10000);
        $f = $this->ledger->refund($r, 'f1', 6000, now());

        [$corr] = $this->ledger->correct($f, 'fc', 7000, 'возврат был 70 ₽');
        $this->assertSame(-7000, $corr->amount_kopecks);
        $this->assertSame(3000, $this->projection->refundableRemaining($r));

        $this->assertLedgerRejects('refunds exceed the source payment', fn () => $this->ledger->correct($corr, 'fc2', 10001, 'x'));
        $this->assertSame(3000, $this->projection->refundableRemaining($r));
    }

    public function test_raw_correction_without_prior_reversal_of_an_outflow_is_rejected(): void
    {
        $r = $this->receipt(10000);
        $f = $this->ledger->refund($r, 'f1', 1000, now());

        $this->assertLedgerRejects('outflow correction must follow', fn () => $this->rawMovement([
            'type' => 'correction', 'amount_kopecks' => -500, 'corrects_movement_id' => $f->id,
            'root_movement_id' => $f->id, 'cap_anchor_id' => $r->id,
        ]));
    }

    // --- обязательства, скидка, депозит (D6, D7) -------------------------

    public function test_discount_reduces_price_then_deposit_counts_as_money_already_paid(): void
    {
        $deposit = $this->ledger->openDeposit('d1', $this->student->id, $this->course->id, 3000);
        $dr = $this->receipt(3000, 'dep-pay');
        $this->ledger->allocate($dr, $deposit, 3000, 'dep-a');

        // D7: 100 ₽ по договору − 10 ₽ скидки = 90 ₽; депозит 30 ₽ зачитывается после.
        $block = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 10000, 1000);
        $this->assertSame(9000, $block->price_kopecks);

        $this->ledger->applyDeposit($deposit, $block, 3000, 'apply-1');
        $state = $this->projection->obligationState($block->fresh());
        $this->assertSame(6000, $state['outstanding']);
        $this->assertSame(0, $this->projection->obligationAllocated($deposit->id));

        $pay = $this->receipt(6000, 'rest');
        $this->ledger->allocate($pay, $block, 6000, 'rest-a');
        $this->assertSame(0, $this->projection->obligationState($block->fresh())['outstanding']);

        // Повтор зачёта — no-op; зачёт сверх цены отбивается.
        $this->assertCount(2, $this->ledger->applyDeposit($deposit, $block, 3000, 'apply-1'));
        $this->assertLedgerRejects('deposit exceeds the obligation price', fn () => $this->ledger->applyDeposit($deposit, $block, 1, 'apply-2'));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    public function test_deposit_is_not_revenue_and_trial_is_recognized_only_after_the_lesson(): void
    {
        $deposit = $this->ledger->openDeposit('d1', $this->student->id, $this->course->id, 2000);
        $this->ledger->allocate($this->receipt(2000, 'dep'), $deposit, 2000, 'dep-a');
        $trial = $this->ledger->openTrial('t1', $this->student->id, $this->course->id, 50000);
        $this->ledger->allocate($this->receipt(50000, 'trial'), $trial, 50000, 'trial-a');

        $summary = $this->projection->studentCourseSummary($this->student->id, $this->course->id);
        $this->assertSame(0, $summary['recognized_kopecks']);
        $this->assertSame(2000, $summary['deposit_held_kopecks']);

        $this->ledger->markDelivered($trial, now());
        $this->assertSame(50000, $this->projection->studentCourseSummary($this->student->id, $this->course->id)['recognized_kopecks']);
        $this->assertLedgerRejects('only a lesson obligation can be delivered', fn () => $this->ledger->markDelivered($deposit, now()));
    }

    public function test_block_is_four_lessons_and_obligation_shape_is_enforced_by_the_database(): void
    {
        $base = ['user_id' => $this->student->id, 'course_id' => $this->course->id, 'list_price_kopecks' => 1000, 'discount_kopecks' => 0, 'price_kopecks' => 1000, 'created_at' => now(), 'updated_at' => now()];

        $this->assertLedgerRejects('a block is four lessons', fn () => DB::table('money_obligations')->insert($base + ['obligation_key' => 'x1', 'kind' => 'block', 'block_number' => 1, 'lessons_count' => 1]));
        $this->assertLedgerRejects('price must equal list minus discount', fn () => DB::table('money_obligations')->insert(array_merge($base, ['obligation_key' => 'x2', 'kind' => 'block', 'block_number' => 1, 'lessons_count' => 4, 'discount_kopecks' => 100])));
        $this->assertLedgerRejects('discount within 0..list', fn () => DB::table('money_obligations')->insert(array_merge($base, ['obligation_key' => 'x3', 'kind' => 'debt', 'discount_kopecks' => 2000, 'price_kopecks' => -1000])));
        $this->assertLedgerRejects('a trial is one lesson', fn () => DB::table('money_obligations')->insert($base + ['obligation_key' => 'x4', 'kind' => 'trial', 'lessons_count' => 4]));
        $this->assertSame(0, MoneyObligation::query()->count());
    }

    // --- распределения и явный остаток (D2) -------------------------------

    public function test_split_over_blocks_leaves_no_kopeck_behind_and_residue_is_explicit(): void
    {
        $r = $this->receipt(10001);
        $shares = Kopecks::split(10001, 3);
        foreach ($shares as $i => $share) {
            $b = $this->ledger->openBlock("b{$i}", $this->student->id, $this->course->id, $i + 1, $share);
            $this->ledger->allocate($r, $b, $share, "a{$i}");
        }
        $this->assertSame(10001, array_sum($shares));
        $this->assertSame(0, $this->projection->unallocatedResidue($r->id));

        $r2 = $this->receipt(5000, 'r2');
        $b = $this->ledger->openBlock('b9', $this->student->id, $this->course->id, 9, 3000);
        $this->ledger->allocate($r2, $b, 3000, 'a9');
        $this->assertSame(2000, $this->projection->unallocatedResidue($r2->id));

        $totals = $this->projection->controlTotals();
        $this->assertSame(15001, $totals['student_money_net_kopecks']);
        $this->assertSame($totals['student_money_net_kopecks'], $totals['allocated_kopecks'] + $totals['unallocated_residue_kopecks']);
        $this->assertSame(2000, $totals['unallocated_residue_kopecks']);
        $this->assertTrue($totals['balanced']);
    }

    public function test_allocation_caps_student_match_and_cancelled_obligations(): void
    {
        $r = $this->receipt(5000);
        $b = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 4000);
        $other = $this->ledger->openBlock('o1', User::factory()->create()->id, $this->course->id, 1, 9000);

        $this->assertLedgerRejects('obligation allocation outside 0..price', fn () => $this->ledger->allocate($r, $b, 4001, 'a1'));
        $this->ledger->allocate($r, $b, 4000, 'a1');
        $this->assertLedgerRejects('allocations exceed the movement', fn () => $this->ledger->allocate($r, $b, 1001, 'a2'));
        $this->assertLedgerRejects('different students', fn () => $this->ledger->allocate($r, $other, 500, 'a3'));

        $c = $this->ledger->openBlock('c1', $this->student->id, $this->course->id, 2, 1000);
        $this->ledger->cancel($c, now());
        $this->assertLedgerRejects('cannot fund a cancelled obligation', fn () => $this->ledger->allocate($r, $c, 500, 'a4'));

        // Сырой INSERT сверх движения — триггер.
        $this->assertLedgerRejects('allocations exceed the movement', fn () => DB::table('money_allocations')->insert([
            'allocation_key' => 'raw', 'movement_id' => $r->id, 'obligation_id' => $b->id, 'amount_kopecks' => 2000, 'created_at' => now(),
        ]));
    }

    public function test_partial_refund_reduces_the_named_block(): void
    {
        $r = $this->receipt(8000);
        $b1 = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 4000);
        $b2 = $this->ledger->openBlock('b2', $this->student->id, $this->course->id, 2, 4000);
        $this->ledger->allocate($r, $b1, 4000, 'a1');
        $this->ledger->allocate($r, $b2, 4000, 'a2');

        $this->ledger->refund($r, 'f1', 4000, now(), [$b2->id => 4000]);

        $this->assertSame(4000, $this->projection->obligationAllocated($b1->id));
        $this->assertSame(0, $this->projection->obligationAllocated($b2->id));
        $this->assertSame(4000, $this->projection->studentCourseSummary($this->student->id, $this->course->id)['net_paid_kopecks']);
    }

    // --- прямой платёж преподавателю (D16) -------------------------------

    public function test_direct_teacher_receipt_is_a_student_payment_plus_a_teacher_offset(): void
    {
        $teacher = Teacher::factory()->create();

        [$dr, $offset] = $this->ledger->directTeacherReceipt('dt1', 700000, $this->student->id, $this->course->id, $teacher->id, 'eur', 7000, 'proof:sha256:abc', now());

        $this->assertSame(MoneyMovement::ACCOUNT_TEACHER_PERSONAL, $dr->received_account);
        $this->assertSame('EUR', $dr->source_currency);
        $this->assertSame(-700000, $offset->amount_kopecks);
        $this->assertSame($dr->id, $offset->pairs_movement_id);
        $this->assertSame(700000, $this->projection->studentCourseSummary($this->student->id, $this->course->id)['received_kopecks']);

        // Повторное использование того же доказательства и второе погашение запрещены.
        $this->assertLedgerRejects('evidence already consumed', fn () => $this->ledger->directTeacherReceipt('dt2', 700000, $this->student->id, $this->course->id, $teacher->id, 'EUR', 7000, 'proof:sha256:abc', now()));
        $this->assertLedgerRejects('pairs_movement_id', fn () => $this->rawMovement([
            'type' => 'payout', 'amount_kopecks' => -700000, 'teacher_id' => $teacher->id, 'pairs_movement_id' => $dr->id,
        ]));

        // Сторно прямого платежа сторнирует и погашение.
        $this->ledger->reverse($dr, 'dt1-rev', 'платёж не подтверждён');
        $this->assertSame(0, $this->projection->chainNet($offset->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // --- свойство: случайные последовательности не ломают инварианты -----

    public function test_property_random_operation_sequences_never_break_invariants(): void
    {
        mt_srand(5443);
        $receipts = [];
        $blocks = [];
        $model = []; // независимая модель в целых копейках: receipt id => [net, refunded]
        $rejected = 0;

        for ($step = 0; $step < 160; $step++) {
            $op = mt_rand(0, 5);
            try {
                if ($op === 0 || $receipts === []) {
                    $amt = mt_rand(1, 500000);
                    $r = $this->receipt($amt, "r{$step}");
                    $receipts[] = $r;
                    $model[$r->id] = ['net' => $amt, 'refunded' => 0];
                    $blocks[$r->id] = $this->ledger->openBlock("b{$step}", $this->student->id, $this->course->id, $step, mt_rand(1, 600000));
                } elseif ($op === 1) {
                    $r = $receipts[array_rand($receipts)];
                    $ask = mt_rand(1, 600000);
                    $expectOk = $ask <= $model[$r->id]['net'] - $model[$r->id]['refunded'];
                    try {
                        $this->ledger->refund($r, "f{$step}", $ask, now());
                        $this->assertTrue($expectOk, 'возврат сверх остатка прошёл');
                        $model[$r->id]['refunded'] += $ask;
                    } catch (LedgerInvariantViolation $e) {
                        $this->assertFalse($expectOk, 'допустимый возврат отбит: '.$e->getMessage());
                        throw $e;
                    }
                } elseif ($op === 2) {
                    $r = $receipts[array_rand($receipts)];
                    $live = MoneyMovement::query()->where(fn ($q) => $q->where('id', $r->id)->orWhere('root_movement_id', $r->id))
                        ->whereIn('type', ['receipt', 'correction'])
                        ->whereNotExists(fn ($q) => $q->from('money_movements as x')->whereColumn('x.reverses_movement_id', 'money_movements.id'))
                        ->first();
                    $new = mt_rand(1, 600000);
                    $expectOk = $new >= $model[$r->id]['refunded'];
                    try {
                        $this->ledger->correct($live, "c{$step}", $new, 'проверка свойства');
                        $this->assertTrue($expectOk, 'корректировка ниже возвращённого прошла');
                        $model[$r->id]['net'] = $new;
                    } catch (LedgerInvariantViolation $e) {
                        $this->assertFalse($expectOk, 'допустимая корректировка отбита: '.$e->getMessage());
                        throw $e;
                    }
                } else {
                    $r = $receipts[array_rand($receipts)];
                    $this->ledger->allocate($r, $blocks[$r->id], mt_rand(1, 300000), "a{$step}");
                }
            } catch (LedgerInvariantViolation) {
                $rejected++;
            }

            $this->assertSame([], $this->projection->integrityBreaches(), "шаг {$step}");
        }

        foreach ($receipts as $r) {
            $this->assertSame($model[$r->id]['net'], $this->projection->chainNet($r->id), "цепочка {$r->id}");
            $this->assertSame($model[$r->id]['net'] - $model[$r->id]['refunded'], $this->projection->refundableRemaining($r), "остаток {$r->id}");
        }
        $totals = $this->projection->controlTotals();
        $this->assertSame($totals['student_money_net_kopecks'], $totals['allocated_kopecks'] + $totals['unallocated_residue_kopecks']);
        $this->assertGreaterThan(0, $rejected, 'последовательность должна проверять и отказы');
        $this->assertSame(0, MoneyAllocation::query()->where('amount_kopecks', 0)->count());
    }
}
