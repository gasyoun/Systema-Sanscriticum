<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\TeacherPayoutAttributionSuggestion;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H3083 — предварительная сверка «начислено / выплачено / остаток» по
 * преподавателю в разрезе семьи потоков.
 *
 * Сервис НИЧЕГО НЕ ПИШЕТ. Он читает.
 *
 * В волне 1 подтверждённой разметки выплат ещё не существует, поэтому
 * `paid_out` собирается только из того, что нельзя перепутать:
 *
 *   - строк выплатного реестра `teacher_payouts` по этому преподавателю;
 *   - платежей-«Расходов» курса, ЯВНО заведённых на пользователя, связанного
 *     с этим преподавателем (`users.teacher_id`).
 *
 * Всё остальное — «Расходы» на служебного пользователя «Системные расходы» —
 * из данных неотличимо от аренды и рекламы, поэтому попадает не в `paid_out`,
 * а в отдельный список кандидатов и ждёт подтверждения человеком. Пока в этом
 * списке есть хоть одна строка, `attribution_confirmed` равен false, и
 * интерфейс ОБЯЗАН печатать остаток со словом «предварительно».
 *
 * H3084 (волна 2) добавил третий источник `paid_out` — подтверждённые
 * предложения атрибуции (`teacher_payout_attribution_suggestions`), и четвёртый
 * исход для «Расхода»: отклонённое предложение значит «это не выплата», и такой
 * платёж уходит из сверки совсем. Подтверждение НЕ создаёт строк в
 * `teacher_payouts` и `payments` — перенос в выплатной реестр остаётся
 * отдельным действием человека.
 *
 * База начисления берётся ВАЛОВАЯ (`subtract_returns => false`). Причина —
 * в §4 PLAN и в журнале решений: те же семь «Расходов» рассматриваются как
 * ВЫПЛАТЫ преподавателю. Учтя их ещё и как возвраты, уменьшающие базу, сверка
 * вычла бы одни и те же деньги дважды. TeacherSalaryService при этом не
 * меняется — он под фенсом; меняется только опция вызова.
 */
class TeacherPayoutReconciliation
{
    public function __construct(private readonly TeacherSalaryService $salary) {}

    /**
     * @param  Collection<int, Course>  $courses  курсы семьи
     * @return array{
     *   teacher_id: ?int,
     *   teacher_name: ?string,
     *   accrued: float,
     *   accrued_by_course: array<int, float>,
     *   paid_out: float,
     *   paid_out_lines: list<array{source:string, payment_id:?int, payout_id:?int, course_id:?int, amount:float, date:?string, note:?string}>,
     *   pending_candidates: list<array{payment_id:int, course_id:?int, course_title:string, user_id:?int, user_name:?string, amount:float, date:?string}>,
     *   pending_total: float,
     *   remainder: float,
     *   remainder_if_all_confirmed: float,
     *   attribution_confirmed: bool
     * }
     */
    public function forFamily(Collection $courses): array
    {
        $teacherId = $this->dominantTeacherId($courses);
        $teacher = $teacherId ? Teacher::find($teacherId) : null;

        $accruedByCourse = [];
        $accrued = 0.0;

        if ($teacher) {
            // Валовое начисление: без вычета «Расходов» из базы — см. шапку класса.
            $breakdown = $this->salary->breakdownForTeacher($teacher, null, null, ['subtract_returns' => false]);
            $familyIds = $courses->pluck('id')->map(fn ($id): int => (int) $id)->all();

            foreach ($breakdown['rows'] as $row) {
                if (! in_array((int) $row['course_id'], $familyIds, true)) {
                    continue;
                }
                $accruedByCourse[(int) $row['course_id']] = (float) $row['accrued'];
                $accrued += (float) $row['accrued'];
            }
        }

        [$paidOut, $paidLines, $pending] = $this->settlementLines($courses, $teacherId);

        $pendingTotal = array_sum(array_column($pending, 'amount'));
        $remainder = Money::round($accrued - $paidOut);

        return [
            'teacher_id' => $teacherId,
            'teacher_name' => $teacher?->name,
            'accrued' => Money::round($accrued),
            'accrued_by_course' => $accruedByCourse,
            'paid_out' => Money::round($paidOut),
            'paid_out_lines' => $paidLines,
            'pending_candidates' => $pending,
            'pending_total' => Money::round($pendingTotal),
            'remainder' => $remainder,
            'remainder_if_all_confirmed' => Money::round($accrued - $paidOut - $pendingTotal),
            'attribution_confirmed' => $pending === [],
        ];
    }

    /**
     * Преподаватель семьи — тот, кому начисляется большинство её курсов.
     * Курс без `teacher_id` (как 424 до волны 2) голоса не имеет.
     *
     * Публичный с H3084: тем же правилом пользуется детектор атрибуций
     * `salary:detect-payout-attributions`, и второй копии правила «чей это
     * поток» в системе быть не должно.
     *
     * @param  Collection<int, Course>  $courses
     */
    public function dominantTeacherId(Collection $courses): ?int
    {
        $votes = [];
        foreach ($courses as $course) {
            if ($course->teacher_id) {
                $votes[(int) $course->teacher_id] = ($votes[(int) $course->teacher_id] ?? 0) + 1;
            }
        }

        if ($votes === []) {
            return null;
        }

        arsort($votes);

        return (int) array_key_first($votes);
    }

