<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Support\Carbon;

/**
 * H3532 — канон «на руки» поверх сгенерированного config/teacher_rates.php.
 *
 * Формула (ARCHITECT контракт №3): (Σ поступлений ₽ × bank_slice%) × ставка(t)
 * − прямые вычеты ± перерасчёты; НПД −6 % — отдельная пометка шага выплаты,
 * НЕ внутри брутто. Слайс банка применяется только когда период несёт
 * bank_slice_pct (эра до окт-2025 шла без среза). Чистые функции над конфигом:
 * ни БД, ни сети — бэктест и прогноз кормят входами сами.
 */
final class PayrollRateCalculator
{
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('teacher_rates'));
    }

    /** Нормализованный получатель из любого раздела конфига или null. */
    public function find(string $slug): ?array
    {
        $recipients = (array) ($this->config['recipients'] ?? []);
        if (isset($recipients[$slug])) {
            $r = (array) $recipients[$slug];

            return $this->normalize($slug, $r, 'teacher');
        }

        foreach (['staff' => 'staff', 'contractors' => 'contractor'] as $section => $kind) {
            $rows = (array) ($this->config[$section] ?? []);
            if (isset($rows[$slug])) {
                return $this->normalize($slug, (array) $rows[$slug], $kind);
            }
        }

        return null;
    }

    /** Матчинг ФИО (включая алиасы «Поликарпова») по подстроке в обе стороны. */
    public function matchName(string $name): ?string
    {
        $haystack = $this->key($name);
        if ($haystack === '') {
            return null;
        }
        $all = $this->allSlugs();
        foreach ($all as $slug => $needleSet) {
            foreach ($needleSet as $needle) {
                if ($needle === '') {
                    continue;
                }
                if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
                    return (string) $slug;
                }
            }
        }

        return null;
    }

    /** Период ставки на дату: побеждает позднейший from; при равных from —
     * конкретный фикс поверх открытой процентной эры (Пахомов 01-05-2026:
     * «фикс 34 838» и «30 % с мая» объявлены одним сообщением). */
    public function periodFor(array $rcpt, Carbon $on): ?array
    {
        $best = null;
        $bestFrom = null;
        foreach ((array) ($rcpt['rate_periods'] ?? []) as $p) {
            $from = Carbon::parse((string) $p['from'])->startOfDay();
            $to = $p['to'] !== null ? Carbon::parse((string) $p['to'])->endOfDay() : null;
            if (! $on->between($from, $to ?? $on->copy()->addYears(50))) {
                continue;
            }
            $isFixed = ! isset($p['value_pct']);
            if (
                $best === null
                || $from->gt($bestFrom)
                || ($from->eq($bestFrom) && $isFixed && isset($best['value_pct']))
            ) {
                $best = $p;
                $bestFrom = $from;
            }
        }

        return $best;
    }

    /**
     * Расчёт «на руки» на дату.
     *
     * @param  array{
     *     receipts_rub?: list<float>,
     *     direct_receipts_rub?: list<float>,
     *     premium_rub?: list<float>,
     *     recalc_rub?: float,
     *     fx_rate?: float|null
     * } $inputs
     * @return array{
     *     slug: string, kind: string, payable_rub: float, deductions_rub: float,
     *     npd_pct: float|null, net_after_npd_rub: float|null,
     *     fx_rate: float|null, payable_eur: float|null,
     *     eur_range: array{min: float, max: float}|null,
     *     period: array<string, mixed>|null, notes: list<string>
     * }
     */
    public function netFor(string $slug, Carbon|string $on, array $inputs = []): array
    {
        $rcpt = $this->find($slug);
        if ($rcpt === null) {
            return $this->result($slug, 'unknown', 0.0, 0.0, null, null, null, null, ['⚠️ получатель не найден в config/teacher_rates.php'], null);
        }

        $date = $on instanceof Carbon ? $on->copy()->startOfDay() : Carbon::parse($on)->startOfDay();
        $notes = [];
        $period = $this->periodFor($rcpt, $date);

        $gross = 0.0;
        $kind = $rcpt['kind'];

        if ($kind === 'contractor') {
            return $this->contractorResult($rcpt, $date, $inputs);
        }

        if ($kind === 'staff') {
            $gross = (float) ($rcpt['monthly_rub'] ?? 0.0) + array_sum(array_map(floatval(...), (array) ($inputs['premium_rub'] ?? [])));
            $notes[] = 'штат: фиксированный ритм + премии отдельными строками';
        } elseif ($period === null) {
            $notes[] = '⚠️ пробел таймлайна ставок на дату — дефолт к проценту LMS (контракт автономии №3)';
            $kind = 'lms_fallback';

            return $this->result($slug, $kind, 0.0, 0.0, $this->npdPct($rcpt), $inputs, $notes, null, $period);
        } elseif (isset($period['value_pct'])) {
            $sliceMult = $period['bank_slice_pct'] !== null
                ? (float) $period['bank_slice_pct'] / 100.0
                : 1.0;
            $pct = (float) $period['value_pct'];
            $bankPart = array_sum(array_map(floatval(...), (array) ($inputs['receipts_rub'] ?? []))) * $sliceMult * $pct / 100.0;
            $directPart = array_sum(array_map(floatval(...), (array) ($inputs['direct_receipts_rub'] ?? []))) * $pct / 100.0;
            $gross = $bankPart + $directPart + (float) ($inputs['recalc_rub'] ?? 0.0);
            $notes[] = sprintf(
                '(%.2f × %s%%) × %s%%',
                array_sum(array_map(floatval(...), (array) ($inputs['receipts_rub'] ?? []))),
                $period['bank_slice_pct'] !== null ? rtrim(rtrim((string) $period['bank_slice_pct'], '0'), '.') : '—',
                rtrim(rtrim((string) $pct, '0'), '.')
            );
        } else {
            $gross = (float) $period['value_rub'] + (float) ($inputs['recalc_rub'] ?? 0.0);
            $notes[] = sprintf('фикс %.2f ₽ с %s', (float) $period['value_rub'], $period['from']);
        }

        $deductions = $this->activeDeductions($rcpt, $date);
        $deducted = array_sum(array_column($deductions, 'amount_rub'));
        foreach ($deductions as $d) {
            $notes[] = sprintf('− вычет %s %.2f ₽ (прямая оплата ученика)', $d['who'], (float) $d['amount_rub']);
        }
        $payable = round($gross - $deducted, 2);

        $npdPct = $this->npdPct($rcpt);
        $netAfterNpd = null;
        if ($npdPct !== null && $npdPct > 0) {
            $netAfterNpd = round($payable * (1 - $npdPct / 100.0), 2);
            $notes[] = sprintf('НПД −%s %% зачётом до выплаты (отдельный шаг, не внутри брутто)', rtrim(rtrim((string) $npdPct, '0'), '.'));
        }

        return $this->result($slug, $rcpt['kind'], $payable, $deducted, $npdPct, $inputs, $notes, $period, $netAfterNpd);
    }

    private function contractorResult(array $rcpt, Carbon $date, array $inputs): array
    {
        $range = ['min' => (float) $rcpt['fee_eur_min'], 'max' => (float) $rcpt['fee_eur_max']];
        $notes = [sprintf('подрядчик: €%s–%s/мес', $range['min'], $range['max'])];
        $fx = isset($inputs['fx_rate']) && $inputs['fx_rate'] ? (float) $inputs['fx_rate'] : null;

        return $this->result(
            $rcpt['slug'],
            'contractor',
            $fx !== null ? round(($range['min'] + $range['max']) / 2.0 * $fx, 2) : 0.0,
            0.0,
            null,
            $inputs,
            $notes,
            null,
            null,
            $range
        );
    }

    /** @param  array<string, mixed>|null  $period */
    private function result(
        string $slug,
        string $kind,
        float $payableRub,
        float $deductionsRub,
        ?float $npdPct,
        array $inputs,
        array $notes,
        ?array $period,
        ?float $netAfterNpd = null,
        ?array $eurRange = null,
    ): array {
        $fx = isset($inputs['fx_rate']) && $inputs['fx_rate'] ? (float) $inputs['fx_rate'] : null;

        return [
            'slug' => $slug,
            'kind' => $kind,
            'payable_rub' => $payableRub,
            'deductions_rub' => $deductionsRub,
            'npd_pct' => $npdPct,
            'net_after_npd_rub' => $netAfterNpd ?? ($npdPct !== null ? round($payableRub * (1 - $npdPct / 100.0), 2) : null),
            'fx_rate' => $fx,
            'payable_eur' => $fx !== null ? round($payableRub / $fx, 2) : null,
            'eur_range' => $eurRange,
            'period' => $period,
            'notes' => $notes,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activeDeductions(array $rcpt, Carbon $on): array
    {
        $rows = (array) ($rcpt['direct_deductions'] ?? []);
        $startsByWho = [];
        foreach ($rows as $d) {
            $startsByWho[(string) ($d['who'] ?? '')][] = Carbon::parse((string) $d['from'])->startOfDay();
        }

        $active = [];
        foreach ($rows as $d) {
            $from = Carbon::parse((string) $d['from'])->startOfDay();
            $to = isset($d['to']) && $d['to'] ? Carbon::parse((string) $d['to'])->endOfDay() : null;
            if ($to === null) {
                // Эволюция суммы («Новикова 1400→1680→1920»): период закрывается
                // стартом следующего вычета тому же получателю.
                foreach ($startsByWho[(string) ($d['who'] ?? '')] as $next) {
                    if ($next->gt($from) && $next->lte($on)) {
                        $to = $next->copy()->subDay()->endOfDay();
                        break;
                    }
                }
            }
            if ($on->gte($from) && ($to === null || $on->lte($to))) {
                $active[] = $d;
            }
        }

        return $active;
    }

    private function npdPct(array $rcpt): ?float
    {
        $npd = (array) ($this->config['npd'] ?? []);
        $row = $npd[$rcpt['slug']] ?? null;

        return is_array($row) && isset($row['pct']) ? (float) $row['pct'] : null;
    }

    /** @return array<string, list<string>> */
    private function allSlugs(): array
    {
        $out = [];
        foreach (['recipients', 'staff', 'contractors'] as $section) {
            foreach ((array) ($this->config[$section] ?? []) as $slug => $row) {
                $row = (array) $row;
                $needles = [(string) ($row['name'] ?? ''), ...(array) ($row['aliases'] ?? [])];
                $out[(string) $slug] = array_map($this->key(...), array_filter($needles));
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function normalize(string $slug, array $row, string $kind): array
    {
        return [
            'slug' => $slug,
            'kind' => $kind,
            'name' => (string) ($row['name'] ?? $slug),
            'aliases' => array_values((array) ($row['aliases'] ?? [])),
            'channel' => (string) ($row['channel'] ?? 'tochka_maria'),
            'lane' => strtoupper((string) ($row['lane'] ?? 'RUB')) === 'EUR' ? 'EUR' : 'RUB',
            'rate_periods' => array_values((array) ($row['rate_periods'] ?? [])),
            'direct_deductions' => array_values((array) ($row['direct_deductions'] ?? [])),
            'monthly_rub' => isset($row['monthly_rub']) ? (float) $row['monthly_rub'] : null,
            'tranches' => array_values((array) ($row['tranches'] ?? [])),
            'pay_day' => isset($row['pay_day']) ? (int) $row['pay_day'] : null,
            'fee_eur_min' => isset($row['fee_eur_min']) ? (float) $row['fee_eur_min'] : null,
            'fee_eur_max' => isset($row['fee_eur_max']) ? (float) $row['fee_eur_max'] : null,
        ];
    }

    private function key(string $s): string
    {
        return mb_strtolower(trim(str_replace(['ё'], ['е'], $s)));
    }
}
