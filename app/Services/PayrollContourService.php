<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Весь платёжный контур вокруг календаря выплат — READ ONLY (H-контур без бухгалтера).
 *
 * Никогда не пишет teacher_payouts / payments. Две задачи:
 *  1) Персонал и повторяемые получатели НЕ-преподаватели: у них нет движка начисления,
 *     их ЗП живёт только «Расход»-строками. Ставка = среднее последних ≤3 плативших
 *     месяцев; долг = ставка × полные календарные месяцы тишины. Экстраполяция всегда
 *     помечена assumption — решение за человеком.
 *  2) Собранность должников по преподавателям: сколько студентов и ₽ ещё не оплатили
 *     активные блоки их курсов (та же логика, что раздел «Должники») — выплата
 *     преподавателю без учёта несобранного вводит в заблуждение.
 */
final class PayrollContourService
{
    /** Получатели смешанного opex («Системные расходы») — им нельзя считать «ставку зарплаты». */
    private const MIXED_OPEX_NAMES = ['Системные расходы'];

    public function __construct(private readonly DebtorsReport $debtors) {}

    /**
     * @return array{
     *   payees: list<array<string, mixed>>,
     *   totals: array{monthly_estimate: float, owed_estimate: float},
     *   money_tables_moved: bool
     * }
     */
    public function staffPayees(): array
    {
        $paymentsBefore = Payment::query()->count();
        $payoutsBefore = TeacherPayout::query()->count();

        $teacherUserIds = User::query()->whereNotNull('teacher_id')->pluck('id')
            ->map(fn ($v) => (int) $v)->all();

        $rows = Payment::query()
            ->where('tariff', 'Расход')
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNull('refund_of_payment_id') // возвраты студентам — не выплаты получателям
            ->where('created_at', '>=', now()->subMonths(12))
            ->whereNotIn('user_id', $teacherUserIds)
            ->get(['id', 'user_id', 'amount', 'created_at']);

        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        $nowMonth = now()->startOfMonth();
        $payees = [];
        foreach ($rows->groupBy('user_id') as $uid => $group) {
            /** @var User|null $user */
            $user = $users->get($uid);
            $name = (string) ($user?->name ?? ('#'.$uid));
            $isMixed = in_array($name, self::MIXED_OPEX_NAMES, true);

            $byMonth = [];
            foreach ($group as $r) {
                $m = Carbon::parse($r->created_at)->format('Y-m');
                $byMonth[$m] = ($byMonth[$m] ?? 0.0) + (float) $r->amount;
            }
            ksort($byMonth);
            $monthsPaid = count(array_filter($byMonth, fn ($v) => $v != 0.0));
            $lastMonthStr = array_key_last($byMonth);
            $lastAt = $lastMonthStr !== null ? Carbon::parse($lastMonthStr.'-01')->startOfMonth() : null;

            $recent = array_slice($byMonth, -3, preserve_keys: true);
            $rate = count($recent) > 0 ? round(abs(array_sum($recent) / count($recent)), 2) : 0.0;

            $silentMonths = 0;
            if ($lastAt !== null && $lastAt->lt($nowMonth)) {
                $silentMonths = (int) round($lastAt->diffInMonths($nowMonth));
            }

            // Смешанный opex и несистемные получатели: оценку долга ставкой не считаем.
            $recurring = ! $isMixed && $monthsPaid >= 3;
            $owed = $recurring ? round($rate * $silentMonths, 2) : 0.0;

            $payees[] = [
                'user_id' => (int) $uid,
                'name' => $name,
                'category' => $isMixed ? 'смешанный opex' : ($recurring ? 'персонал' : 'разовый'),
                'n' => $group->count(),
                'sum_12m' => round((float) $group->sum('amount'), 2),
                'monthly_rate' => $recurring ? $rate : null,
                'months_paid_12m' => $monthsPaid,
                'last_month' => $lastMonthStr,
                'silent_months' => $silentMonths,
                'owed_estimate' => $owed,
                'assumption' => $recurring && $silentMonths > 0,
            ];
        }

        usort($payees, fn ($a, $b) => [$b['category'], abs($a['sum_12m'])] <=> [$a['category'], abs($b['sum_12m'])]);

        $recurringOnly = array_filter($payees, fn ($p) => $p['category'] === 'персонал');

        return [
            'payees' => array_values($payees),
            'totals' => [
                'monthly_estimate' => round((float) array_sum(array_map(fn ($p) => (float) $p['monthly_rate'], $recurringOnly)), 2),
                'owed_estimate' => round((float) array_sum(array_map(fn ($p) => (float) $p['owed_estimate'], $recurringOnly)), 2),
            ],
            'money_tables_moved' => Payment::query()->count() !== $paymentsBefore
                || TeacherPayout::query()->count() !== $payoutsBefore,
        ];
    }

