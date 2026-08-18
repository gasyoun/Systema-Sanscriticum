<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Payment;
use App\Models\TeacherPayoutAttributionSuggestion;
use App\Services\TeacherPayoutReconciliation;
use App\Services\TeacherSalaryService;
use App\Support\Plural;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H3084, шаг 14 — детектор «этот «Расход» похож на выплату преподавателю».
 *
 * Разметка семи платежей семьи «Кашмирский шиваизм» вручную занимает у
 * бухгалтера вечер и делается раз в квартал; детектор готовит предложения,
 * решение остаётся за человеком.
 *
 * Чего детектор НЕ предлагает — и это не оптимизация, а защита от задвоения:
 *
 *   - платежи, заведённые на пользователя, УЖЕ связанного с этим
 *     преподавателем (`users.teacher_id`): сверка считает их в «выплачено»
 *     напрямую, источником `payment_expense_direct`. Предложить их к
 *     подтверждению значило бы дать бухгалтеру подтвердить уже посчитанное —
 *     ровно тот случай, когда `paid_out` вырастает из воздуха. На боевых
 *     данных это платёж #13573 на 50 000 ₽: после `salary:link-teacher-users`
 *     он размечается сам, и руками остаётся шесть платежей, а не семь;
 *   - платежи, по которым предложение уже есть (в любом статусе).
 *
 * Дедупликация идёт по `payment_id`, не по сумме: суммы в этой семье не
 * уникальны.
 *
 * Команда пишет ТОЛЬКО в `teacher_payout_attribution_suggestions` и только со
 * статусом `pending`. Ни `teacher_payouts`, ни `payments` не трогаются.
 */
class DetectPayoutAttributions extends Command
{
    protected $signature = 'salary:detect-payout-attributions
        {--apply : Завести предложения в статусе pending}
        {--family= : Ограничить одной семьёй потоков (слаг courses.course_family)}';

    protected $description = 'Найти платежи-«Расходы», похожие на выплаты преподавателю, и завести их в очередь подтверждения';

