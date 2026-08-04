<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\MutualSettlement;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Взаимозачёт «преподаватель-ученик» (H1730, волна B).
 *
 * Один и тот же человек платит школе как ученик и получает от школы гонорар как
 * преподаватель. Если гонять деньги в обе стороны, на каждом проходе теряется
 * 6 % НПД, поэтому ruling MG 27-07-2026: зачитываем ДО выплаты, а условия
 * фиксируем на текущий блок/поток.
 *
 * Сервис считает обе цифры с расшифровкой и умеет заморозить их в акт
 * ({@see MutualSettlement}). Две дисциплины, которые здесь важнее всего:
 *
 *  1. ВТОРОГО расчёта ЗП не появляется. Зарплатная цифра берётся у
 *     {@see TeacherSalaryService::totalForTeacher()} — того же источника, что
 *     печатает страница «Зарплаты». Здесь только разбивка по курсам поверх.
 *  2. Выручка школы не трогается. Её платежи остаются доходом в полном объёме,
 *     зачёт живёт исключительно на выплатной стороне (см. B5 на странице ЗП).
 *
 * Фиксация ЗАМОРАЖИВАЕТ числа, а не пересчитывает: после status=fixed суммы
 * читаются из строки акта. Иначе поздняя оплата задним числом сдвинула бы уже
 * подписанную цифру и слово «зафиксировали» ничего бы не значило.
 */
class MutualSettlementService
{
    /**
     * Тарифы, не являющиеся оплатой обучения: возвраты и зеркала выплат.
     * Держим ОДИН список на два сервиса — своей копии здесь заводить нельзя,
     * иначе цифра «оплачено» разъедется с базой выручки в ЗП.
     *
     * @return list<string>
     */
    public static function nonTuitionTariffs(): array
    {
        return TeacherSalaryService::NON_REVENUE_TARIFFS;
    }

    /**
     * Сколько человек заплатил школе КАК УЧЕНИК за окно, с построчной
     * расшифровкой. Берём его оплаченные неусловные платежи, кроме возвратов и
     * зеркал выплат; платежи, ушедшие мимо кассы напрямую преподавателю
     * (RECEIVED_TEACHER), в обязательство перед школой не входят.
     *
     * @return array{total: float, lines: list<array{payment_id:int, course_id:?int,
     *     course_title:string, tariff:?string, amount:float, date:?string, month:?string}>}
     */
    public function tuitionFor(User $user, $from = null, $to = null): array
    {
        $query = Payment::query()
            ->where('user_id', $user->id)
            ->paid()
            ->real()
            ->whereNotIn('tariff', self::nonTuitionTariffs())
            ->where('amount', '>', 0)
            // NULL-safe: в SQL `!= 'x'` по NULL даёт NULL и молча выбрасывает
            // строку. Колонка сейчас NOT NULL с дефолтом, но условие должно
            // читаться так же, как PHP-предикат в TeacherSalaryService
            // (`$p->received_account !== RECEIVED_TEACHER`), иначе однажды
            // появившийся NULL тихо занизил бы сумму оплат.
            ->where(fn ($q) => $q
                ->where('received_account', '!=', Payment::RECEIVED_TEACHER)
                ->orWhereNull('received_account'))
            ->with('course:id,title')
            ->orderBy('created_at');

        if ($from !== null) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to !== null) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $lines = [];
        $total = 0.0;

        foreach ($query->get() as $payment) {
            $amount = (float) $payment->amount;
            $total += $amount;
            $lines[] = [
                'payment_id' => (int) $payment->id,
                'course_id' => $payment->course_id ? (int) $payment->course_id : null,
                'course_title' => (string) ($payment->course?->title ?? '—'),
                'tariff' => $payment->tariff,
                'amount' => Money::round($amount),
                'date' => $payment->created_at?->format('d.m.Y'),
                'month' => $payment->created_at?->format('Y-m'),
            ];
        }

