<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Payment;
use App\Models\TeacherPayout;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Проводит выплату преподавателю (TeacherPayout) в «Финансах» как
 * транзакцию-отток: Payment с tariff='salary_payout', минусовой суммой, на
 * реальном курсе выплаты (или техническом «Прочие затраты», если курс не задан).
 *
 * Идемпотентно: повторный post по уже проведённой выплате обновляет её
 * транзакцию, а не плодит новую. Единая точка для калькулятора, ручной записи
 * выплаты, ресурса «История выплат» и команды бэкафилла.
 */
class TeacherPayoutPoster
{
    public const TARIFF = 'salary_payout';

    /**
     * Создать/обновить транзакцию-зеркало для выплаты. Возвращает Payment или
     * null, если у выплаты нет суммы.
     */
    public function post(TeacherPayout $payout): ?Payment
    {
        if ((float) $payout->amount == 0.0) {
            return null;
        }

        $attributes = [
            'user_id' => $this->systemUserId(),
            'course_id' => $payout->course_id ?: $this->technicalCourseId(),
            'amount' => -abs((float) $payout->amount),
            'tariff' => self::TARIFF,
            'status' => 'paid',
            'is_conditional' => false,
            'start_block' => $this->blockNumber($payout),
            'end_block' => $this->blockNumber($payout),
            'transaction_id' => $this->transactionLabel($payout),
            'created_at' => $payout->paid_at ?? now(),
        ];

        // Уже проведена — обновляем существующую транзакцию.
        if ($payout->payment_id && ($existing = Payment::find($payout->payment_id))) {
            $existing->update($attributes);

            return $existing;
        }

        $payment = Payment::create($attributes);
        $payout->updateQuietly(['payment_id' => $payment->id]);

        return $payment;
    }

    /**
     * Снять проведение: удалить транзакцию-зеркало и отвязать выплату.
     */
    public function unpost(TeacherPayout $payout): void
    {
        if (! $payout->payment_id) {
            return;
        }

        Payment::withoutEvents(fn () => Payment::whereKey($payout->payment_id)->delete());
        $payout->updateQuietly(['payment_id' => null]);
    }

    private function blockNumber(TeacherPayout $payout): ?int
    {
        $block = $payout->breakdown['block_number'] ?? null;

        return $block !== null ? (int) $block : null;
    }

    private function transactionLabel(TeacherPayout $payout): string
    {
        $teacher = $payout->teacher?->name ?? ('препод #'.$payout->teacher_id);
        $period = $payout->period_month ? ' · '.$payout->period_month : '';

        return Str::limit('ЗП: '.$teacher.$period, 250, '');
    }

    /**
     * Системный пользователь для бухгалтерских транзакций (как в импорте
     * расходов) — чтобы выплаты не светились в карточках студентов.
     */
    private function systemUserId(): int
    {
        return User::firstOrCreate(
            ['email' => 'expenses@samskrte.ru'],
            ['name' => 'Системные расходы', 'password' => Hash::make(Str::random(16))],
        )->id;
    }

    /**
     * Технический курс «Прочие затраты» — для выплат без привязки к курсу.
     * Исключён из начисления ЗП (TeacherSalaryService::isTechnicalCourse).
     */
    private function technicalCourseId(): int
    {
        return Course::firstOrCreate(
            ['slug' => 'system-expenses'],
            ['title' => 'Прочие затраты (Технический)', 'is_visible' => false],
        )->id;
    }
}