    public function handle(TeacherPayoutReconciliation $reconciliation): int
    {
        $familyOption = trim((string) ($this->option('family') ?? ''));

        $courses = Course::query()
            ->whereNotNull('course_family')
            ->where('course_family', '!=', '')
            ->when($familyOption !== '', fn ($q) => $q->where('course_family', $familyOption))
            ->orderBy('id')
            ->get();

        if ($courses->isEmpty()) {
            $this->warn('Курсов с заполненной семьёй потоков не найдено — сначала `courses:backfill-families --apply`.');

            return self::SUCCESS;
        }

        $existing = TeacherPayoutAttributionSuggestion::query()
            ->pluck('status', 'payment_id')
            ->all();

        $rows = [];
        $toCreate = [];

        /** @var Collection<int, Course> $members */
        foreach ($courses->groupBy(fn (Course $c): string => (string) $c->course_family) as $family => $members) {
            $teacherId = $reconciliation->dominantTeacherId($members);
            if ($teacherId === null) {
                continue;
            }

            $courseIds = $members->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $teacherUserIds = DB::table('users')->where('teacher_id', $teacherId)
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $expenses = Payment::query()
                ->whereIn('course_id', $courseIds)
                ->paid()
                ->whereIn('tariff', TeacherSalaryService::NON_REVENUE_TARIFFS)
                ->with(['course:id,title', 'user:id,name'])
                ->orderBy('created_at')
                ->get();

            foreach ($expenses as $payment) {
                $paymentId = (int) $payment->id;
                $amount = abs((float) $payment->amount);
                if ($amount <= 0.0) {
                    continue;
                }

                if ($payment->user_id !== null && in_array((int) $payment->user_id, $teacherUserIds, true)) {
                    $rows[] = [
                        $paymentId, $family, $payment->course_id, $this->money($amount),
                        $payment->user?->name ?? '—',
                        'уже считается напрямую (пользователь связан с преподавателем) — не предлагаю',
                    ];

                    continue;
                }

                if (array_key_exists($paymentId, $existing)) {
                    $rows[] = [
                        $paymentId, $family, $payment->course_id, $this->money($amount),
                        $payment->user?->name ?? '—',
                        'предложение уже есть — статус «'.$existing[$paymentId].'»',
                    ];

                    continue;
                }

                [$confidence, $reason] = $this->assess($payment, $members->firstWhere('id', $payment->course_id));

                $toCreate[$paymentId] = [
                    'payment_id' => $paymentId,
                    'teacher_id' => $teacherId,
                    'course_id' => $payment->course_id ? (int) $payment->course_id : null,
                    'course_family' => (string) $family,
                    'amount' => $amount,
                    'paid_on' => $payment->created_at?->toDateString(),
                    'confidence' => $confidence,
                    'reason' => $reason,
                    'status' => TeacherPayoutAttributionSuggestion::STATUS_PENDING,
                ];

                $rows[] = [
                    $paymentId, $family, $payment->course_id, $this->money($amount),
                    $payment->user?->name ?? '—',
                    ($this->option('apply') ? 'завожу' : 'завёл бы').' · уверенность '.number_format($confidence * 100, 0).' %',
                ];
            }
        }

        if ($rows === []) {
            $this->info('Платежей-«Расходов» по семьям с известным преподавателем не найдено.');

            return self::SUCCESS;
        }

        $this->table(['Платёж', 'Семья', 'Курс', 'Сумма', 'На кого заведён', 'Что будет'], $rows);

        $total = array_sum(array_column($toCreate, 'amount'));
        $this->line('');
        $this->line(sprintf(
            'К разметке человеком: %d %s на %s ₽.',
            count($toCreate),
            Plural::ru(count($toCreate), 'платёж', 'платежа', 'платежей'),
            $this->money($total),
        ));

        if (! $this->option('apply')) {
            $this->warn('Режим отчёта: в базу не записано ничего. Повторите с --apply.');

            return self::SUCCESS;
        }

        $created = 0;
        DB::transaction(function () use ($toCreate, &$created) {
            foreach ($toCreate as $paymentId => $attributes) {
                // Уникальность payment_id есть и в схеме; здесь — чтобы гонка
                // с параллельным прогоном не свалилась исключением.
                if (TeacherPayoutAttributionSuggestion::where('payment_id', $paymentId)->exists()) {
                    continue;
                }

                TeacherPayoutAttributionSuggestion::create($attributes);
                $created++;
            }
        });

        $this->info("Предложений заведено: {$created}. Все — в статусе «ожидает», подтверждает бухгалтер.");

        return self::SUCCESS;
    }

    /**
     * Уверенность и её основание одной строкой. Основание печатается человеку
     * и хранится в строке предложения: бухгалтер подтверждает не «0.8», а
     * названную причину.
     *
     * @return array{float, string}
     */
    private function assess(Payment $payment, ?Course $course): array
    {
        $confidence = 0.5;
        $reasons = ['платёж курса проведён как «'.$payment->tariff.'»'];

        if ($course?->teacher_id !== null) {
            $confidence += 0.2;
            $reasons[] = 'курс «'.mb_strimwidth((string) $course?->title, 0, 40, '…').'» закреплён за этим преподавателем';
        }

        if ((float) $payment->amount < 0) {
            $confidence += 0.1;
            $reasons[] = 'сумма отрицательная — это выплата, а не поступление';
        }

        if ($payment->user_id === null) {
            $confidence += 0.05;
            $reasons[] = 'платёж не заведён ни на кого конкретно';
        } else {
            $reasons[] = 'заведён на пользователя «'.($payment->user?->name ?? '#'.$payment->user_id).'»';
        }

        return [min(1.0, round($confidence, 2)), implode('; ', $reasons).'.'];
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