        return ['total' => Money::round($total), 'lines' => $lines];
    }

    /**
     * Сколько человеку начислено КАК ПРЕПОДАВАТЕЛЮ за окно — accrual по блокам,
     * ровно та цифра, что видна на странице ЗП. Делегируем
     * {@see TeacherSalaryService}; здесь только разбивка по курсам поверх.
     *
     * @return array{total: float, lines: list<array<string, mixed>>}
     */
    public function salaryFor(Teacher $teacher, $from = null, $to = null): array
    {
        $breakdown = app(TeacherSalaryService::class)->breakdownForTeacher($teacher, $from, $to);

        $lines = array_map(fn (array $row): array => [
            'course_id' => (int) $row['course_id'],
            'course_title' => (string) $row['title'],
            'salary_type' => $row['salary_type'],
            'salary_value' => (float) $row['salary_value'],
            'students' => (int) $row['students'],
            'revenue' => (float) $row['revenue'],
            'amount' => (float) $row['accrued'],
        ], $breakdown['rows']);

        return ['total' => Money::round((float) $breakdown['total']), 'lines' => $lines];
    }

    /**
     * Обе суммы за окно, зачёт (минимум из них), направление и остаток.
     * Ничего не пишет — это и превью страницы, и тело команды settlement:preview.
     *
     * @return array{user_id:int, teacher_id:int, course_id:?int, block_number:?int,
     *     period_from:?string, period_to:?string, tuition:array{total:float, lines:list<array<string, mixed>>},
     *     salary:array{total:float, lines:list<array<string, mixed>>}, tuition_amount:float,
     *     salary_amount:float, offset_amount:float, net_direction:string, net_amount:float}
     */
    public function preview(User $user, ?int $courseId = null, ?int $blockNumber = null, $from = null, $to = null): array
    {
        $teacher = $this->teacherForUser($user);

        [$from, $to] = $this->resolvePeriod($courseId, $blockNumber, $from, $to);

        $tuition = $this->tuitionFor($user, $from, $to);
        $salary = $this->salaryFor($teacher, $from, $to);

        return array_merge(
            [
                'user_id' => (int) $user->id,
                'teacher_id' => (int) $teacher->id,
                'course_id' => $courseId,
                'block_number' => $blockNumber,
                'period_from' => $from ? Carbon::parse($from)->toDateString() : null,
                'period_to' => $to ? Carbon::parse($to)->toDateString() : null,
                'tuition' => $tuition,
                'salary' => $salary,
            ],
            self::settle((float) $tuition['total'], (float) $salary['total']),
        );
    }

    /**
     * Чистая арифметика зачёта — зачитывается меньшая из сумм, остаток идёт в ту
     * сторону, чья сумма больше. Статическая и без побочных эффектов, чтобы её
     * можно было проверить в тесте отдельно от данных.
     *
     * @return array{tuition_amount:float, salary_amount:float, offset_amount:float,
     *     net_direction:string, net_amount:float}
     */
    public static function settle(float $tuition, float $salary): array
    {
        $tuition = Money::round(max(0.0, $tuition));
        $salary = Money::round(max(0.0, $salary));
        $offset = Money::round(min($tuition, $salary));
        $net = Money::round(abs($salary - $tuition));

        $direction = match (true) {
            $salary > $tuition => MutualSettlement::DIRECTION_SCHOOL_PAYS,
            $tuition > $salary => MutualSettlement::DIRECTION_STUDENT_PAYS,
            default => MutualSettlement::DIRECTION_ZERO,
        };

        return [
            'tuition_amount' => $tuition,
            'salary_amount' => $salary,
            'offset_amount' => $offset,
            'net_direction' => $direction,
            'net_amount' => $direction === MutualSettlement::DIRECTION_ZERO ? 0.0 : $net,
        ];
    }

    /**
     * Зафиксировать акт: прежний активный акт того же человека за тот же период
     * уходит в superseded, новый пишется со status=fixed и снимком breakdown НА
     * МОМЕНТ ФИКСАЦИИ. Дальше суммы читаются из строки, а не пересчитываются.
     */
    public function fix(User $user, ?int $courseId = null, ?int $blockNumber = null, $from = null, $to = null, ?int $fixedBy = null, ?string $note = null): MutualSettlement
    {
        [$from, $to] = $this->resolvePeriod($courseId, $blockNumber, $from, $to);
        $periodFrom = $from !== null ? Carbon::parse($from)->toDateString() : null;
        $periodTo = $to !== null ? Carbon::parse($to)->toDateString() : null;

        return DB::transaction(function () use ($user, $courseId, $blockNumber, $periodFrom, $periodTo, $fixedBy, $note): MutualSettlement {
            /** @var User $lockedUser */
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $matchingActs = MutualSettlement::query()
                ->where('user_id', $lockedUser->id)
                ->where('course_id', $courseId)
                ->where('block_number', $blockNumber)
                ->where('period_from', $periodFrom)
                ->where('period_to', $periodTo)
                ->lockForUpdate()
                ->get();

            if ($matchingActs->contains(fn (MutualSettlement $act): bool => $act->payout_id !== null)) {
                throw new \DomainException(
                    'Акт этого периода уже использован в выплате и не может быть пересмотрен.'
                );
            }

            $matchingActs
                ->where('status', MutualSettlement::STATUS_FIXED)
                ->each(fn (MutualSettlement $prev) => $prev->update([
                    'status' => MutualSettlement::STATUS_SUPERSEDED,
                ]));

            $preview = $this->preview(
                $lockedUser,
                $courseId,
                $blockNumber,
                $periodFrom,
                $periodTo,
            );

            return MutualSettlement::create([
                'user_id' => (int) $lockedUser->id,
                'teacher_id' => $preview['teacher_id'],
                'course_id' => $courseId,
                'block_number' => $blockNumber,
                'period_from' => $preview['period_from'],
                'period_to' => $preview['period_to'],
                'tuition_amount' => $preview['tuition_amount'],
                'salary_amount' => $preview['salary_amount'],
                'offset_amount' => $preview['offset_amount'],
                'net_direction' => $preview['net_direction'],
                'net_amount' => $preview['net_amount'],
                'status' => MutualSettlement::STATUS_FIXED,
                'fixed_at' => now(),
                'fixed_by' => $fixedBy,
                'note' => $note,
                'breakdown' => [
                    'tuition_lines' => $preview['tuition']['lines'],
                    'salary_lines' => $preview['salary']['lines'],
                    'fixed_preview' => [
                        'tuition_amount' => $preview['tuition_amount'],
                        'salary_amount' => $preview['salary_amount'],
                        'offset_amount' => $preview['offset_amount'],
                        'net_direction' => $preview['net_direction'],
                        'net_amount' => $preview['net_amount'],
                    ],
                ],
            ]);
        });
    }

    /**
     * Зафиксированные и ещё не израсходованные акты преподавателя — кандидаты
     * для зачёта в НОВОЙ выплате (B5). Пустой зачёт (offset = 0) в кандидаты не
     * идёт: вычитать ноль незачем, а строка в форме только путала бы.
     *
     * @return list<array{settlement_id:int, offset_amount:float, tuition_amount:float,
     *     salary_amount:float, course_id:?int, course_title:string, block_number:?int,
     *     period_from:?string, period_to:?string, fixed_at:?string, note:?string}>
     */
    public function availableForTeacher(Teacher $teacher): array
    {
        return MutualSettlement::query()
            ->where('teacher_id', $teacher->id)
            ->fixed()
            ->unconsumed()
            ->where('offset_amount', '>', 0)
            ->with('course:id,title')
            ->orderBy('id')
            ->get()
            ->map(fn (MutualSettlement $s): array => [
                'settlement_id' => (int) $s->id,
                'offset_amount' => Money::round((float) $s->offset_amount),
                'tuition_amount' => Money::round((float) $s->tuition_amount),
                'salary_amount' => Money::round((float) $s->salary_amount),
                'course_id' => $s->course_id ? (int) $s->course_id : null,
                'course_title' => (string) ($s->course?->title ?? '—'),
                'block_number' => $s->block_number ? (int) $s->block_number : null,
                'period_from' => $s->period_from?->toDateString(),
                'period_to' => $s->period_to?->toDateString(),
                'fixed_at' => $s->fixed_at?->toDateTimeString(),
                'note' => $s->note,
            ])
            ->all();
    }

    /**
     * Пометить акт израсходованным в выплате. Однократность держится НА УРОВНЕ
     * ДАННЫХ: обновляем условно (`whereNull('payout_id')`) и смотрим на число
     * затронутых строк, поэтому две параллельные выплаты не могут зачесть один
     * акт дважды — вторая получит false и ничего не спишет.
     */
    public function consume(MutualSettlement $settlement, int $payoutId): bool
    {
        $claimed = MutualSettlement::query()
            ->whereKey($settlement->getKey())
            ->whereNull('payout_id')
            ->where('status', MutualSettlement::STATUS_FIXED)
            ->update(['payout_id' => $payoutId]);

        if ($claimed > 0) {
            $settlement->refresh();

            return true;
        }

        return false;
    }

    /**
     * Пора ли пересматривать условия акта — ровно условие MG «если её доход
     * будет больше оплат курсов, пересмотрим». Сравниваем ЖИВЫЕ цифры за период
     * акта: начисление перевесило оплаты ⇒ истина.
     */
    public function needsReview(MutualSettlement $settlement): bool
    {
        if ($settlement->status !== MutualSettlement::STATUS_FIXED) {
            return false;
        }

        $user = $settlement->user;
        if (! $user instanceof User) {
            return false;
        }

        $from = $settlement->period_from;
        $to = $settlement->period_to;

        $liveTuition = (float) $this->tuitionFor($user, $from, $to)['total'];
        $liveSalary = (float) $this->salaryFor($this->teacherForUser($user), $from, $to)['total'];

        return $liveSalary > $liveTuition;
    }

    /**
     * Зафиксированные акты, по которым живые цифры требуют пересмотра — для
     * бейджа у пункта меню (B6), чтобы условие пересмотра не жило в чьей-то
     * памяти.
     */
    public function needsReviewCount(): int
    {
        return MutualSettlement::query()
            ->fixed()
            ->with('user')
            ->get()
            ->filter(fn (MutualSettlement $s) => $this->needsReview($s))
            ->count();
    }

    /**
     * Инвариант «один человек»: акт имеет смысл только если ученик и
     * преподаватель — одно физическое лицо, то есть users.teacher_id указывает
     * на этого преподавателя. Падаем осмысленно, а не считаем чужие деньги.
     */
    private function teacherForUser(User $user): Teacher
    {
        if (empty($user->teacher_id)) {
            throw new \DomainException(
                "У пользователя #{$user->id} не проставлен teacher_id — взаимозачёт возможен только когда ученик и преподаватель это один человек."
            );
        }

        $teacher = Teacher::find($user->teacher_id);
        if (! $teacher instanceof Teacher) {
            throw new \DomainException(
                "У пользователя #{$user->id} teacher_id={$user->teacher_id} не находит преподавателя."
            );
        }

        return $teacher;
    }

    /**
     * Публичный гард для вызывающих (страница/команда) — те же правила, что
     * применяет preview(), но без расчёта.
     */
    public function assertSamePerson(User $user): Teacher
    {
        return $this->teacherForUser($user);
    }

    /**
     * Границы расчёта. Если период не задан явно, но названы курс и блок —
     * берём даты блока (решение 9: условия фиксируются на блок/поток).
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function resolvePeriod(?int $courseId, ?int $blockNumber, $from, $to): array
    {
        if (($from !== null && $to !== null) || $courseId === null || $blockNumber === null) {
            return [$from, $to];
        }

        $block = Course::find($courseId)?->blocks()
            ->where('number', $blockNumber)
            ->first();

        return [
            $from ?? $block?->starts_at?->toDateString(),
            $to ?? $block?->ends_at?->toDateString(),
        ];
    }
}
