<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Сверка «оплачен, но доступа нет»: paid-платежи (включая promise-grant,
 * is_conditional), у студента которых нет строки в course_user.
 *
 * Инцидент 30-10-2026 (платежи 14577/14066): ConditionalAccessGranter создаёт
 * conditional-платежи через withoutEvents — fireOnPaid не стреляет; грантер
 * вызывал только grantAccess(), а enrollInCourse() — никогда. Часть
 * пострадавших — обычные платежи, оказавшиеся в paid-статусе мимо модельных
 * событий (импорт, ручные правки).
 *
 * По умолчанию СУХОЙ ПРОГОН. Запись делает только `--apply`, и она
 * идемпотентна: grantAccess() = syncWithoutDetaching, enrollInCourse() =
 * attach при отсутствии. Планировщик водит ежедневный сухой прогон и пишет
 * warning в логи при дрифте; --apply — ручной ops-шаг.
 *
 * Исторический архив из импорта 2024 года (paid-платежи завершённых курсов
 * без course_user) в окно --days не попадает: выдача этим парам групп = спам
 * дашбордов живых студентов завершёнными курсами. Это отдельное решение, не
 * эта команда; promise-grant проверяется всегда независимо от возраста.
 */
class ReconcileCourseUserAccess extends Command
{
    protected $signature = 'access:reconcile-course-user
        {--days=90 : Возраст окна для обычных платежей (first_paid_at)}
        {--apply : Выполнить запись (без флага — сухой прогон)}';

    protected $description = 'Найти paid-платежи без course_user и идемпотентно выдать доступ (грант + enroll)';

    /** Тарифы, не дающие доступа к курсу (как Payment::accessGrantingPaymentsForUser). */
    private const NON_ACCESS_TARIFFS = ['deposit', 'trial', 'Расход', 'salary_payout'];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $apply = (bool) $this->option('apply');

        $ids = Payment::query()
            ->paid()
            ->whereNull('refund_of_payment_id')
            ->whereNotNull('course_id')
            ->whereNotIn('tariff', self::NON_ACCESS_TARIFFS)
            ->where(function ($q) use ($days) {
                // Promise-grant (conditional) — класс инцидента, проверяем
                // всегда; обычные платежи — только в окне давности, чтобы не
                // трогать архив завершённых потоков.
                $q->where('is_conditional', 1)
                    ->orWhere('first_paid_at', '>=', now()->subDays($days));
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('course_user as cu')
                    ->whereColumn('cu.user_id', 'payments.user_id')
                    ->whereColumn('cu.course_id', 'payments.course_id');
            })
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Дрифт не найден: у всех paid-платежей окна есть course_user.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').": найдено платежей без course_user — ".$ids->count());

        $fixed = 0;
        $failed = [];

        foreach ($ids as $id) {
            $payment = Payment::find($id);
            if (! $payment || ! $payment->user) {
                $failed[$id] = 'платёж или студент не найден';

                continue;
            }

            $label = "payment #{$payment->id} (user {$payment->user_id}, course {$payment->course_id}, "
                ."tariff {$payment->tariff}".($payment->is_conditional ? ', promise-grant' : '').')';

            if (! $apply) {
                $this->line("  [dry] {$label}");

                continue;
            }

            try {
                // Оба шага канонические и идемпотентные: см. Payment::grantAccess /
                // Payment::enrollInCourse. fail-closed на курсе без групп бросит
                // исключение — платёж попадёт в $failed, остальные дорвются.
                $payment->grantAccess();
                $payment->enrollInCourse();
                $this->line("  [fix] {$label}");
                $fixed++;
            } catch (Throwable $e) {
                $this->error("  [err] {$label}: ".$e->getMessage());
                $failed[$id] = $e->getMessage();
            }
        }

        if (! $apply) {
            Log::warning(sprintf(
                'access:reconcile-course-user: %d paid-платежей без course_user (dry-run). Проверьте выборку и запустите с --apply.',
                $ids->count()
            ));
            $this->warn('Сухой прогон. Ничего не записано — повторите с --apply.');
        } else {
            $this->info("Итог: выдано {$fixed}, ошибок ".count($failed).'.');
            if ($failed !== []) {
                Log::error('access:reconcile-course-user: ошибки гранта: '.json_encode($failed, JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }
}
