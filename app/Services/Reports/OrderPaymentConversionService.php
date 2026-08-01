<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Filament\Pages\ReceivablesGovernance;
use App\Models\Lead;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Конверсия воронки «оформленный заказ → оплата» + список недожатых заказов
 * (H262, фаза F плана noboring /cases/education).
 *
 * Кейс «Нашли и обезвредили слабое место в продажах»
 * (noboring-finance.ru/cases/dozhimali-klientov): заказы шли, но до денег
 * доходило лишь 47 % — половина «оставляла заявку и пропадала». Вскрыл это
 * операционный отчёт по воронке; дожим/рассрочка подняли конверсию
 * 47 % → 63 % → 67 % по кварталам. Здесь этот отчёт и живёт.
 *
 * «Оформленный заказ» = реальный (не conditional) платёж-заказ, кроме
 * техрасходов/выплат ЗП и микро-покупок (бронь/пробное) — намерение купить курс.
 * Считаем КОГОРТНО по дате оформления: из заказов, оформленных в периоде, какая
 * доля дошла до статуса paid/success. «Оплата» — тот же ряд заказа, доведённый
 * до оплаты (checkout создаёт pending, вебхук Точки переводит в paid).
 *
 * Недожатый заказ = заказ в статусе pending дольше N дней (config). Это РАННИЙ
 * сигнал — заказ ещё НЕ стал дебиторкой (дебиторка H257 = непогашенные
 * PaymentPromise). Разные источники, логику дебиторки не дублируем.
 *
 * Ничего не хранит — считает из живой БД. Пороги/окна — config/conversion.php.
 */
class OrderPaymentConversionService
{
    /**
     * Dual-denominator baseline for NOBORING dozhim Wave 0 (rate A + rate B).
     *
     * Rate A (primary): paid course-intent Payments / all course-intent Payments
     * in the cohort window (same «оформленный заказ» filter as snapshot()).
     * Rate B (secondary): Leads created in the window with converted_at set
     * (product mark when a linked Payment reaches paid) / all Leads in window.
     *
     * @param  list<int>  $windowsDays  e.g. [30, 90]
     * @return array{
     *   as_of: string,
     *   windows: list<array{
     *     days: int,
     *     from: string,
     *     to: string,
     *     rate_a: array{orders:int, paid:int, pending:int, lost:int, conversion_pct:float|null},
     *     rate_b: array{leads:int, converted:int, conversion_pct:float|null}
     *   }>,
     *   zayavka_definition: string
     * }
     */
    public function dozhimBaseline(?Carbon $asOf = null, array $windowsDays = [30, 90]): array
    {
        $asOf = ($asOf ?? now())->copy();
        $windows = [];

        foreach ($windowsDays as $days) {
            $days = max(1, (int) $days);
            $from = $asOf->copy()->subDays($days);
            $windows[] = [
                'days' => $days,
                'from' => $from->toDateTimeString(),
                'to' => $asOf->toDateTimeString(),
                'rate_a' => $this->conversionForRange($from, $asOf),
                'rate_b' => $this->leadConversionForRange($from, $asOf),
            ];
        }

        return [
            'as_of' => $asOf->toDateTimeString(),
            'windows' => $windows,
            'zayavka_definition' => 'Rate A «заявка» = real course Payment (not conditional; not excluded tariffs). '
                .'Rate B «заявка» = Lead row created in window; converted = converted_at set on paid path.',
        ];
    }

    /**
     * Lead → first paid conversion for rate B.
     *
     * @return array{leads:int, converted:int, conversion_pct:float|null}
     */
    public function leadConversionForRange(Carbon $from, Carbon $to): array
    {
        $base = Lead::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $leads = (clone $base)->count();
        $converted = (clone $base)->whereNotNull('converted_at')->count();

        return [
            'leads' => $leads,
            'converted' => $converted,
            'conversion_pct' => $leads > 0 ? round($converted / $leads * 100, 1) : null,
        ];
    }