    /**
     * Собранность должников по каждому преподавателю (его курсы целиком).
     *
     * @return array<int, array{teacher_id: int, name: string, pairs: int, amount: float, by_course: array<int, float>}>
     */
    public function collectionReadinessByTeacher(): array
    {
        $teachers = Teacher::query()->orderBy('id')->get();
        $out = [];

        foreach ($teachers as $teacher) {
            $courseIds = $teacher->allTaughtCourses()->modelKeys();
            if ($courseIds === []) {
                continue;
            }

            $query = $this->debtors->query()->whereIn('d.course_id', $courseIds);
            $total = $this->debtors->totalDebtForQuery($query);

            if ($total['pairs'] === 0) {
                continue;
            }

            ksort($total['by_course']);
            $out[(int) $teacher->id] = [
                'teacher_id' => (int) $teacher->id,
                'name' => (string) $teacher->name,
                'pairs' => (int) $total['pairs'],
                'amount' => round((float) $total['amount'], 2),
                'by_course' => array_map(fn ($v) => round((float) $v, 2), $total['by_course']),
            ];
        }

        return $out;
    }

    /**
     * «Должники платят»: реальные оплаты студентов на курсах преподавателя за
     * последние $days дней — кто, сколько, за какие блоки и с какой задержкой
     * относительно старта самого раннего покрытого блока. Задержка <0 = заплатил
     * заранее; null = блоки без дат, задержку определить нельзя.
     *
     * @return array<int, array{teacher_id: int, name: string, rows: list<array<string, mixed>>}>
     */
    public function recentPaymentsByTeacher(int $days = 35): array
    {
        $paymentsBefore = Payment::query()->count();
        $payoutsBefore = TeacherPayout::query()->count();

        $since = now()->subDays(max(1, $days))->startOfDay();

        $out = [];
        foreach (Teacher::query()->orderBy('id')->get() as $teacher) {
            $rows = [];
            foreach ($teacher->allTaughtCourses() as $course) {
                if ($course->salaryTermsFor((int) $teacher->id) === null) {
                    continue;
                }

                $blockStarts = CourseBlock::query()
                    ->where('course_id', $course->id)
                    ->whereNotNull('starts_at')
                    ->pluck('starts_at', 'number');

                $payments = Payment::query()
                    ->with('user:id,name')
                    ->where('course_id', $course->id)
                    ->paid()
                    ->real()
                    ->schoolReceived()
                    ->whereNotIn('tariff', TeacherSalaryService::NON_REVENUE_TARIFFS)
                    ->where('amount', '>', 0)
                    ->where('created_at', '>=', $since)
                    ->orderByDesc('created_at')
                    ->limit(100)
                    ->get(['id', 'user_id', 'amount', 'tariff', 'start_block', 'end_block', 'created_at']);

                foreach ($payments as $p) {
                    $covered = [];
                    foreach ($blockStarts as $number => $start) {
                        if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, (int) $number)) {
                            $covered[(int) $number] = Carbon::parse($start);
                        }
                    }
                    ksort($covered);

                    $delayDays = null;
                    if ($covered !== [] && $p->created_at !== null) {
                        $earliestStart = $covered[min(array_keys($covered))]->copy()->startOfDay();
                        $delayDays = (int) $earliestStart->diffInDays($p->created_at->copy()->startOfDay(), false);
                    }

                    $rows[] = [
                        'student' => (string) ($p->user?->name ?? ('#'.$p->user_id)),
                        'course' => (string) $course->title,
                        'blocks' => DebtorsReport::formatBlockRanges(array_map(intval(...), array_keys($covered))),
                        'amount' => round((float) $p->amount, 2),
                        'paid_at' => $p->created_at?->toDateString(),
                        'delay_days' => $delayDays,
                    ];
                }
            }

            if ($rows === []) {
                continue;
            }
            usort($rows, fn ($a, $b) => strcasecmp((string) $a['paid_at'], (string) $b['paid_at']));
            $out[] = [
                'teacher_id' => (int) $teacher->id,
                'name' => (string) $teacher->name,
                'rows' => $rows,
            ];
        }

        $moved = Payment::query()->count() !== $paymentsBefore
            || TeacherPayout::query()->count() !== $payoutsBefore;
        if ($moved) { // @codeCoverageIgnore
            report(new \RuntimeException('payroll contour: money tables moved during a read-only slice'));
        }

        return $out;
    }
}
