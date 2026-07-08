<x-filament-panels::page>
    @php($r = $this->results())
    @php($money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, '.', ' ').' ₽')
    @php($pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, '.', ' ').' %')
    @php($light = [
        'success' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'gray' => 'text-gray-900 dark:text-white',
    ])
    @php($chip = [
        'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-400/30',
        'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-400/30',
        'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-400/30',
        'gray' => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
    ])

    {{-- ── Живые ориентиры из P&L / юнит-экономики ── --}}
    @php($def = $r['defaults'])
    <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <div class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
            Живые ориентиры (из P&amp;L и юнит-экономики за 12 мес)
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div>
                <div class="text-gray-500 dark:text-gray-400 text-xs">Средний чек (LTV ученика)</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $money($def['avg_check']) }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400 text-xs">CAC (стоимость привлечения)</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $money($def['cac']) }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400 text-xs">Маржа EBITDA</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $pct($def['ebitda_margin_pct']) }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400 text-xs">Средняя прибыль/мес (начисл.)</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $money($def['avg_monthly_profit']) }}</div>
            </div>
        </div>
        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Это отправные ориентиры для ручного ввода, а не готовый сценарий: крупная трата — forward-looking, её выручку/расходы задаёт человек.
        </div>
    </div>

    {{-- ── Форма ввода сценариев ── --}}
    {{ $this->form }}

    {{-- ── Итог сравнения ── --}}
    @php($cmp = $r['compare'])
    <div class="rounded-xl p-4 ring-1 {{ $cmp['winner'] ? $chip['success'] : $chip['gray'] }}">
        <div class="flex items-center gap-2 font-semibold">
            <x-heroicon-o-scale class="h-5 w-5" />
            @if ($cmp['winner'])
                Предпочтительнее: «{{ $cmp['winner'] }}»
            @else
                Вердикт сравнения
            @endif
        </div>
        <div class="mt-1 text-sm">{{ $cmp['note'] }}</div>
    </div>

    {{-- ── Две колонки результатов ── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach (['a' => $r['a'], 'b' => $r['b']] as $key => $s)
            <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4 space-y-3 {{ $s['has_input'] ? '' : 'opacity-60' }}">
                <div class="flex items-center justify-between gap-2">
                    <div class="font-semibold text-gray-900 dark:text-white">{{ $s['label'] }}</div>
                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $chip[$s['level']] }}">
                        {{ ['success' => 'оправдан', 'warning' => 'осторожно', 'danger' => 'отказаться', 'gray' => '—'][$s['level']] }}
                    </span>
                </div>

                @unless ($s['has_input'])
                    <div class="text-sm text-gray-500 dark:text-gray-400">Сценарий не задан — введите капзатраты и потоки.</div>
                @else
                    {{-- Ключевые метрики --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <div class="text-xs text-gray-500 dark:text-gray-400">NPV (при {{ $pct($s['discount_rate_pct']) }})</div>
                            <div class="text-xl font-bold {{ $s['npv_positive'] ? $light['success'] : $light['danger'] }}">{{ $money($s['npv']) }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <div class="text-xs text-gray-500 dark:text-gray-400">IRR (внутр. доходность)</div>
                            <div class="text-xl font-bold {{ $s['irr_pct'] === null ? $light['gray'] : ($s['irr_pct'] >= $s['discount_rate_pct'] ? $light['success'] : $light['danger']) }}">
                                {{ $s['irr_pct'] === null ? '—' : $pct($s['irr_pct']) }}
                            </div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <div class="text-xs text-gray-500 dark:text-gray-400">Срок окупаемости</div>
                            <div class="text-xl font-bold {{ $light['gray'] }}">
                                {{ $s['payback_time'] === null ? 'не окупается' : number_format($s['payback_time'], 1, '.', ' ').' лет' }}
                            </div>
                            @if ($s['disc_payback_time'] !== null)
                                <div class="text-xs text-gray-500 dark:text-gray-400">дисконт.: {{ number_format($s['disc_payback_time'], 1, '.', ' ') }} лет</div>
                            @elseif ($s['payback_time'] !== null)
                                <div class="text-xs text-gray-500 dark:text-gray-400">дисконт.: не окупается за горизонт</div>
                            @endif
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <div class="text-xs text-gray-500 dark:text-gray-400">Точка безубыточности (поток/год)</div>
                            <div class="text-lg font-bold {{ $s['meets_breakeven'] ? $light['success'] : $light['danger'] }}">{{ $money($s['breakeven_annual_net']) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">ваш чистый поток: {{ $money($s['annual_net']) }}</div>
                        </div>
                    </div>

                    {{-- Вердикт --}}
                    <div class="text-sm {{ $light[$s['level']] }}">{{ $s['verdict'] }}</div>

                    {{-- Погодовой денежный поток --}}
                    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                                <tr>
                                    <th class="px-2 py-1.5 text-left font-semibold">Год</th>
                                    <th class="px-2 py-1.5 text-right font-semibold">Поток</th>
                                    <th class="px-2 py-1.5 text-right font-semibold">Накоплено</th>
                                    <th class="px-2 py-1.5 text-right font-semibold">Накопл. (диск.)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($s['rows'] as $row)
                                    <tr>
                                        <td class="px-2 py-1.5">{{ $row['year'] }}</td>
                                        <td class="px-2 py-1.5 text-right {{ $row['net'] < 0 ? $light['danger'] : $light['gray'] }}">{{ $money($row['net']) }}</td>
                                        <td class="px-2 py-1.5 text-right {{ $row['cum_net'] >= 0 ? $light['success'] : $light['danger'] }}">{{ $money($row['cum_net']) }}</td>
                                        <td class="px-2 py-1.5 text-right {{ $row['cum_disc'] >= 0 ? $light['success'] : $light['danger'] }}">{{ $money($row['cum_disc']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endunless
            </div>
        @endforeach
    </div>

    {{-- ── Допущения ── --}}
    <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
        <div><b>NPV</b> — чистая приведённая стоимость: сумма дисконтированных потоков за горизонт минус капзатраты. NPV &gt; 0 ⇔ IRR выше ставки дисконтирования.</div>
        <div><b>IRR</b> — ставка, при которой NPV = 0 (внутренняя доходность проекта). Ниже стоимости капитала — проект разрушает стоимость.</div>
        <div><b>Срок окупаемости</b> — год, когда накопленный поток впервые ≥ 0 (простой и дисконтированный). <b>Точка безубыточности</b> — минимальный годовой чистый поток, оправдывающий капзатраты при заданной ставке.</div>
        <div>Модель forward-looking: доп. выручка растёт на «рост, % в год» (ramp-up), расходы плоские. Ставка/горизонт/порог — config/investment.php (меняются через .env). Денег не двигает.</div>
        <div>Предзаполнено воркед-примером из кейса «Юный чемпион» (noboring-finance): «без покупки» → IRR ~1 %, окупаемость к 6-му году — сходимость с опубликованным кейсом.</div>
    </div>
</x-filament-panels::page>
