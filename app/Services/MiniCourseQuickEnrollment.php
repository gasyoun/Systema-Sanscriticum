<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Быстрая запись на бесплатный мини-курс «Подготовительная группа» (ссылка
 * из VK-бота). Повторяет нулевую ветку PaymentController::createPayment:
 * платёж 0 ₽ создаётся pending и переводится в paid — дальше доступ, запись
 * на курс (якорь дрипа) и письма выдаёт штатный Payment::processSuccessfulPayment().
 * Группы и course_user здесь вручную не трогаются.
 */
final class MiniCourseQuickEnrollment
{
    /** Мини-курс, если он настроен и продаётся бесплатным тарифом. */
    public function course(): ?Course
    {
        $course = Course::where('slug', (string) config('mini_courses.slug'))->first();

        if ($course === null || $this->freeTariff($course) === null || ! $course->groups()->exists()) {
            return null;
        }

        return $course;
    }

    /**
     * Единственный тариф, который сюда допускается: активный и ровно 0 ₽.
     * Платный тариф этим путём не выдаётся никогда.
     */
    public function freeTariff(Course $course): ?Tariff
    {
        return $course->tariffs()
            ->where('is_active', true)
            ->where('price', 0)
            ->orderBy('id')
            ->first();
    }

    public function isEnrolled(User $user, Course $course): bool
    {
        return Payment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->exists();
    }

    /** Идемпотентно: уже записанному возвращает его прежний платёж. */
    public function enroll(User $user, Course $course): Payment
    {
        $tariff = $this->freeTariff($course);

        if ($tariff === null || (float) $tariff->price !== 0.0) {
            throw new \DomainException('Мини-курс сейчас недоступен для бесплатной записи.');
        }

        $payment = DB::transaction(function () use ($user, $course, $tariff): Payment {
            // Сериализуем двойной клик одного пользователя.
            User::whereKey($user->id)->lockForUpdate()->first();

            $existing = Payment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Тот же флаг H2186, что и в чекауте.
            $leadId = null;
            if (config('features.lead_converted_at_on_course_paid') && filled($user->email)) {
                $leadId = Lead::query()
                    ->where('email', $user->email)
                    ->whereNull('converted_at')
                    ->latest()
                    ->value('id');
            }

            return Payment::create([
                'user_id' => $user->id,
                'lead_id' => $leadId,
                'course_id' => $course->id,
                'amount' => 0,
                'tariff' => $tariff->accessKey(),
                'status' => 'pending',
            ]);
        });

        // Как в чекауте: paid-переход ПОСЛЕ commit — наблюдатель Payment выдаёт доступ.
        if ($payment->status === 'pending') {
            $payment->update(['status' => 'paid']);
        }

        return $payment;
    }
}
