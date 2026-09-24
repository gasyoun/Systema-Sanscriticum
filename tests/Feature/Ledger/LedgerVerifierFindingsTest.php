<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\Course;
use App\Models\MoneyAllocation;
use App\Models\MoneyMovement;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Ledger\LedgerInvariantViolation;
use App\Services\Ledger\LedgerProjection;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\LedgerWritesDisabled;
use App\Services\Ledger\LegacyLedgerMapper;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * H5443 (P1) — пины находок независимого денежного ревью (24-09-2026).
 * Каждая находка проверена на двух слоях: сервис и сырой INSERT мимо него.
 */
class LedgerVerifierFindingsTest extends TestCase
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

    private function receipt(int $kopecks, string $key): MoneyMovement
    {
        return $this->ledger->receipt($key, $kopecks, $this->student->id, $this->course->id, now());
    }

    private function raw(string $table, array $attrs): void
    {
        $base = $table === 'money_movements'
            ? ['movement_key' => 'raw-'.uniqid('', true), 'type' => MoneyMovement::RECEIPT, 'amount_kopecks' => 100, 'user_id' => $this->student->id, 'course_id' => $this->course->id, 'occurred_at' => now()]
            : ['allocation_key' => 'raw-'.uniqid('', true)];
        DB::table($table)->insert(array_merge($base, ['created_at' => now()], $attrs));
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

    // 1. BLOCKER: погашение D16 не сторнируется и не корректируется отдельно.
    public function test_direct_receipt_offset_lives_and_dies_only_with_its_receipt(): void
    {
        $teacher = Teacher::factory()->create();
        [$dr, $offset] = $this->ledger->directTeacherReceipt('dt', 500000, $this->student->id, $this->course->id, $teacher->id, 'RUB', 500000, 'proof:1', now());

        $this->assertLedgerRejects('reversed only together with its receipt', fn () => $this->ledger->reverse($offset, 'off-rev', 'x'));
        $this->assertLedgerRejects('never corrected', fn () => $this->ledger->correct($offset, 'off-corr', 400000, 'x'));
        $this->assertLedgerRejects('never corrected', fn () => $this->ledger->correct($dr, 'dr-corr', 400000, 'x'));

        // Сырой обход: сторно погашения раньше платежа — отказ триггера.
        $this->assertLedgerRejects('reversed only after its receipt', fn () => $this->raw('money_movements', [
            'type' => 'reversal', 'amount_kopecks' => 500000, 'teacher_id' => $teacher->id,
            'reverses_movement_id' => $offset->id, 'root_movement_id' => $offset->id,
        ]));
        $this->assertLedgerRejects('never corrected', fn () => $this->raw('money_movements', [
            'type' => 'correction', 'amount_kopecks' => -400000, 'teacher_id' => $teacher->id,
            'corrects_movement_id' => $offset->id, 'root_movement_id' => $offset->id,
        ]));
        $this->assertSame([], $this->projection->integrityBreaches());

        // Сырое сторно одного платежа (без погашения) видно как нарушение.
        $this->raw('money_movements', [
            'type' => 'reversal', 'amount_kopecks' => -500000, 'teacher_id' => $teacher->id,
            'reverses_movement_id' => $dr->id, 'root_movement_id' => $dr->id, 'cap_anchor_id' => $dr->id,
        ]);
        $this->assertSame([$dr->id], $this->projection->integrityBreaches()['direct_receipt_offset_mismatch'] ?? null);
    }

    public function test_service_reversal_of_a_direct_receipt_reverses_receipt_then_offset(): void
    {
        $teacher = Teacher::factory()->create();
        [$dr, $offset] = $this->ledger->directTeacherReceipt('dt', 500000, $this->student->id, $this->course->id, $teacher->id, 'RUB', 500000, 'proof:2', now());

        $rev = $this->ledger->reverse($dr, 'dt-rev', 'не подтверждён');
        $this->assertSame($dr->id, $rev->reverses_movement_id);
        $this->assertSame(0, $this->projection->chainNet($dr->id));
        $this->assertSame(0, $this->projection->chainNet($offset->id));
        $this->assertSame([], $this->projection->integrityBreaches());
        // Повтор — тот же факт.
        $this->assertSame($rev->id, $this->ledger->reverse($dr, 'dt-rev', 'не подтверждён')->id);
    }

    // 2. BLOCKER: обратное распределение только своим движением, цепочкой или возвратом.
    public function test_deallocation_cannot_ride_a_foreign_movement(): void
    {
        $r1 = $this->receipt(5000, 'r1');
        $r2 = $this->receipt(4000, 'r2');
        $block = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 8000);
        $a1 = $this->ledger->allocate($r1, $block, 4000, 'a1');
        $this->ledger->allocate($r2, $block, 4000, 'a2');

        $this->assertLedgerRejects('must ride the target movement', fn () => $this->ledger->deallocate($a1, 'x1', $r2));
        $this->assertLedgerRejects('must ride the target movement', fn () => $this->raw('money_allocations', [
            'movement_id' => $r2->id, 'obligation_id' => $block->id, 'amount_kopecks' => -4000, 'reverses_allocation_id' => $a1->id,
        ]));

        // Возврат по корню цепочки — законный носитель; своё движение — тоже.
        $refund = $this->ledger->refund($r1, 'f1', 1000, now());
        $this->assertLedgerRejects('allocations exceed the movement', fn () => $this->ledger->deallocate($a1, 'x2', $refund));
        $this->ledger->deallocate($a1, 'x3');
        $this->assertSame(4000, $this->projection->obligationAllocated($block->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    public function test_a_refund_reduces_an_obligation_only_by_what_its_payment_funded(): void
    {
        $r1 = $this->receipt(6000, 'r1');
        $r2 = $this->receipt(4000, 'r2');
        $block = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 8000);
        $this->ledger->allocate($r1, $block, 4000, 'a1');
        $this->ledger->allocate($r2, $block, 4000, 'a2');

        // r1 внесла в блок 4000 — снять 5000 её возвратом нельзя (1000 чужих денег r2).
        $this->assertLedgerRejects('only by what it funded', fn () => $this->ledger->refund($r1, 'f1', 5000, now(), [$block->id => 5000]));
        $this->ledger->refund($r1, 'f2', 5000, now(), [$block->id => 4000]);
        $this->assertSame(4000, $this->projection->obligationAllocated($block->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // 3. MAJOR: признанное (проведённое) занятие возвратом не уменьшается.
    public function test_refund_cannot_unrecognise_a_delivered_block_but_a_reversal_can(): void
    {
        $r = $this->receipt(8001, 'r');
        $b1 = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 4000);
        $b2 = $this->ledger->openBlock('b2', $this->student->id, $this->course->id, 2, 4000);
        $a1 = $this->ledger->allocate($r, $b1, 4000, 'a1');
        $this->ledger->allocate($r, $b2, 4000, 'a2');
        $this->ledger->markDelivered($b1, now());

        $this->assertLedgerRejects('delivered obligation is reduced only by a reversal', fn () => $this->ledger->refund($r, 'f1', 4000, now(), [$b1->id => 4000]));
        $this->assertLedgerRejects('delivered obligation is reduced only by a reversal', fn () => $this->ledger->deallocate($a1, 'd1'));
        $this->assertSame(4000, $this->projection->obligationState($b1->fresh())['recognized']);

        // Возврат за непроведённый блок — можно.
        $this->ledger->refund($r, 'f2', 4000, now(), [$b2->id => 4000]);
        $this->assertSame(0, $this->projection->obligationAllocated($b2->id));

        // Ошибочная проводка снимается сторно даже с проведённого блока.
        $this->ledger->reverse($this->ledger->refund($r, 'f3', 1, now()), 'f3-rev', 'ошибка');
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // 5. MINOR: зачёт депозита берёт живую строку семьи, не сторнированную.
    public function test_deposit_application_survives_a_corrected_funding_receipt(): void
    {
        $deposit = $this->ledger->openDeposit('dep', $this->student->id, $this->course->id, 3000);
        $r = $this->receipt(3000, 'r');
        $this->ledger->allocate($r, $deposit, 3000, 'dep-a');
        // Корректировка сама наследует распределение на депозит (в пределах новой суммы).
        [$corr] = $this->ledger->correct($r, 'r-corr', 2500, 'сумма в банке 25 ₽');
        $this->assertSame(2500, $this->projection->obligationAllocated($deposit->id));

        $block = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 9000);
        $this->assertLedgerRejects('deposit holds less than asked', fn () => $this->ledger->applyDeposit($deposit, $block, 2501, 'ap-x'));
        $made = $this->ledger->applyDeposit($deposit, $block, 2500, 'ap-1');
        $this->assertSame([$corr->id], array_values(array_unique(array_map(fn (MoneyAllocation $a) => $a->movement_id, $made))));
        $this->assertSame(0, $this->projection->obligationAllocated($deposit->id));
        $this->assertSame(2500, $this->projection->obligationAllocated($block->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    public function test_deposit_application_counts_refunds_of_the_funding_payment(): void
    {
        $deposit = $this->ledger->openDeposit('dep', $this->student->id, $this->course->id, 3000);
        $r = $this->receipt(3000, 'r');
        $this->ledger->allocate($r, $deposit, 3000, 'dep-a');
        $this->ledger->refund($r, 'f', 1000, now(), [$deposit->id => 1000]);

        $block = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 9000);
        $this->assertLedgerRejects('deposit holds less than asked', fn () => $this->ledger->applyDeposit($deposit, $block, 2001, 'ap-x'));
        $this->ledger->applyDeposit($deposit, $block, 2000, 'ap-1');
        $this->assertSame(0, $this->projection->obligationAllocated($deposit->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // 6. MINOR: прямой Model::create() тоже упирается во флаг.
    public function test_models_refuse_direct_writes_when_the_flag_is_off(): void
    {
        config(['features.money_ledger_core' => false]);

        $this->expectException(LedgerWritesDisabled::class);
        MoneyMovement::query()->create([
            'movement_key' => 'direct', 'type' => MoneyMovement::RECEIPT, 'amount_kopecks' => 100,
            'user_id' => $this->student->id, 'occurred_at' => now(),
        ]);
    }

    public function test_models_write_inside_a_shadow_run(): void
    {
        config(['features.money_ledger_core' => false]);

        $count = $this->ledger->shadow(function () {
            MoneyMovement::query()->create([
                'movement_key' => 'shadow', 'type' => MoneyMovement::RECEIPT, 'amount_kopecks' => 100,
                'user_id' => $this->student->id, 'occurred_at' => now(),
            ]);

            return MoneyMovement::query()->count();
        });
        $this->assertSame(1, $count);
        $this->assertSame(0, MoneyMovement::query()->count());
    }

    // 7. MINOR: личность студента — у денег студента, возврата и сторно.
    public function test_student_identity_rules(): void
    {
        $other = User::factory()->create();
        $r = $this->receipt(5000, 'r');

        $this->assertLedgerRejects('student money names its student', fn () => $this->raw('money_movements', ['user_id' => null]));
        $this->assertLedgerRejects('belongs to the student of its source payment', fn () => $this->raw('money_movements', [
            'type' => 'refund', 'amount_kopecks' => -1000, 'user_id' => $other->id, 'refund_of_movement_id' => $r->id, 'cap_anchor_id' => $r->id,
        ]));
        $this->assertLedgerRejects('belong to the student of their chain', fn () => $this->raw('money_movements', [
            'type' => 'reversal', 'amount_kopecks' => -5000, 'user_id' => $other->id, 'reverses_movement_id' => $r->id, 'root_movement_id' => $r->id, 'cap_anchor_id' => $r->id,
        ]));
    }

    // 8. MINOR: маппер решает «> 0» без float.
    public function test_mapper_positive_decimal_check_uses_no_float(): void
    {
        $m = new ReflectionMethod(LegacyLedgerMapper::class, 'positiveDecimal');
        foreach (['0', '0.00', '', null, '-5.00', '-0.01'] as $v) {
            $this->assertFalse($m->invoke(null, $v), var_export($v, true));
        }
        foreach (['0.01', '5', '10.50', '0.0001'] as $v) {
            $this->assertTrue($m->invoke(null, $v), var_export($v, true));
        }
    }

    // --- повторное ревью (24-09-2026): N1–N5 и кап семьи ------------------

    // N1a: на строку сторно нельзя повесить свободное распределение.
    public function test_n1a_reversal_row_cannot_carry_a_free_allocation(): void
    {
        $r = $this->receipt(8000, 'r');
        $b1 = $this->ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 4000);
        $this->ledger->allocate($r, $b1, 4000, 'a1');
        $this->ledger->markDelivered($b1, now());
        [, $rev] = $this->ledger->correct($r, 'c', 8000, 'та же сумма');

        $this->assertLedgerRejects('carries only the mirrors', fn () => $this->ledger->allocate($rev, $b1, 1, 'free'));
        $this->assertLedgerRejects('carries only the mirrors', fn () => $this->raw('money_allocations', [
            'movement_id' => $rev->id, 'obligation_id' => $b1->id, 'amount_kopecks' => -4000,
        ]));
        $this->assertSame(4000, $this->projection->obligationState($b1->fresh())['recognized']);
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // N1b: снять распределение корректировки сторно корня нельзя.
    public function test_n1b_deallocation_via_a_reversal_of_another_chain_row_is_refused(): void
    {
        $r = $this->receipt(4000, 'r');
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 4000);
        $this->ledger->allocate($r, $b, 4000, 'a');
        $this->ledger->markDelivered($b, now());
        [$corr, $rev] = $this->ledger->correct($r, 'c', 4000, 'x');
        $carried = MoneyAllocation::query()->where('movement_id', $corr->id)->firstOrFail();

        $this->assertLedgerRejects('carries only the mirrors', fn () => $this->ledger->deallocate($carried, 'd', $rev));
        $this->assertSame(4000, $this->projection->obligationAllocated($b->id));
    }

    // N1c: «корректировка на ту же сумму + возврат» не вынимает деньги проведённого занятия.
    public function test_n1c_correct_then_refund_cannot_empty_a_delivered_block(): void
    {
        $r = $this->receipt(4000, 'r');
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 4000);
        $this->ledger->allocate($r, $b, 4000, 'a');
        $this->ledger->markDelivered($b, now());

        $this->ledger->correct($r, 'c', 4000, 'та же сумма');
        $this->assertSame(4000, $this->projection->obligationAllocated($b->id), 'корректировка наследует распределение');
        $this->assertLedgerRejects('exceeds the unallocated residue', fn () => $this->ledger->refund($r, 'f', 4000, now()));
        $this->assertLedgerRejects('delivered obligation is reduced only by a reversal', fn () => $this->ledger->refund($r, 'f2', 4000, now(), [$b->id => 4000]));
        $this->assertSame(4000, $this->projection->obligationState($b->fresh())['recognized']);
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // Кап семьи: возвращённые деньги не закрывают обязательств.
    public function test_refunded_money_cannot_fund_an_obligation(): void
    {
        $r = $this->receipt(10000, 'r');
        $this->ledger->refund($r, 'f', 3000, now());
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 10000);

        $this->assertLedgerRejects('cannot allocate more than it holds', fn () => $this->ledger->allocate($r, $b, 7001, 'a'));
        $this->assertLedgerRejects('cannot allocate more than it holds', fn () => $this->raw('money_allocations', [
            'movement_id' => $r->id, 'obligation_id' => $b->id, 'amount_kopecks' => 7001,
        ]));
        $this->ledger->allocate($r, $b, 7000, 'a2');
        $this->assertSame(0, $this->projection->familyResidue($r->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // Корректировка вниз при возврате: переносится не больше свободного нетто.
    public function test_correction_carries_no_more_than_the_family_holds(): void
    {
        $r = $this->receipt(10000, 'r');
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 10000);
        $this->ledger->allocate($r, $b, 8000, 'a');
        $this->ledger->refund($r, 'f', 2000, now());

        $this->ledger->correct($r, 'c', 9000, 'в выписке 90 ₽');
        $this->assertSame(7000, $this->projection->obligationAllocated($b->id));
        $this->assertSame(0, $this->projection->familyResidue($r->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // N2: корректировка оплаты, возврат которой уменьшил обязательство, требует сначала сторно возврата (закреплено).
    public function test_n2_correction_after_an_obligation_reducing_refund_needs_the_refund_reversed_first(): void
    {
        $r = $this->receipt(8000, 'r');
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 8000);
        $this->ledger->allocate($r, $b, 8000, 'a');
        $f = $this->ledger->refund($r, 'f', 3000, now(), [$b->id => 3000]);

        $this->assertLedgerRejects('only by what it funded', fn () => $this->ledger->correct($r, 'c', 9000, 'x'));
        $this->ledger->reverse($f, 'f-rev', 'возврат проводится заново после корректировки');
        $this->ledger->correct($r, 'c2', 9000, 'x');
        $this->assertSame(8000, $this->projection->obligationAllocated($b->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // N3: сторно/корректировка держат преподавателя и курс цепочки; компенсация называет студента.
    public function test_n3_chain_rows_keep_teacher_and_course_and_compensation_names_a_student(): void
    {
        $teacher = Teacher::factory()->create();
        $other = Teacher::factory()->create();
        $payout = $this->ledger->payout('p', 50000, $teacher->id, now());

        $this->assertLedgerRejects('keep the teacher and course of their chain', fn () => $this->raw('money_movements', [
            'type' => 'reversal', 'amount_kopecks' => 50000, 'user_id' => null, 'course_id' => null, 'teacher_id' => $other->id,
            'reverses_movement_id' => $payout->id, 'root_movement_id' => $payout->id,
        ]));
        $this->assertLedgerRejects('student money names its student', fn () => $this->raw('money_movements', [
            'type' => 'compensation', 'amount_kopecks' => -100, 'user_id' => null, 'reason' => 'x', 'approved_by' => $this->student->id,
        ]));
    }

    // N4: занятие не признаётся проведённым в будущем.
    public function test_n4_delivery_cannot_be_dated_in_the_future(): void
    {
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 4000);

        $this->assertLedgerRejects('only after it happened', fn () => $this->ledger->markDelivered($b, now()->addMinute()));
        $this->assertNull($b->fresh()->delivered_at);
    }

    // N5: коммит внутри теневого прогона отбивается, уровень транзакций восстановлен.
    public function test_n5_shadow_refuses_a_commit_inside_and_restores_the_transaction_level(): void
    {
        config(['features.money_ledger_core' => false]);
        $level = DB::transactionLevel();

        try {
            $this->ledger->shadow(function (LedgerService $l) {
                $l->receipt('sh', 100, $this->student->id, $this->course->id, now());
                DB::commit();
            });
            $this->fail('коммит внутри shadow() должен отбиваться');
        } catch (LedgerInvariantViolation $e) {
            $this->assertStringContainsString('commit inside shadow() is refused', $e->getMessage());
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertFalse($this->ledger->writable());
    }

    // --- третье ревью (24-09-2026): F1–F7 ------------------------------------

    // F1: корректировка возврата вверх не обходит правило свободного остатка.
    public function test_f1_refund_correction_cannot_take_money_that_funds_a_delivered_block(): void
    {
        $r = $this->receipt(10000, 'r');
        $f = $this->ledger->refund($r, 'f', 4000, now());
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 6000);
        $this->ledger->allocate($r, $b, 6000, 'a');
        $this->ledger->markDelivered($b, now()->subHour());

        $this->assertLedgerRejects('exceeds the unallocated residue', fn () => $this->ledger->refund($r, 'f2', 1000, now()));
        $this->assertLedgerRejects('holding less than it has allocated', fn () => $this->ledger->correct($f, 'fc', 5000, 'x'));
        $this->assertNull(MoneyMovement::query()->where('movement_key', 'fc')->first(), 'отказ атомарен');
        $this->assertSame(6000, $this->projection->obligationState($b->fresh())['recognized']);
        $this->assertSame([], $this->projection->integrityBreaches());

        // Вниз — можно: возвращено меньше, семья держит больше.
        $this->ledger->correct($f, 'fc-down', 3000, 'в выписке 30 ₽');
        $this->assertSame(1000, $this->projection->familyResidue($r->id));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // F2: корректировка возврата, уменьшившего проведённый блок, переносит уменьшение — выручка не растёт за счёт возвращённых денег.
    public function test_f2_correcting_an_obligation_reducing_refund_carries_the_reduction(): void
    {
        $r = $this->receipt(10000, 'r');
        $x = $this->ledger->openBlock('x', $this->student->id, $this->course->id, 1, 10000);
        $this->ledger->allocate($r, $x, 10000, 'a');
        $f = $this->ledger->refund($r, 'f', 4000, now(), [$x->id => 4000]);
        $this->ledger->markDelivered($x, now()->subHour());
        $this->assertSame(6000, $this->projection->obligationState($x->fresh())['recognized']);

        $this->ledger->correct($f, 'fc', 4000, 'исправлена дата');
        $this->assertSame(6000, $this->projection->obligationState($x->fresh())['recognized']);
        $this->assertSame(0, $this->projection->familyResidue($r->id));
        $this->assertSame([], $this->projection->integrityBreaches());

        // Вниз: вернули 1000, а не 4000 — признанная выручка растёт до 9000 честно.
        $fc = MoneyMovement::query()->where('movement_key', 'fc')->firstOrFail();
        $this->ledger->correct($fc, 'fc2', 1000, 'в выписке 10 ₽');
        $this->assertSame(9000, $this->projection->obligationState($x->fresh())['recognized']);
        $this->assertSame([], $this->projection->integrityBreaches());

        // Вверх сверх того, что семья держит, — отказ.
        $fc2 = MoneyMovement::query()->where('movement_key', 'fc2')->firstOrFail();
        $this->assertLedgerRejects('holding less than it has allocated', fn () => $this->ledger->correct($fc2, 'fc3', 5000, 'x'));
        $this->assertSame(9000, $this->projection->obligationState($x->fresh())['recognized']);
    }

    // F2 (сырой обход): корректировка не уменьшает проведённое обязательство сверх того, что вернуло сторно.
    public function test_f2_raw_correction_cannot_reduce_a_delivered_obligation_beyond_its_reversal(): void
    {
        $r = $this->receipt(10000, 'r');
        $x = $this->ledger->openBlock('x', $this->student->id, $this->course->id, 1, 10000);
        $this->ledger->allocate($r, $x, 10000, 'a');
        $f = $this->ledger->refund($r, 'f', 1000, now(), [$x->id => 1000]);
        $this->ledger->markDelivered($x, now()->subHour());
        $this->ledger->correct($f, 'fc', 1000, 'исправлена дата');
        $fc = MoneyMovement::query()->where('movement_key', 'fc')->firstOrFail();

        $this->assertLedgerRejects('delivered obligation is reduced only by a reversal', fn () => $this->raw('money_allocations', [
            'movement_id' => $fc->id, 'obligation_id' => $x->id, 'amount_kopecks' => -1,
        ]));
    }

    // F3: настоящий COMMIT внутри shadow() отбивается до записи; «commit + begin» тоже ловится.
    public function test_f3_shadow_blocks_a_real_commit_and_detects_commit_then_begin(): void
    {
        config(['features.money_ledger_core' => false]);
        $pdo = DB::connection()->getPdo();
        $outer = DB::transactionLevel();

        // Уровень теста 1 → прогон 2; второй commit — это настоящий COMMIT уровня 1.
        try {
            $this->ledger->shadow(function (LedgerService $l) {
                $l->receipt('sh-1', 100, $this->student->id, $this->course->id, now());
                DB::commit();
                DB::commit();
            });
            $this->fail('настоящий COMMIT внутри shadow() должен отбиваться');
        } catch (LedgerInvariantViolation $e) {
            $this->assertStringContainsString('a commit inside shadow() is refused', $e->getMessage());
        }
        $this->assertTrue($pdo->inTransaction(), 'COMMIT до базы не дошёл');
        $this->assertSame($outer, DB::transactionLevel());

        $this->assertLedgerRejects('commit inside shadow() is refused', fn () => $this->ledger->shadow(function (LedgerService $l) {
            $l->receipt('sh-2', 100, $this->student->id, $this->course->id, now());
            DB::commit();
            DB::beginTransaction();
        }));
        $this->assertTrue($pdo->inTransaction());
        $this->assertFalse($this->ledger->writable());

        // Обычный прогон после отказа снова работает и откатывается.
        $this->assertSame(1, $this->ledger->shadow(fn (LedgerService $l) => $l->receipt('sh-3', 100, $this->student->id, $this->course->id, now()) ? 1 : 0));
        $this->assertNull(MoneyMovement::query()->where('movement_key', 'sh-3')->first());
    }

    // F4: дата проведения в будущем отбивается и триггером.
    public function test_f4_trigger_refuses_a_delivery_date_in_the_future(): void
    {
        $b = $this->ledger->openBlock('b', $this->student->id, $this->course->id, 1, 4000);

        $this->assertLedgerRejects('delivered only after it happened', fn () => DB::table('money_obligations')->where('id', $b->id)->update(['delivered_at' => '2030-01-01 00:00:00']));
        $this->assertNull($b->fresh()->delivered_at);
    }

    // F6: ошибочный прямой платёж сторнируется и записывается заново — ровно один раз на оригинал.
    public function test_f6_reversed_direct_receipt_is_re_recorded_once_without_reusing_evidence(): void
    {
        $teacher = Teacher::factory()->create();
        [$dr] = $this->ledger->directTeacherReceipt('dt', 500000, $this->student->id, $this->course->id, $teacher->id, 'RUB', 500000, 'ev-1', now());

        $this->assertLedgerRejects('only a reversed direct teacher receipt', fn () => $this->ledger->rerecordDirectTeacherReceipt($dr, 'dt2', 450000, 'RUB', 450000, now()));
        $this->ledger->reverse($dr, 'dt-rev', 'сумма в выписке другая');
        $this->assertLedgerRejects('evidence', fn () => $this->ledger->directTeacherReceipt('dt-x', 450000, $this->student->id, $this->course->id, $teacher->id, 'RUB', 450000, 'ev-1', now()));

        [$again, $offset] = $this->ledger->rerecordDirectTeacherReceipt($dr, 'dt2', 450000, 'RUB', 450000, now());
        $this->assertSame(LedgerService::rerecordEvidenceKey($dr), $again->evidence_key);
        $this->assertSame(-450000, $offset->amount_kopecks);
        $this->assertLedgerRejects('evidence', fn () => $this->ledger->rerecordDirectTeacherReceipt($dr, 'dt3', 450000, 'RUB', 450000, now()));
        $this->assertSame([], $this->projection->integrityBreaches());
    }

    // F7: типы и ключи сравниваются точно (на MariaDB — колонки utf8mb4_bin).
    public function test_f7_types_and_keys_compare_exactly(): void
    {
        $this->assertLedgerRejects('unknown movement type', fn () => $this->raw('money_movements', ['type' => 'RECEIPT']));
        $this->assertLedgerRejects('unknown movement type', fn () => $this->raw('money_movements', ['type' => 'receipt ']));

        $teacher = Teacher::factory()->create();
        $this->ledger->directTeacherReceipt('d1', 100, $this->student->id, $this->course->id, $teacher->id, 'RUB', 100, 'ev-case', now());
        $this->ledger->directTeacherReceipt('d2', 100, $this->student->id, $this->course->id, $teacher->id, 'RUB', 100, 'EV-CASE', now());
        $this->assertSame(2, MoneyMovement::query()->where('type', MoneyMovement::DIRECT_TEACHER_RECEIPT)->count());
    }

    // --- четвёртое ревью (24-09-2026) -----------------------------------------

    // R4 MAJOR: «корректировка» уже сторнированного возврата — это новый возврат против проведённого блока; отказ на обоих уровнях.
    public function test_r4_an_already_reversed_refund_is_not_corrected(): void
    {
        $r = $this->receipt(14000, 'r');
        $x = $this->ledger->openBlock('x', $this->student->id, $this->course->id, 1, 10000);
        $this->ledger->allocate($r, $x, 10000, 'a');
        $f = $this->ledger->refund($r, 'f', 4000, now(), [$x->id => 4000]);
        $this->ledger->markDelivered($x, now()->subHour());
        $this->ledger->reverse($f, 'f-rev', 'возврат не состоялся');
        $this->assertSame(10000, $this->projection->obligationState($x->fresh())['recognized']);

        $this->assertLedgerRejects('delivered obligation is reduced only by a reversal', fn () => $this->ledger->refund($r, 'f2', 4000, now(), [$x->id => 4000]));
        $this->assertLedgerRejects('a reversed row is not corrected', fn () => $this->ledger->correct($f, 'fc', 4000, 'x'));
        $this->assertLedgerRejects('a reversed row is not corrected', fn () => $this->raw('money_movements', [
            'movement_key' => 'fc-raw', 'type' => 'correction', 'amount_kopecks' => -4000,
            'corrects_movement_id' => $f->id, 'root_movement_id' => $f->id, 'cap_anchor_id' => $r->id, 'reason' => 'x',
        ]));
        $this->assertSame(10000, $this->projection->obligationState($x->fresh())['recognized']);
        $this->assertSame([], $this->projection->integrityBreaches());

        // Повтор своей же корректировки по тому же ключу остаётся идемпотентным.
        $g = $this->ledger->refund($r, 'g', 1000, now());
        [$gc] = $this->ledger->correct($g, 'gc', 500, 'в выписке 5 ₽');
        [$again] = $this->ledger->correct($g, 'gc', 500, 'в выписке 5 ₽');
        $this->assertSame($gc->id, $again->id);
    }

    // R4 minor: враждебный колбэк откатил уровни сам — открытая транзакция PDO без уровня откатывается.
    public function test_r4_shadow_rolls_back_an_orphaned_pdo_transaction(): void
    {
        config(['features.money_ledger_core' => false]);
        $pdo = DB::connection()->getPdo();

        try {
            $this->ledger->shadow(function (LedgerService $l) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                // Данные теста откатил сам колбэк; ничего не создаём в автокоммите (утечка в
                // следующие тесты) — берём прежние id при выключенной проверке внешних ключей.
                $this->foreignKeys(false);
                $l->receipt('orphan', 100, $this->student->id, $this->course->id, now());
            });
            $this->fail('COMMIT внутри shadow() должен отбиваться');
        } catch (LedgerInvariantViolation $e) {
            $this->assertStringContainsString('commit inside shadow() is refused', $e->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction(), 'осиротевшая транзакция PDO откачена');
        $this->assertNull(MoneyMovement::query()->where('movement_key', 'orphan')->first());
        $this->assertFalse($this->ledger->writable());
        $this->foreignKeys(true);
        DB::beginTransaction(); // вернуть уровень для отката RefreshDatabase
    }

    private function foreignKeys(bool $on): void
    {
        DB::statement(DB::connection()->getDriverName() === 'sqlite'
            ? 'PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF')
            : 'SET FOREIGN_KEY_CHECKS = '.($on ? '1' : '0'));
    }

    // R4: ключ «<k>:reversal» принадлежит только сторно корректировки <k>; подделка видна в сверке.
    public function test_r4_correction_reversal_key_suffix_is_reserved_and_forgery_is_reported(): void
    {
        $r = $this->receipt(14000, 'r');
        $x = $this->ledger->openBlock('x', $this->student->id, $this->course->id, 1, 10000);
        $this->ledger->allocate($r, $x, 10000, 'a');
        $f = $this->ledger->refund($r, 'f', 4000, now(), [$x->id => 4000]);
        $this->ledger->markDelivered($x, now()->subHour());

        $this->assertLedgerRejects('reserved for the reversal half of a correction', fn () => $this->ledger->reverse($f, 'fc:reversal', 'x'));

        // Сырая подделка: сторно с ключом корректировки без самой корректировки — нарушение в сверке.
        $this->raw('money_movements', [
            'movement_key' => 'forged:reversal', 'type' => 'reversal', 'amount_kopecks' => 4000,
            'reverses_movement_id' => $f->id, 'root_movement_id' => $f->id, 'cap_anchor_id' => $r->id, 'reason' => 'x',
        ]);
        $breaches = $this->projection->integrityBreaches();
        $this->assertArrayHasKey('correction_reversal_without_correction', $breaches);
    }
}