    /**
     * @return array{0: float, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function settlementLines(Collection $courses, ?int $teacherId): array
    {
        $courseIds = $courses->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $paidOut = 0.0;
        $lines = [];
        $pending = [];

        if ($teacherId) {
            // 1. Выплатной реестр. Только строки, ЯВНО привязанные к курсу семьи:
            //    выплата без course_id относится ко всей работе преподавателя и
            //    приписывать её этой семье было бы догадкой.
            //    Зачёт аванса считается так же, как в TeacherSalaryService::summaryForAll
            //    (по type = advance берётся settled_amount) — второй копии правила
            //    «сколько выплачено» в системе быть не должно.
            $payouts = TeacherPayout::query()
                ->where('teacher_id', $teacherId)
                ->whereIn('course_id', $courseIds)
                ->orderBy('paid_at')
                ->get();

            foreach ($payouts as $p) {
                $amount = $p->type === TeacherPayout::TYPE_ADVANCE
                    ? (float) ($p->settled_amount ?? 0)
                    : (float) $p->amount;
                $amount = abs($amount);
                if ($amount <= 0.0) {
                    continue;
                }

                $paidOut += $amount;
                $lines[] = [
                    'source' => 'teacher_payouts',
                    'payment_id' => null,
                    'payout_id' => (int) $p->id,
                    'course_id' => $p->course_id ? (int) $p->course_id : null,
                    'amount' => Money::round($amount),
                    'date' => $p->paid_at?->format('Y-m-d'),
                    'note' => 'строка выплатного реестра'.($p->comment ? ' · '.$p->comment : ''),
                ];
            }
        }

        // 2. Платежи-«Расходы» курсов семьи. Три исхода:
        //      - заведён прямо на пользователя преподавателя → в paid_out;
        //      - подтверждённое предложение атрибуции (H3084)  → в paid_out;
        //      - отклонённое предложение                       → это не выплата
        //        (аренда, реклама), человек так решил — из сверки уходит;
        //      - всё остальное                                 → в очередь.
        //
        // ⚠️ Дедупликация идёт по `payment_id`, а не по сумме: суммы в этой
        // семье не уникальны, а платёж, УЖЕ учтённый напрямую, легко получает
        // ещё и подтверждённое предложение — на боевых данных это #13573 на
        // 50 000 ₽. Учтя его дважды, сверка занизила бы остаток ровно на эту
        // сумму (риск, описанный в H3084 до начала работ).
        $teacherUserIds = $teacherId
            ? DB::table('users')->where('teacher_id', $teacherId)->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];

        $expenses = Payment::query()
            ->whereIn('course_id', $courseIds)
            ->paid()
            ->whereIn('tariff', TeacherSalaryService::NON_REVENUE_TARIFFS)
            ->with(['course:id,title', 'user:id,name'])
            ->orderBy('created_at')
            ->get();

        $suggestions = $teacherId
            ? TeacherPayoutAttributionSuggestion::query()
                ->where('teacher_id', $teacherId)
                ->whereIn('payment_id', $expenses->pluck('id')->map(fn ($id): int => (int) $id)->all())
                ->get()
                ->keyBy(fn (TeacherPayoutAttributionSuggestion $s): int => (int) $s->payment_id)
            : collect();

        /** @var array<int, true> $countedPaymentIds */
        $countedPaymentIds = [];

        foreach ($expenses as $e) {
            $amount = abs((float) $e->amount);
            if ($amount <= 0.0) {
                continue;
            }

            $paymentId = (int) $e->id;
            if (isset($countedPaymentIds[$paymentId])) {
                continue;
            }

            if ($e->user_id && in_array((int) $e->user_id, $teacherUserIds, true)) {
                $countedPaymentIds[$paymentId] = true;
                $paidOut += $amount;
                $lines[] = [
                    'source' => 'payment_expense_direct',
                    'payment_id' => $paymentId,
                    'payout_id' => null,
                    'course_id' => $e->course_id ? (int) $e->course_id : null,
                    'amount' => Money::round($amount),
                    'date' => $e->created_at?->format('Y-m-d'),
                    'note' => 'платёж-«Расход» заведён прямо на пользователя преподавателя',
                ];

                continue;
            }

            $suggestion = $suggestions->get($paymentId);

            if ($suggestion?->status === TeacherPayoutAttributionSuggestion::STATUS_CONFIRMED) {
                $countedPaymentIds[$paymentId] = true;
                $paidOut += $amount;
                $lines[] = [
                    'source' => 'attribution_confirmed',
                    'payment_id' => $paymentId,
                    'payout_id' => null,
                    'course_id' => $e->course_id ? (int) $e->course_id : null,
                    'amount' => Money::round($amount),
                    'date' => $e->created_at?->format('Y-m-d'),
                    'note' => 'разметка подтверждена'
                        .($suggestion->resolved_at ? ' '.$suggestion->resolved_at->format('d.m.Y') : '')
                        .($suggestion->resolver?->name ? ' · '.$suggestion->resolver->name : ''),
                ];

                continue;
            }

            if ($suggestion?->status === TeacherPayoutAttributionSuggestion::STATUS_REJECTED) {
                // Человек сказал «это не выплата преподавателю». Вопрос закрыт:
                // в очереди строка больше не висит и «предварительно» с экрана
                // из-за неё не печатается.
                continue;
            }

            $pending[] = [
                'payment_id' => $paymentId,
                'course_id' => $e->course_id ? (int) $e->course_id : null,
                'course_title' => (string) ($e->course?->title ?? '—'),
                'user_id' => $e->user_id ? (int) $e->user_id : null,
                'user_name' => $e->user?->name,
                'amount' => Money::round($amount),
                'date' => $e->created_at?->format('Y-m-d'),
            ];
        }

        return [$paidOut, $lines, $pending];
    }
}
