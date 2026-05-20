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
     * @param  list<array{promised_at: string|\DateTimeInterface, amount: numeric}>  $schedule
     * @return string uuid группы
     */
    public function create(User $user, Course $course, array $schedule, ?string $note = null): string
    {
        $normalized = $this->normalizeSchedule($schedule);

        $groupId = (string) Str::uuid();

        DB::transaction(function () use ($user, $course, $normalized, $note, $groupId): void {
            foreach ($normalized as $row) {
                PaymentPromise::create([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'promised_at' => $row['promised_at'],
                    'amount' => $row['amount'],
                    'status' => PaymentPromise::STATUS_ACTIVE,
                    'note' => $note,
                    'installment_group_id' => $groupId,
                ]);
            }
        });

        return $groupId;
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
     * @param  list<array{promised_at: mixed, amount: mixed}>  $schedule
     * @return list<array{promised_at: CarbonImmutable, amount: float}>
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
            ];
        }

        usort($rows, fn ($a, $b) => $a['promised_at']->getTimestamp() <=> $b['promised_at']->getTimestamp());

        return $rows;
    }
}
