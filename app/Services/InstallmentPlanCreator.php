<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\PaymentPromise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstallmentPlanCreator
{
    /**
     * Создание плана рассрочки: N обещаний с общим installment_group_id.
     *
     * Каждая строка графика — отдельный PaymentPromise со статусом active.
     * Группа позволяет потом одной кнопкой «Отменить всю рассрочку» закрыть
     * все обещания плана разом.
     *
     * Возвращает uuid группы и список созданных promises в порядке нарастания
     * даты — это нужно вызывающему, чтобы привязать к каждой строке расписания
     * дополнительные действия (например, открытие доступа к блокам).
     *
     * @param  list<array{promised_at: string|\DateTimeInterface, amount: numeric, actual_paid_at?: string|\DateTimeInterface|null}>  $schedule
     * @return array{group_id: string, promises: list<PaymentPromise>}
     */
    public function create(User $user, Course $course, array $schedule, ?string $note = null): array
    {
        $normalized = $this->normalizeSchedule($schedule);

        $groupId = (string) Str::uuid();

        $created = DB::transaction(function () use ($user, $course, $normalized, $note, $groupId): array {
            $out = [];
            foreach ($normalized as $row) {
                $out[] = PaymentPromise::create([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'promised_at' => $row['promised_at'],
                    'actual_paid_at' => $row['actual_paid_at'] ?? null,
                    'amount' => $row['amount'],
                    'status' => PaymentPromise::STATUS_ACTIVE,
                    'note' => $note,
                    'installment_group_id' => $groupId,
                ]);
            }

            return $out;
        });

        return ['group_id' => $groupId, 'promises' => $created];
    }

    /**
     * Отмена всех активных обещаний в группе. Уже выполненные не трогаем —
     * деньги могли пройти, и закрывать платёж задним числом не наше дело.
     *
     * @return int сколько обещаний отменено
     */
    public function cancelGroup(string $groupId): int
    {
        return PaymentPromise::query()
            ->inGroup($groupId)
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->update([
                'status' => PaymentPromise::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
    }

    /**
     * @param  list<array{promised_at: mixed, amount: mixed, actual_paid_at?: mixed}>  $schedule
     * @return list<array{promised_at: CarbonImmutable, amount: float, actual_paid_at: ?CarbonImmutable}>
     */
    private function normalizeSchedule(array $schedule): array
    {
        if (count($schedule) < 2) {
            throw ValidationException::withMessages([
                'schedule' => 'Рассрочка должна содержать минимум 2 платежа.',
            ]);
        }

        $rows = [];
        foreach ($schedule as $i => $row) {
            $rawDate = $row['promised_at'] ?? null;
            $rawAmount = $row['amount'] ?? null;
            $rawActual = $row['actual_paid_at'] ?? null;

            if (empty($rawDate)) {
                throw ValidationException::withMessages([
                    "schedule.$i.promised_at" => 'Дата платежа обязательна.',
                ]);
            }
            if ($rawAmount === null || $rawAmount === '' || ! is_numeric($rawAmount) || (float) $rawAmount <= 0) {
                throw ValidationException::withMessages([
                    "schedule.$i.amount" => 'Сумма платежа должна быть больше нуля.',
                ]);
            }

            $rows[] = [
                'promised_at' => CarbonImmutable::parse($rawDate)->startOfDay(),
                'amount' => (float) $rawAmount,
                'actual_paid_at' => ! empty($rawActual)
                    ? CarbonImmutable::parse($rawActual)->startOfDay()
                    : null,
            ];
        }

        usort($rows, fn ($a, $b) => $a['promised_at']->getTimestamp() <=> $b['promised_at']->getTimestamp());

        return $rows;
    }
}
