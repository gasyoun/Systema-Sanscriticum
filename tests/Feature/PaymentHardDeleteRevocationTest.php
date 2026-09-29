<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5084 (находка H5046 payment-hard-delete-skips-access-revocation) —
 * модельная половина фикса: хард-делит оплаченного платежа роутится через
 * канонический переход в 'canceled' (revocation chain), поэтому доступ,
 * выданный платежом, реально отзывается до исчезновения строки.
 *
 * Policy-половина (delete только у админа) — в
 * tests/Feature/Sandbox/H5084PaymentDeleteAdminOnlyTest.php.
 */
class PaymentHardDeleteRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    /**
     * Курс с одной группой + оплаченный платёж, выдавший доступ.
     *
     * @return array{0: Payment, 1: User, 2: Group}
     */
    private function paidPaymentWithAccess(string $tariff = 'full'): array
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'H5084 revocation группа']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => $tariff,
            'status' => 'paid',
        ]);

        // Предусловие: платёж реально открыл доступ — то, что удаление
        // строки не должно оставить висеть.
        $this->assertTrue(
            $student->fresh()->groups->contains($group->id),
            'Предусловие: оплаченный платёж выдал группу курса.'
        );

        return [$payment, $student, $group];
    }

    /** @test */
    public function hard_delete_of_paid_payment_revokes_group_access(): void
    {
        [$payment, $student, $group] = $this->paidPaymentWithAccess();

        $payment->delete();

        // Строка денег удалена, а выданный ею доступ отозван через canceled.
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertFalse(
            $student->fresh()->groups->contains($group->id),
            'Хард-делит оплаченного платежа должен отозвать групповой доступ.'
        );
    }

    /** @test */
    public function hard_delete_keeps_access_while_another_paid_payment_remains(): void
    {
        [$payment, $student, $group] = $this->paidPaymentWithAccess();

        $second = $payment->replicate(['first_paid_at']);
        $second->status = 'paid';
        $second->save();

        $payment->delete();

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertTrue(
            $student->fresh()->groups->contains($group->id),
            'Пока есть другой access-granting платёж, доступ не отзывается (reconcile-семантика).'
        );
    }

    /** @test */
    public function hard_delete_of_canceled_payment_leaves_access_untouched(): void
    {
        [$payment, $student, $group] = $this->paidPaymentWithAccess();

        $canceled = Payment::create([
            'user_id' => $student->id,
            'course_id' => $payment->course_id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'canceled',
        ]);

        $canceled->delete();

        $this->assertDatabaseMissing('payments', ['id' => $canceled->id]);
        $this->assertTrue(
            $student->fresh()->groups->contains($group->id),
            'Удаление уже отменённого платежа — не событие отзыва: доступ живого paid-платежа не трогаем.'
        );
    }
}
