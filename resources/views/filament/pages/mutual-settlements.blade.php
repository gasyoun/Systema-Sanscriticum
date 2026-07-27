<x-filament-panels::page>
    @php($money = fn ($v) => number_format((float) $v, 2, '.', ' ') . ' ₽')
    @php($p = $this->preview())
    @php($err = $this->previewError())

    {{ $this->form }}

    @if ($err)
        <div class="rounded-xl bg-danger-50 p-4 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:ring-danger-400/30">
            <div class="text-sm font-semibold text-danger-700 dark:text-danger-400">Взаимозачёт невозможен</div>
            <div class="mt-1 text-sm text-danger-700 dark:text-danger-400">{{ $err }}</div>
        </div>
    @endif

    @if ($p)
        {{-- ── Две цифры крупно ── --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Оплачено как ученик</div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $money($p['tuition_amount']) }}</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">Начислено как преподавателю</div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $money($p['salary_amount']) }}</div>
            </div>
            <div class="rounded-xl bg-primary-50 p-4 ring-1 ring-primary-600/20 dark:bg-primary-500/10 dark:ring-primary-400/30">
                <div class="text-xs text-primary-700 dark:text-primary-400">К зачёту (минимум из двух)</div>
                <div class="text-2xl font-bold text-primary-700 dark:text-primary-400">{{ $money($p['offset_amount']) }}</div>
                <div class="mt-1 text-xs text-primary-700 dark:text-primary-400">
                    Остаток {{ $money($p['net_amount']) }} — {{ \App\Models\MutualSettlement::directionLabels()[$p['net_direction']] ?? $p['net_direction'] }}
                </div>
            </div>
        </div>

        <div class="text-xs text-gray-500 dark:text-gray-400">
            Период: {{ $p['period_from'] ?? '—' }} — {{ $p['period_to'] ?? '—' }}.
            Выручка школы не трогается: платежи остаются доходом в полном объёме, зачёт живёт только на выплатной стороне.
        </div>

        {{-- ── Расшифровка: оплаты ── --}}
        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">Из чего сложились оплаты</div>
            @if (empty($p['tuition']['lines']))
                <div class="text-sm text-gray-500 dark:text-gray-400">Платежей за период нет.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-1 text-left">Дата</th>
                                <th class="py-1 text-left">Курс</th>
                                <th class="py-1 text-left">Тариф</th>
                                <th class="py-1 text-right">Сумма</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-900 dark:text-white">
                            @foreach ($p['tuition']['lines'] as $line)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1">{{ $line['date'] ?? '—' }}</td>
                                    <td class="py-1">{{ $line['course_title'] }}</td>
                                    <td class="py-1">{{ $line['tariff'] ?? '—' }}</td>
                                    <td class="py-1 text-right">{{ $money($line['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- ── Расшифровка: начисление ── --}}
        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">Из чего сложилось начисление</div>
            <div class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                Accrual по блокам, из того же источника, что печатает страница «Зарплаты» — второго расчёта ЗП здесь нет.
            </div>
            @if (empty($p['salary']['lines']))
                <div class="text-sm text-gray-500 dark:text-gray-400">Начислений за период нет.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-1 text-left">Курс</th>
                                <th class="py-1 text-left">Схема</th>
                                <th class="py-1 text-right">Студентов</th>
                                <th class="py-1 text-right">Выручка</th>
                                <th class="py-1 text-right">Начислено</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-900 dark:text-white">
                            @foreach ($p['salary']['lines'] as $line)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1">{{ $line['course_title'] }}</td>
                                    <td class="py-1">{{ $line['salary_type'] ?? '—' }}</td>
                                    <td class="py-1 text-right">{{ $line['students'] }}</td>
                                    <td class="py-1 text-right">{{ $money($line['revenue']) }}</td>
                                    <td class="py-1 text-right">{{ $money($line['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- ── История актов ── --}}
    @php($history = $this->history())
    @if ($history->isNotEmpty())
        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">Прежние акты</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-1 text-left">Период</th>
                            <th class="py-1 text-left">Курс / блок</th>
                            <th class="py-1 text-right">Оплачено</th>
                            <th class="py-1 text-right">Начислено</th>
                            <th class="py-1 text-right">Зачёт</th>
                            <th class="py-1 text-left">Статус</th>
                            <th class="py-1 text-left">Израсходован</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-900 dark:text-white">
                        @foreach ($history as $act)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1">
                                    {{ $act->period_from?->format('d.m.Y') ?? '—' }} — {{ $act->period_to?->format('d.m.Y') ?? '—' }}
                                </td>
                                <td class="py-1">
                                    {{ $act->course?->title ?? '—' }}{{ $act->block_number ? ' · блок ' . $act->block_number : '' }}
                                </td>
                                <td class="py-1 text-right">{{ $money($act->tuition_amount) }}</td>
                                <td class="py-1 text-right">{{ $money($act->salary_amount) }}</td>
                                <td class="py-1 text-right">{{ $money($act->offset_amount) }}</td>
                                <td class="py-1">{{ $act->statusLabel() }}</td>
                                <td class="py-1">
                                    {{ $act->payout_id ? 'выплата #' . $act->payout_id : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
