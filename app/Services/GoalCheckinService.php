<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Goal;
use App\Models\GoalCheckin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Петля check-in'ов цели (H376) — младший брат DelegationKpiService/
 * ProfitFundsService (H259), но для целей делегированных лидов, а не
 * финансовых фаз. В отличие от них ХРАНИТ данные (goals/goal_checkins),
 * потому что цели — вводимая сущность, а не производная от других сервисов.
 *
 * Светофор: сравниваем факт с ожидаемым темпом (линейная интерполяция между
 * period_start/period_end к target_value) — цель, отстающая от темпа, красная
 * задолго до дедлайна, а не только в момент провала.
 */
class GoalCheckinService
{
    /**
     * Снимок прогресса по всем активным целям на дату $asOf (по умолчанию —
     * сейчас). Не пишет ничего — для записи см. recordCheckin().
     *
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        $goals = Goal::query()
            ->where('status', Goal::STATUS_ACTIVE)
            ->with('owner')
            ->get()
            ->map(fn (Goal $goal) => $this->goalCard($goal, $asOf))
            ->values();

        return [
            'as_of' => $asOf->toDateTimeString(),
            'goals' => $goals,
            'ok' => $goals->every(fn (array $g) => $g['level'] !== 'danger'),
        ];
    }

    /**
     * Записывает check-in для одной цели: снимок текущего прогресса как
     * GoalCheckin-строка. $currentValue — свежее фактическое значение метрики
     * (вводится вручную оператором; у целей нет единого источника метрики).
     */
    public function recordCheckin(Goal $goal, float $currentValue, ?string $note = null, ?Carbon $asOf = null): GoalCheckin
    {
        $asOf = ($asOf ?? now())->copy();
        $level = $this->level($goal, $currentValue, $asOf);

        return $goal->checkins()->create([
            'value_at_checkin' => $currentValue,
            'level' => $level,
            'note' => $note,
            'checked_in_at' => $asOf,
        ]);
    }

    /**
     * Последнее записанное значение метрики цели (из истории check-in'ов),
     * либо starting_value, если check-in'ов ещё не было.
     */
    public function latestValue(Goal $goal): float
    {
        $last = $goal->checkins()->latest('checked_in_at')->first();

        return $last !== null ? (float) $last->value_at_checkin : (float) $goal->starting_value;
    }

    /**
     * @return array{goal:Goal, current_value:float, expected_value:float, progress_pct:float, level:string}
     */
    private function goalCard(Goal $goal, Carbon $asOf): array
    {
        $current = $this->latestValue($goal);
        $expected = $this->expectedValue($goal, $asOf);
        $target = (float) $goal->target_value;
        $start = (float) $goal->starting_value;

        $span = $target - $start;
        $progressPct = $span !== 0.0 ? round((($current - $start) / $span) * 100, 1) : 100.0;

        return [
            'goal' => $goal,
            'current_value' => $current,
            'expected_value' => $expected,
            'progress_pct' => $progressPct,
            'level' => $this->level($goal, $current, $asOf),
        ];
    }

    /**
     * Ожидаемое значение метрики на $asOf при равномерном темпе от
     * starting_value (period_start) до target_value (period_end).
     */
    private function expectedValue(Goal $goal, Carbon $asOf): float
    {
        $start = Carbon::parse($goal->period_start);
        $end = Carbon::parse($goal->period_end);
        $totalDays = max(1, $start->diffInDays($end));
        $elapsedDays = min($totalDays, max(0, $start->diffInDays($asOf, false)));

        $fraction = $elapsedDays / $totalDays;

        return (float) $goal->starting_value + ((float) $goal->target_value - (float) $goal->starting_value) * $fraction;
    }

    /**
     * Светофор: сравнение факта с ожидаемым темпом. 15%-й допуск (warning)
     * прежде, чем цель считается «красной» — короткое отставание не паника.
     */
    private function level(Goal $goal, float $currentValue, Carbon $asOf): string
    {
        $expected = $this->expectedValue($goal, $asOf);
        $span = (float) $goal->target_value - (float) $goal->starting_value;

        if ($span === 0.0) {
            return 'success';
        }

        // Доля отставания/опережения относительно полного размаха цели.
        $deltaPct = ($currentValue - $expected) / abs($span);

        if ($deltaPct >= 0) {
            return 'success';
        }

        return $deltaPct >= -0.15 ? 'warning' : 'danger';
    }

    /**
     * Записывает check-in для всех активных целей их последним известным
     * значением (без нового ввода) — используется еженедельной командой,
     * чтобы петля даже без ручного ввода фиксировала «темп относительно
     * дедлайна ухудшился/улучшился» день ото дня.
     *
     * @return Collection<int, GoalCheckin>
     */
    public function recordCheckinsForAllActive(?Carbon $asOf = null): Collection
    {
        $asOf = ($asOf ?? now())->copy();

        return Goal::query()
            ->where('status', Goal::STATUS_ACTIVE)
            ->get()
            ->map(fn (Goal $goal) => $this->recordCheckin($goal, $this->latestValue($goal), 'Автоматический еженедельный check-in.', $asOf));
    }
}