    /**
     * Полный снимок воронки заказ→оплата на момент $asOf (по умолчанию — сейчас).
     *
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        $targetPct = (float) config('conversion.target_pct', 63);
        $warnPct = (float) config('conversion.warn_pct', 50);
        $unclosedAfterDays = (int) config('conversion.unclosed_after_days', 3);
        $breakdownWindow = (int) config('conversion.breakdown_window_days', 90);
        $headlineWindow = (int) config('conversion.headline_window_days', 30);

        // ── Главная цифра: конверсия за окно + динамика к прошлому такому окну ──
        $curFrom = $asOf->copy()->subDays($headlineWindow);
        $current = $this->conversionForRange($curFrom, $asOf);
        $prevFrom = $curFrom->copy()->subDays($headlineWindow);
        $previous = $this->conversionForRange($prevFrom, $curFrom);

        $deltaPp = $current['conversion_pct'] === null || $previous['conversion_pct'] === null
            ? null
            : round($current['conversion_pct'] - $previous['conversion_pct'], 1);

        // ── Тренд период-к-периоду (недели + кварталы) ──
        $weeks = $this->trend($asOf, (int) config('conversion.trend_weeks', 8), 'week');
        $quarters = $this->trend($asOf, (int) config('conversion.trend_quarters', 4), 'quarter');

        // ── Разрезы: где течёт (по курсу / по каналу) за окно разрезов ──
        $bFrom = $asOf->copy()->subDays($breakdownWindow);
        $byCourse = $this->breakdownByCourse($bFrom, $asOf, $targetPct, $warnPct);
        $byChannel = $this->breakdownByChannel($bFrom, $asOf, $targetPct, $warnPct);

        // ── Недожатые заказы (рабочий список «кого дожать») ──
        $unclosed = $this->unclosedOrders($asOf, $unclosedAfterDays);

        return [
            'as_of' => $asOf->toDateTimeString(),
            'target_pct' => $targetPct,
            'warn_pct' => $warnPct,
            'headline_window_days' => $headlineWindow,
            'breakdown_window_days' => $breakdownWindow,
            'unclosed_after_days' => $unclosedAfterDays,

            // Главная цифра + светофор + динамика.
            'conversion_pct' => $current['conversion_pct'],
            'level' => $this->level($current['conversion_pct'], $targetPct, $warnPct),
            'orders' => $current['orders'],
            'paid' => $current['paid'],
            'pending_open' => $current['pending'],
            'lost' => $current['lost'],
            'prev_conversion_pct' => $previous['conversion_pct'],
            'delta_pp' => $deltaPp,

            // Тренды.
            'weeks' => $weeks,
            'quarters' => $quarters,

            // Разрезы.
            'by_course' => $byCourse,
            'by_channel' => $byChannel,

            // Недожатые.
            'unclosed' => $unclosed,

            // Связь с дебиторкой (H257): недожатый заказ — сигнал ДО дебиторки.
            'receivables_url' => $this->receivablesUrl(),
        ];
    }

    /** Уровень светофора по конверсии: зелёный ≥ target, жёлтый ≥ warn, иначе красный. */
    public function level(?float $conversionPct, float $targetPct, float $warnPct): string
    {
        if ($conversionPct === null) {
            return 'gray';
        }

        return match (true) {
            $conversionPct >= $targetPct => 'success',
            $conversionPct >= $warnPct => 'warning',
            default => 'danger',
        };
    }

    /**
     * Число недожатых заказов — для бейджа в меню (оператор видит утечку, не
     * заходя на страницу). Быстрый count, без полного снимка.
     */
    public function unclosedCount(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy();
        $cutoff = $asOf->copy()->subDays((int) config('conversion.unclosed_after_days', 3))->endOfDay();

        return $this->orders()
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->count();
    }

    /**
     * Когорта заказов, оформленных в [$from, $to): total / оплачено / ещё pending
     * / потеряно (canceled|failed) + процент конверсии. Правая граница
     * ПОЛУОТКРЫТА, чтобы соседние периоды тренда не считали один заказ дважды.
     *
     * @return array{orders:int, paid:int, pending:int, lost:int, conversion_pct:float|null}
     */
    private function conversionForRange(Carbon $from, Carbon $to): array
    {
        $row = $this->orders()
            ->where('payments.created_at', '>=', $from)
            ->where('payments.created_at', '<', $to)
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw($this->countIf("status IN ('paid','success')").' as paid')
            ->selectRaw($this->countIf("status = 'pending'").' as pending')
            ->selectRaw($this->countIf("status IN ('canceled','cancelled','failed')").' as lost')
            ->first();

        $orders = (int) ($row->orders ?? 0);
        $paid = (int) ($row->paid ?? 0);

        return [
            'orders' => $orders,
            'paid' => $paid,
            'pending' => (int) ($row->pending ?? 0),
            'lost' => (int) ($row->lost ?? 0),
            'conversion_pct' => $orders > 0 ? round($paid / $orders * 100, 1) : null,
        ];
    }

    /**
     * Тренд по $count последних периодов гранулярности $unit ('week'|'quarter'),
     * старые → новые. Последний период — текущий (незавершённый): его конверсия
     * занижена лагом (заказы конца периода ещё легитимно pending) — помечаем.
     *
     * @return list<array{label:string, from:string, orders:int, paid:int, pending:int, lost:int, conversion_pct:float|null, current:bool}>
     */
    private function trend(Carbon $asOf, int $count, string $unit): array
    {
        $rows = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            if ($unit === 'week') {
                $start = $asOf->copy()->startOfWeek()->subWeeks($i);
                $end = $start->copy()->addWeek();
                $label = $start->format('d.m');
            } else {
                $start = $asOf->copy()->startOfQuarter()->subQuarters($i);
                $end = $start->copy()->addQuarter();
                $label = 'Q'.$start->quarter.' '.$start->year;
            }

            $isCurrent = $i === 0;
            // Текущий период считаем по $asOf, а не по будущему концу периода.
            $to = $isCurrent ? $asOf->copy() : $end;
            $c = $this->conversionForRange($start, $to);

            $rows[] = [
                'label' => $label,
                'from' => $start->toDateString(),
                'orders' => $c['orders'],
                'paid' => $c['paid'],
                'pending' => $c['pending'],
                'lost' => $c['lost'],
                'conversion_pct' => $c['conversion_pct'],
                'current' => $isCurrent,
            ];
        }

