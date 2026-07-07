<?php

declare(strict_types=1);

namespace Tests\Feature\Deposit;

use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Jobs\SendTelegramMessageJob;
use App\Mail\DepositReceivedMail;
use App\Mail\DepositTransferredMail;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Перенос брони (депозита) с курса на курс через action PaymentResource.
 * Ядро: смена course_id переносит зачёт депозита в новый курс, ничего денежного
 * не трогая и не повторяя писем брони.
 */
class DepositTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function paidDeposit(User $user, Course $course, int $amount = 1000): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function transfer_moves_credit_to_new_course_without_touching_money(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $student = User::factory()->create();
        $from = Course::factory()->create(['title' => 'Курс A']);
        $to = Course::factory()->create(['title' => 'Курс B', 'chat_url' => 'https://t.me/b']);
        $deposit = $this->paidDeposit($student, $from);

        $tariffA = Tariff::factory()->for($from)->create(['price' => 12000]);
        $tariffB = Tariff::factory()->for($to)->create(['price' => 12000]);

        // До переноса: депозит кредитуется в курс A.
        $this->assertSame(11000.0, $tariffA->calculateFinalPriceForUser($student));
        $this->assertSame(12000.0, $tariffB->calculateFinalPriceForUser($student));

        Mail::fake(); // сбрасываем письма брони, интересует только эффект переноса

        Livewire::test(ListPayments::class)
            ->callTableAction('transferDeposit', $deposit, ['new_course_id' => $to->id, 'notify_student' => true]);

        $deposit->refresh();
        $this->assertSame($to->id, $deposit->course_id, 'Курс брони должен смениться на B.');
        $this->assertNull($deposit->deposit_consumed_at, 'Депозит остаётся незачтённым.');
        $this->assertSame(1000.0, (float) $deposit->amount, 'Сумма брони не меняется.');
        $this->assertSame('paid', $deposit->status, 'Статус не меняется.');

        // Ядро: кредит переехал на курс B, курс A больше не кредитуется.
        $this->assertSame(12000.0, $tariffA->calculateFinalPriceForUser($student));
        $this->assertSame(11000.0, $tariffB->calculateFinalPriceForUser($student));

        // Побочки processDeposit НЕ повторились (status не менялся).
        Mail::assertNotQueued(DepositReceivedMail::class);
    }

    /** @test */
    public function transfer_writes_audit_and_notifies_student(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $student = User::factory()->create();
        $from = Course::factory()->create(['title' => 'Курс A']);
        $to = Course::factory()->create(['title' => 'Курс B']);
        $deposit = $this->paidDeposit($student, $from);

        Livewire::test(ListPayments::class)
            ->callTableAction('transferDeposit', $deposit, ['new_course_id' => $to->id, 'notify_student' => true]);

        // Аудит фиксирует смену course_id (A → B).
        $audit = PaymentAudit::query()
            ->where('payment_id', $deposit->id)
            ->where('action', PaymentAudit::ACTION_UPDATED)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, 'Смена курса должна попасть в аудит.');
        $this->assertArrayHasKey('course_id', $audit->changes);
        $this->assertSame([$from->id, $to->id], $audit->changes['course_id']);

        // Студенту ушло письмо и Telegram о переносе.
        Mail::assertQueued(
            DepositTransferredMail::class,
            fn ($mail) => $mail->hasTo($student->email)
                && $mail->toCourse->id === $to->id
                && $mail->fromCourse->id === $from->id,
        );
        Queue::assertPushed(SendTelegramMessageJob::class);
    }

    /** @test */
    public function opting_out_skips_student_notifications(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $student = User::factory()->create();
        $from = Course::factory()->create();
        $to = Course::factory()->create();
        $deposit = $this->paidDeposit($student, $from);

        Mail::fake();
        Queue::fake();

        Livewire::test(ListPayments::class)
            ->callTableAction('transferDeposit', $deposit, ['new_course_id' => $to->id, 'notify_student' => false]);

        Mail::assertNotQueued(DepositTransferredMail::class);
        Queue::assertNotPushed(SendTelegramMessageJob::class);
        $this->assertSame($to->id, $deposit->fresh()->course_id);
    }

    /** @test */
    public function action_hidden_for_consumed_deposit_and_non_deposit_and_non_admin(): void
    {
        $student = User::factory()->create();
        $course = Course::factory()->create();
        $other = Course::factory()->create();

        $consumed = $this->paidDeposit($student, $course);
        $consumed->update(['deposit_consumed_at' => now()]);
        $fresh = $this->paidDeposit($student, $course);
        $full = Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 12000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Админ: доступно только для свежей (незачтённой) брони.
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        Livewire::test(ListPayments::class)
            ->assertTableActionVisible('transferDeposit', $fresh)
            ->assertTableActionHidden('transferDeposit', $consumed)
            ->assertTableActionHidden('transferDeposit', $full);

        // Менеджер: действие недоступно (только админ).
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
        Livewire::test(ListPayments::class)
            ->assertTableActionHidden('transferDeposit', $fresh);
    }
}
