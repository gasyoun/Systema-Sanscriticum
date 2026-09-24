<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Http\Controllers\StudentController;
use App\Models\Course;
use App\Models\Group;
use App\Models\MoneyObligation;
use App\Models\Payment;
use App\Models\User;
use App\Services\BlockAccessMaterializer;
use App\Services\Ledger\LedgerInvariantViolation;
use App\Services\Ledger\LedgerProjection;
use App\Services\Ledger\LedgerService;
use App\Services\Reconciliation\RefundAccessPolicy;
use App\Services\Reconciliation\RefundAccessViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H5445 (P3, D10): полный возврат отзывает оставшийся доступ; частичный —
 * только с явными блоками. Легаси-контур за флагом money_refund_access_rules,
 * ядро P1 — через RefundAccessPolicy::refundLedger.
 */
class RefundAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private User $student;

    private Payment $original;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 12:00:00');
        config(['features.money_refund_access_rules' => true]);

        $this->course = Course::factory()->create();
        $this->group = Group::factory()->create();
        $this->course->groups()->attach($this->group);
        $this->student = User::factory()->create();
        $this->student->groups()->attach($this->group);

        // Оплата блоков 1–3 одной строкой: ключ block_1 + siblings block_2, block_3.
        $this->original = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => '12000.00',
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 3,
            'status' => 'paid',
            'is_conditional' => false,
            'first_paid_at' => now(),
        ]));
        $this->assertSame(2, app(BlockAccessMaterializer::class)->materialize($this->original));
    }

    private function refund(string $amount, ?int $from = null, ?int $to = null): Payment
    {
        return Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => $amount,
            'tariff' => 'Расход',
            'status' => 'paid',
            'is_conditional' => false,
            'refund_of_payment_id' => $this->original->id,
            'start_block' => $from,
            'end_block' => $to,
            'first_paid_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function unlocked(): array
    {
        $keys = StudentController::getUserUnlockedTariffs($this->student->id, $this->course->slug);
        sort($keys);

        // Расход-строка тоже paid и попадает в ключи (легаси) — уроков с таким ключом нет.
        return array_values(array_unique(array_filter($keys, fn ($k) => str_starts_with((string) $k, 'block_'))));
    }

    private function inGroup(): bool
    {
        return $this->student->groups()->whereKey($this->group->id)->exists();
    }

    public function test_partial_refund_without_blocks_is_refused(): void
    {
        try {
            $this->refund('-4000.00');
            $this->fail('partial refund without blocks must be refused');
        } catch (RefundAccessViolation $e) {
            $this->assertStringContainsString('D10', $e->getMessage());
        }
        $this->assertSame(0, Payment::query()->whereNotNull('refund_of_payment_id')->count());
        $this->assertSame(['block_1', 'block_2', 'block_3'], $this->unlocked());
    }

    public function test_partial_refund_outside_paid_range_or_reversed_is_refused(): void
    {
        foreach ([[4, 4], [3, 2], [0, 1]] as [$from, $to]) {
            try {
                $this->refund('-4000.00', $from, $to);
                $this->fail("blocks {$from}-{$to} must be refused");
            } catch (RefundAccessViolation) {
            }
        }
        $this->assertSame(0, Payment::query()->whereNotNull('refund_of_payment_id')->count());
    }

    public function test_partial_refund_revokes_only_the_named_blocks(): void
    {
        $this->refund('-4000.00', 3, 3);

        $this->assertSame(['block_1', 'block_2'], $this->unlocked());
        $this->assertTrue($this->inGroup(), 'remaining blocks keep group access');
    }

    public function test_partial_refund_of_the_payments_own_block_revokes_that_key(): void
    {
        $this->refund('-4000.00', 1, 1);

        $this->assertSame(['block_2', 'block_3'], $this->unlocked());
        $this->assertTrue($this->inGroup());
    }

    public function test_partial_refunds_adding_up_to_full_revoke_everything(): void
    {
        $this->refund('-4000.00', 3, 3);
        // Второй возврат доводит сумму до полной — блоки уже не обязательны.
        $this->refund('-8000.00');

        $this->assertSame([], $this->unlocked());
        $this->assertFalse($this->inGroup(), 'full refund detaches course groups');
    }

    public function test_full_refund_revokes_remaining_access(): void
    {
        $this->refund('-12000.00');

        $this->assertSame([], $this->unlocked());
        $this->assertSame(0, Payment::query()->where('transaction_id', BlockAccessMaterializer::GRANT_PREFIX.$this->original->id)->count());
        $this->assertFalse($this->inGroup());
    }

    public function test_full_refund_keeps_groups_when_another_paid_course_payment_grants_them(): void
    {
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => '4000.00',
            'tariff' => 'block_4',
            'status' => 'paid',
            'is_conditional' => false,
            'first_paid_at' => now(),
        ]));

        $this->refund('-12000.00');

        $this->assertSame(['block_4'], $this->unlocked());
        $this->assertTrue($this->inGroup());
    }

    public function test_unpaid_refund_draft_changes_nothing(): void
    {
        Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => '-4000.00',
            'tariff' => 'Расход',
            'status' => 'pending',
            'is_conditional' => false,
            'refund_of_payment_id' => $this->original->id,
        ]);

        $this->assertSame(['block_1', 'block_2', 'block_3'], $this->unlocked());
        $this->assertTrue($this->inGroup());
    }

    public function test_flag_off_keeps_legacy_behaviour(): void
    {
        config(['features.money_refund_access_rules' => false]);

        $this->refund('-4000.00');
        $this->refund('-8000.00');

        $this->assertSame(['block_1', 'block_2', 'block_3'], $this->unlocked(), 'flag OFF: refunds do not touch access');
        $this->assertTrue($this->inGroup());
    }

    public function test_ledger_full_refund_names_holdings_and_cancels_undelivered_blocks(): void
    {
        config(['features.money_ledger_core' => true]);
        $ledger = app(LedgerService::class);
        $receipt = $ledger->receipt('r1', 1_200_000, $this->student->id, $this->course->id, now());
        $b1 = $ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 400_000);
        $b2 = $ledger->openBlock('b2', $this->student->id, $this->course->id, 2, 400_000);
        $ledger->allocate($receipt, $b1, 400_000, 'a1');
        $ledger->allocate($receipt, $b2, 400_000, 'a2');
        $ledger->markDelivered($b1, now()->subDay());

        $policy = app(RefundAccessPolicy::class);

        try {
            // Деньги проведённого блока 1 возвратом не уходят — только сторно проведения.
            $policy->refundLedger($receipt, 'refund-all', 1_200_000, now());
            $this->fail('money of a delivered block is not refundable');
        } catch (LedgerInvariantViolation) {
        }

        // Полный возврат = 1 200 000 − 400 000 проведённых.
        $policy->refundLedger($receipt, 'refund-full', 800_000, now());

        $projection = app(LedgerProjection::class);
        $this->assertSame(400_000, $projection->obligationAllocated($b1->id), 'delivered block keeps its money');
        $this->assertSame(0, $projection->obligationAllocated($b2->id));
        $this->assertSame(0, $projection->refundableRemaining($receipt->fresh()) - 400_000);
        $this->assertNull($b1->fresh()->cancelled_at, 'a delivered block is history, never cancelled');
        $this->assertNotNull($b2->fresh()->cancelled_at, 'undelivered block without money is revoked');
        $this->assertSame(0, $projection->familyResidue($receipt->id));
    }

    public function test_ledger_partial_refund_must_name_obligations_once_money_sits_on_blocks(): void
    {
        config(['features.money_ledger_core' => true]);
        $ledger = app(LedgerService::class);
        $receipt = $ledger->receipt('r1', 1_200_000, $this->student->id, $this->course->id, now());
        $b1 = $ledger->openBlock('b1', $this->student->id, $this->course->id, 1, 400_000);
        $ledger->allocate($receipt, $b1, 400_000, 'a1');
        $policy = app(RefundAccessPolicy::class);

        try {
            // P1 пропустил бы: 100 000 помещаются в свободный остаток 800 000.
            $policy->refundLedger($receipt, 'refund-p', 100_000, now());
            $this->fail('D10: a partial refund must name what it reduces');
        } catch (LedgerInvariantViolation $e) {
            $this->assertStringContainsString('D10', $e->getMessage());
        }

        $policy->refundLedger($receipt, 'refund-p', 400_000, now(), [$b1->id => 400_000]);
        $this->assertSame(0, app(LedgerProjection::class)->obligationAllocated($b1->id));
        $this->assertNull(MoneyObligation::query()->findOrFail($b1->id)->cancelled_at, 'partial refund cancels nothing implicitly');
    }

    public function test_ledger_partial_refund_without_allocations_passes_through(): void
    {
        config(['features.money_ledger_core' => true]);
        $ledger = app(LedgerService::class);
        $receipt = $ledger->receipt('r1', 1_200_000, $this->student->id, $this->course->id, now());

        $refund = app(RefundAccessPolicy::class)->refundLedger($receipt, 'refund-p', 100_000, now());

        $this->assertSame(-100_000, $refund->amount_kopecks);
    }
}