        return $rows;
    }

    /**
     * Конверсия по курсу за окно — видно, ГДЕ течёт воронка.
     *
     * @return list<array{course:string, orders:int, paid:int, conversion_pct:float|null, level:string}>
     */
    private function breakdownByCourse(Carbon $from, Carbon $to, float $targetPct, float $warnPct): array
    {
        $rows = $this->orders()
            ->leftJoin('courses', 'courses.id', '=', 'payments.course_id')
            ->where('payments.created_at', '>=', $from)
            ->where('payments.created_at', '<', $to)
            ->selectRaw("COALESCE(courses.title, 'Без курса') as course")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw($this->countIf("payments.status IN ('paid','success')").' as paid')
            ->groupBy('course')
            ->get();

        return $this->decorateBreakdown($rows, 'course', $targetPct, $warnPct);
    }

    /**
     * Конверсия по каналу привлечения (utm_source покупателя) за окно. Канал
     * берём с users.utm_source — тот же источник, что у ChannelConversionReport;
     * 'direct' = заказ без метки. Дубля с тем отчётом нет: там канал→оплата на
     * УРОВНЕ пользователя за месяц, здесь — заказ→оплата когортно.
     *
     * @return list<array{channel:string, orders:int, paid:int, conversion_pct:float|null, level:string}>
     */
    private function breakdownByChannel(Carbon $from, Carbon $to, float $targetPct, float $warnPct): array
    {
        $rows = $this->orders()
            ->leftJoin('users', 'users.id', '=', 'payments.user_id')
            ->where('payments.created_at', '>=', $from)
            ->where('payments.created_at', '<', $to)
            ->selectRaw("COALESCE(users.utm_source, 'direct') as channel")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw($this->countIf("payments.status IN ('paid','success')").' as paid')
            ->groupBy('channel')
            ->get();

        return $this->decorateBreakdown($rows, 'channel', $targetPct, $warnPct);
    }

    /**
     * Считает conversion_pct + светофор на каждую строку разреза и сортирует по
     * объёму заказов (крупные каналы/курсы сверху).
     *
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function decorateBreakdown($rows, string $keyField, float $targetPct, float $warnPct): array
    {
        $out = $rows->map(function (object $r) use ($keyField, $targetPct, $warnPct): array {
            $orders = (int) $r->orders;
            $paid = (int) $r->paid;
            $pct = $orders > 0 ? round($paid / $orders * 100, 1) : null;

            return [
                $keyField => (string) $r->{$keyField},
                'orders' => $orders,
                'paid' => $paid,
                'conversion_pct' => $pct,
                'level' => $this->level($pct, $targetPct, $warnPct),
            ];
        })->sortByDesc('orders')->values()->all();

        return $out;
    }

    /**
     * Недожатые заказы: pending дольше N дней, старые → новые (самые «протухшие»
     * сверху). Рабочий список отдела продаж/оператора. Сумма и возраст на строку.
     *
     * @return array{count:int, sum:float, after_days:int, rows: list<array{id:int, user:string, course:string, amount:float, age_days:int, created_at:string}>}
     */
    private function unclosedOrders(Carbon $asOf, int $afterDays): array
    {
        $cutoff = $asOf->copy()->subDays($afterDays)->endOfDay();

        $orders = $this->orders()
            ->with(['user:id,name,email', 'course:id,title'])
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at') // старые сверху = дольше всех недожаты
            ->get(['id', 'user_id', 'course_id', 'amount', 'created_at', 'tariff', 'is_conditional', 'status']);

        $rows = $orders->map(fn (Payment $p): array => [
            'id' => (int) $p->id,
            'user' => $p->user?->name ?: ($p->user?->email ?: '—'),
            'course' => $p->course?->title ?: 'Без курса',
            'amount' => (float) $p->amount,
            'age_days' => (int) $p->created_at->diffInDays($asOf),
            'created_at' => $p->created_at->toDateString(),
        ])->all();

        return [
            'count' => count($rows),
            'sum' => Money::round((float) $orders->sum('amount')),
            'after_days' => $afterDays,
            'rows' => $rows,
        ];
    }

    /**
     * Базовый запрос «оформленный заказ»: реальный (не conditional) платёж, кроме
     * исключённых тарифов (техрасходы/ЗП + бронь/пробное по config). Единый
     * знаменатель для конверсии, разрезов и недожатых.
     */
    private function orders(): Builder
    {
        $excluded = (array) config('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);

        return Payment::query()
            ->where('payments.is_conditional', false)
            ->when($excluded !== [], fn (Builder $q) => $q->whereNotIn('payments.tariff', $excluded));
    }

    /** SQL-выражение SUM(CASE WHEN <cond> THEN 1 ELSE 0 END) — переносимо (SQLite/MySQL). */
    private function countIf(string $condition): string
    {
        return 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END)';
    }

    /** Ссылка на витрину дебиторки (H257), если страница доступна. */
    private function receivablesUrl(): ?string
    {
        try {
            return ReceivablesGovernance::getUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}
