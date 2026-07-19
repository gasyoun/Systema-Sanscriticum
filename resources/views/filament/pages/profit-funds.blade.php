<x-filament-panels::page>
    @php($s = $snap)
    @php($money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, '.', ' ').' ₽')
    @php($light = [
        'success' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'gray' => 'text-gray-900 dark:text-white',
    ])
    @php($dot = [
        'success' => 'bg-success-500',
        'warning' => 'bg-warning-500',
        'danger' => 'bg-danger-500',
        'gray' => 'bg-gray-300',
    ])

    {{-- ── Резерв: сверка с кассой (главный сигнал фазы D) ── --}}
    @if ($s['reserve_level'] === 'danger')
        <div class="rounded-xl bg-danger-50 p-4 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:ring-danger-400/30">
            <div class="flex items-center gap-2 text-danger-700 dark:text-danger-400 font-semibold">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
                Резервный фонд не обеспечен кассой
            </div>
            <div class="mt-2 text-sm text-danger-700 dark:text-danger-300">{{ $s['reserve_note'] }}</div>
        </div>
    @elseif ($s['reserve_level'] === 'warning')
        <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:ring-warning-400/30 text-sm text-warning-700 dark:text-warning-300">
            <span class="font-semibold">Резерв обеспечен частично.</span> {{ $s['reserve_note'] }}
        </div>
    @else
        <div class="rounded-xl bg-success-50 p-4 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:ring-success-400/30 text-sm text-success-700 dark:text-success-300">
            <span class="font-semibold">Фонды под контролем.</span> {{ $s['reserve_note'] }}
        </div>
    @endif

    {{-- ── Распределение прибыли текущего месяца по фондам ── --}}
    <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
        <div class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-1">
            Распределение прибыли месяца ({{ $s['period'] }}) по фондам
        </div>
        <div class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            Чистая прибыль по начислению:
            <b class="{{ $s['current_profit_positive'] ? $light['success'] : $light['danger'] }}">{{ $money($s['current_profit']) }}</b>.
            @unless ($s['current_profit_positive'])
                Месяц не прибыльный — фонды не пополняются.
            @endunless
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            @foreach ($s['funds'] as $key => $fund)
                <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                        @if ($key === $s['reserve_key'])
                            <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$s['reserve_level']] }}"></span>
                        @endif
                        {{ $fund['label'] }}
                    </div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ $money($s['current_allocations'][$key] ?? 0) }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ number_format($fund['share_pct'], $fund['share_pct'] == (int) $fund['share_pct'] ? 0 : 1) }}% прибыли
                    </div>
                </div>
            @endforeach
        </div>
        @if ($s['share_note'])
            <div class="mt-2 text-xs text-warning-600 dark:text-warning-400">⚠ {{ $s['share_note'] }}</div>
        @endif
    </div>

    {{-- ── Накопленные фонды за окно + сверка резерва с кассой ── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 md:col-span-2">
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                Накоплено за {{ $s['window_months'] }} мес (прибыльные месяцы)
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                @foreach ($s['funds'] as $key => $fund)
                    <div>
                        <div class="text-gray-500 dark:text-gray-400 text-xs">{{ $fund['label'] }}</div>
                        <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $money($s['accumulated'][$key] ?? 0) }}</div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Всего распределено прибыли: <b>{{ $money($s['accumulated_profit']) }}</b>
            </div>
        </div>

        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$s['reserve_level']] }}"></span>
                Резерв обеспечен кассой
            </div>
            <div class="text-3xl font-bold {{ $light[$s['reserve_level']] }}">
                {{ $s['cash_covers_reserve_pct'] === null ? '—' : number_format($s['cash_covers_reserve_pct'], 0).'%' }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                резерв <b>{{ $money($s['reserve_accrued']) }}</b> ·
                накопленная касса (ДДС) <b>{{ $money($s['accumulated_cash']) }}</b>
            </div>
        </div>
    </div>

    {{-- ── Помесячная история: прибыль → резерв → касса ── --}}
    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">Месяц</th>
                    <th class="px-4 py-2 text-right font-semibold">Прибыль (начисление)</th>
                    <th class="px-4 py-2 text-right font-semibold">Отчисление в резерв</th>
                    <th class="px-4 py-2 text-right font-semibold">Чистый поток ДДС</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($s['months'] as $m)
                    <tr>
                        <td class="px-4 py-2">{{ $m['period'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold {{ $m['profit'] > 0 ? $light['success'] : ($m['profit'] < 0 ? $light['danger'] : $light['gray']) }}">
                            {{ $money($m['profit']) }}
                        </td>
                        <td class="px-4 py-2 text-right">{{ $money($m['reserve']) }}</td>
                        <td class="px-4 py-2 text-right {{ $m['cash_net'] < 0 ? $light['danger'] : $light['gray'] }}">
                            {{ $money($m['cash_net']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── Допущения ── --}}
    <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
        <div><b>Прибыль для фондов</b> = чистая прибыль по начислению (EBITDA ОПиУ-accrual, фаза B): признанная выручка − расходы периода. Только положительная прибыль распределяется.</div>
        <div><b>Накопленный фонд</b> — управленческий earmark (сумма месячных отчислений за окно), не отдельный банковский счет: у LMS нет банковского леджера.</div>
        <div><b>Обеспеченность резерва</b> = накопленный чистый денежный поток ДДС ÷ накопленный резерв. Ниже 100% — резерв не полностью подкреплен реальными деньгами.</div>
        <div>Доли фондов — config/profit_funds.php (меняются через .env без деплоя). Снимок на {{ $s['as_of'] }}.</div>
    </div>
</x-filament-panels::page>
