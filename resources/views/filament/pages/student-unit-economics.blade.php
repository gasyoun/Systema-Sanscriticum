<x-filament-panels::page>
    @php($e = $econ)
    @php($money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, '.', ' ').' ₽')
    {{-- Светофор: код цвета → классы плашки-значения --}}
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

    {{-- Выбор периода когорты --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Первая покупка с</label>
            <input type="date" wire:model.live="from"
                class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm" />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">по</label>
            <input type="date" wire:model.live="to"
                class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm" />
        </div>
    </div>

    @if (empty($e['found']))
        <div class="rounded-xl bg-gray-50 p-6 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            За выбранный период нет привлечённых учеников (ни одной первой покупки курса).
            Маркетинг за окно: <b>{{ $money($e['spend_total']) }}</b>
            (Директ {{ $money($e['spend_direct']) }} + посты {{ $money($e['spend_posts']) }}).
            Расширьте период.
        </div>
    @else
        {{-- Шапка когорты --}}
        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 text-sm">
            Когорта <b>{{ $e['cohort_size'] }}</b> учеников ({{ $e['cohort_hint'] }}) ·
            период {{ $e['from'] }} — {{ $e['to'] }} ·
            маркетинг <b>{{ $money($e['spend_total']) }}</b>
            (Директ {{ $money($e['spend_direct']) }} + посты {{ $money($e['spend_posts']) }}) ·
            выручка когорты <b>{{ $money($e['revenue_total']) }}</b>
        </div>

        {{-- KPI-карточки --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- CAC --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">CAC — стоимость привлечения</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $money($e['cac']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">маркетинг ÷ {{ $e['cohort_size'] }} привлечённых</div>
            </div>

            {{-- LTV --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">LTV — доход с ученика</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $money($e['ltv_mean']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">средний · медиана {{ $money($e['ltv_median']) }}</div>
            </div>

            {{-- LTV / CAC --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$e['ltv_cac_color']] }}"></span>
                    LTV / CAC
                </div>
                <div class="text-3xl font-bold {{ $light[$e['ltv_cac_color']] }}">
                    {{ $e['ltv_cac_ratio'] === null ? '—' : number_format($e['ltv_cac_ratio'], 2) }}×
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    &lt; {{ config('unit_economics.ltv_cac.red') }} — привлечение в убыток · ≥ {{ config('unit_economics.ltv_cac.green') }} — здорово
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- Месяцев до окупаемости CAC --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$e['payback_color']] }}"></span>
                    Месяцев до окупаемости CAC
                </div>
                <div class="text-3xl font-bold {{ $light[$e['payback_color']] }}">
                    {{ $e['months_to_payback'] === null ? '—' : number_format($e['months_to_payback'], 1) }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    CAC ÷ {{ $money($e['arpu_monthly']) }}/мес · удержание в среднем {{ number_format($e['avg_span_months'], 1) }} мес
                </div>
            </div>

            {{-- Удержание --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">Удержание</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($e['retention_rate'], 1) }}%</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">платили в последние {{ $e['churn_months'] }} мес</div>
            </div>

            {{-- Отток --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">Отток (churn)</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($e['churn_rate'], 1) }}%</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">нет оплат ≥ {{ $e['churn_months'] }} мес к концу периода</div>
            </div>
        </div>

        {{-- Кривая удержания по месяцам от первой покупки --}}
        <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">Кривая удержания (доля когорты с оплатой в месяц N от первой покупки)</div>
            <div class="space-y-1.5">
                @foreach ($e['retention_curve'] as $point)
                    <div class="flex items-center gap-3 text-xs">
                        <div class="w-14 shrink-0 text-gray-500 dark:text-gray-400">мес {{ $point['k'] }}</div>
                        <div class="flex-1 bg-gray-100 dark:bg-white/5 rounded h-4 overflow-hidden">
                            <div class="h-4 bg-primary-500" style="width: {{ $point['rate'] === null ? 0 : $point['rate'] }}%"></div>
                        </div>
                        <div class="w-28 shrink-0 text-right text-gray-600 dark:text-gray-300">
                            {{ $point['rate'] === null ? '—' : number_format($point['rate'], 1).'%' }}
                            <span class="text-gray-400">({{ $point['active'] }}/{{ $point['eligible'] }})</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Разрез по когортам месяца первой покупки --}}
        @if (! empty($e['cohort_months']))
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">Когорта (мес. первой покупки)</th>
                            <th class="px-4 py-2 text-right font-semibold">Учеников</th>
                            <th class="px-4 py-2 text-right font-semibold">+1 мес</th>
                            <th class="px-4 py-2 text-right font-semibold">+3 мес</th>
                            <th class="px-4 py-2 text-right font-semibold">+6 мес</th>
                            <th class="px-4 py-2 text-right font-semibold">+12 мес</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($e['cohort_months'] as $row)
                            <tr>
                                <td class="px-4 py-2">{{ $row['label'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['size'] }}</td>
                                @foreach ([1, 3, 6, 12] as $k)
                                    <td class="px-4 py-2 text-right">{{ $row['r'][$k] === null ? '—' : number_format($row['r'][$k], 0).'%' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Разрез по курсу привлечения --}}
        @if (! empty($e['by_course']))
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">Курс привлечения</th>
                            <th class="px-4 py-2 text-right font-semibold">Учеников</th>
                            <th class="px-4 py-2 text-right font-semibold">LTV сред.</th>
                            <th class="px-4 py-2 text-right font-semibold">LTV медиана</th>
                            <th class="px-4 py-2 text-right font-semibold">Выручка</th>
                            <th class="px-4 py-2 text-right font-semibold">LTV / CAC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($e['by_course'] as $row)
                            <tr>
                                <td class="px-4 py-2">{{ $row['title'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['students'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($row['ltv_mean']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($row['ltv_median']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $money($row['revenue_total']) }}</td>
                                <td class="px-4 py-2 text-right font-semibold {{ $light[$row['ltv_cac_color']] }}">
                                    <span class="inline-block h-2 w-2 rounded-full {{ $dot[$row['ltv_cac_color']] }} mr-1"></span>
                                    {{ $row['ltv_cac_ratio'] === null ? '—' : number_format($row['ltv_cac_ratio'], 2).'×' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                CAC на ученика по курсам — общий (реклама не разбита по продуктам), поэтому разное LTV/CAC показывает, какой продукт убыточен именно по привлечению.
            </div>
        @endif

        {{-- Допущения (чтобы оператор читал цифры без интерпретации) --}}
        <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
            <div><b>Привлечён</b> = ученик с первой доступо-открывающей покупкой курса (без брони/пробного) в периоде.</div>
            <div><b>LTV</b> = сумма всех реальных оплат ученика за жизнь (кроме техрасходов и выплат ЗП; возвраты вычитаются).</div>
            <div><b>Окупаемость</b> = CAC ÷ средний месячный доход с ученика; при свежей когорте LTV ещё не полон — число оптимистично снизу.</div>
        </div>
    @endif
</x-filament-panels::page>
