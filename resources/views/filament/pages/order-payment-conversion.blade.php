<x-filament-panels::page>
    @php($s = $snap)
    @php($money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, '.', ' ').' ₽')
    @php($pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).'%')
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

    {{-- ── Главная цифра: конверсия заказ→оплата за окно + светофор + динамика ── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$s['level']] }}"></span>
                Конверсия заказ→оплата · {{ $s['headline_window_days'] }} дн.
            </div>
            <div class="text-3xl font-bold {{ $light[$s['level']] }}">{{ $pct($s['conversion_pct']) }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                цель <b>{{ number_format($s['target_pct'], 0) }}%</b> ·
                порог <b>{{ number_format($s['warn_pct'], 0) }}%</b>
                @if ($s['delta_pp'] !== null)
                    · нед. окно назад {{ $pct($s['prev_conversion_pct']) }}
                    <span class="{{ $s['delta_pp'] >= 0 ? $light['success'] : $light['danger'] }}">
                        ({{ $s['delta_pp'] >= 0 ? '+' : '' }}{{ number_format($s['delta_pp'], 1) }} п.п.)
                    </span>
                @endif
            </div>
        </div>

        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Заказов за окно</div>
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $s['orders'] }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                оплачено <b class="text-success-600 dark:text-success-400">{{ $s['paid'] }}</b> ·
                в работе <b class="text-warning-600 dark:text-warning-400">{{ $s['pending_open'] }}</b> ·
                потеряно <b class="text-danger-600 dark:text-danger-400">{{ $s['lost'] }}</b>
            </div>
        </div>

        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Недожатых заказов (&gt; {{ $s['unclosed_after_days'] }} дн.)</div>
            <div class="text-3xl font-bold {{ $s['unclosed']['count'] > 0 ? $light['warning'] : $light['success'] }}">
                {{ $s['unclosed']['count'] }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                на сумму <b>{{ $money($s['unclosed']['sum']) }}</b> — рабочий список ниже
            </div>
        </div>
    </div>

    {{-- ── Тренд период-к-периоду: недели и кварталы ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([['По неделям', $s['weeks']], ['По кварталам', $s['quarters']]] as [$heading, $series])
            <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <div class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">{{ $heading }}</div>
                <div class="flex items-end gap-2 h-32">
                    @foreach ($series as $p)
                        @php($h = $p['conversion_pct'] === null ? 0 : max(4, (int) round($p['conversion_pct'])))
                        @php($lvl = $p['conversion_pct'] === null ? 'gray' : ($p['conversion_pct'] >= $s['target_pct'] ? 'success' : ($p['conversion_pct'] >= $s['warn_pct'] ? 'warning' : 'danger')))
                        <div class="flex-1 flex flex-col items-center justify-end gap-1"
                             title="{{ $p['label'] }}: {{ $pct($p['conversion_pct']) }} — {{ $p['paid'] }}/{{ $p['orders'] }} заказов{{ $p['current'] ? ' (текущий, ещё копится)' : '' }}">
                            <span class="text-[10px] {{ $light[$lvl] }} font-semibold">{{ $p['conversion_pct'] === null ? '—' : number_format($p['conversion_pct'], 0) }}</span>
                            <div class="w-full rounded-t {{ $dot[$lvl] }} {{ $p['current'] ? 'opacity-50' : '' }}" style="height: {{ $h }}%"></div>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500 {{ $p['current'] ? 'font-bold' : '' }}">{{ $p['label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 text-[11px] text-gray-400 dark:text-gray-500">
                    Высота = конверсия %. Полупрозрачный столбец — текущий незавершённый период (цифра ещё копится).
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Разрезы: где течёт (по курсу / по каналу) ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([['По курсу', 'course', 'Курс', $s['by_course']], ['По каналу привлечения', 'channel', 'Канал', $s['by_channel']]] as [$heading, $keyField, $colLabel, $rows])
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <div class="px-4 pt-3 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    {{ $heading }} <span class="text-xs font-normal text-gray-400">· {{ $s['breakdown_window_days'] }} дн.</span>
                </div>
                <table class="w-full text-sm mt-2">
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">{{ $colLabel }}</th>
                            <th class="px-4 py-2 text-right font-semibold">Заказы</th>
                            <th class="px-4 py-2 text-right font-semibold">Оплачено</th>
                            <th class="px-4 py-2 text-right font-semibold">Конверсия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-4 py-2">{{ $row[$keyField] }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['orders'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['paid'] }}</td>
                                <td class="px-4 py-2 text-right font-semibold {{ $light[$row['level']] }}">
                                    {{ $pct($row['conversion_pct']) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-3 text-center text-gray-400">Нет заказов за окно</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    {{-- ── Недожатые заказы: рабочий список «кого дожать» ── --}}
    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <div class="px-4 pt-3 flex items-center justify-between">
            <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                Недожатые заказы — кого дожать
                <span class="text-xs font-normal text-gray-400">· оформлены &gt; {{ $s['unclosed']['after_days'] }} дн. назад, не оплачены</span>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $s['unclosed']['count'] }} шт · {{ $money($s['unclosed']['sum']) }}
            </div>
        </div>
        <table class="w-full text-sm mt-2">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">Ученик</th>
                    <th class="px-4 py-2 text-left font-semibold">Курс</th>
                    <th class="px-4 py-2 text-right font-semibold">Сумма</th>
                    <th class="px-4 py-2 text-right font-semibold">Возраст</th>
                    <th class="px-4 py-2 text-right font-semibold">Оформлен</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($s['unclosed']['rows'] as $row)
                    <tr>
                        <td class="px-4 py-2">{{ $row['user'] }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $row['course'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $money($row['amount']) }}</td>
                        <td class="px-4 py-2 text-right {{ $row['age_days'] >= 14 ? $light['danger'] : $light['gray'] }}">
                            {{ $row['age_days'] }} дн.
                        </td>
                        <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ $row['created_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-3 text-center text-success-600 dark:text-success-400">Недожатых заказов нет — всё дожато 👍</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Допущения (чтобы читать цифры без интерпретации) ── --}}
    <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
        <div><b>Оформленный заказ</b> = реальный (не conditional) платёж-заказ, кроме техрасходов/выплат ЗП и брони/пробного (config <code>conversion.excluded_tariffs</code>) — намерение купить курс.</div>
        <div><b>Конверсия</b> считается когортно по дате оформления: из заказов периода — доля дошедших до статуса paid/success. Текущий период занижен лагом (заказы конца окна ещё легитимно «в работе»).</div>
        <div><b>Недожатый заказ</b> = pending дольше {{ $s['unclosed']['after_days'] }} дн. Это ранний сигнал ДО дебиторки@if ($s['receivables_url']) (<a href="{{ $s['receivables_url'] }}" class="text-primary-600 dark:text-primary-400 underline">Дебиторка: план-факт</a>, H257)@endif — источник данных другой (pending-заказы, а не непогашенные обещания), логику дебиторки не дублируем.</div>
        <div>Цель/порог/окна — config/conversion.php (меняются через .env без деплоя). Снимок на {{ $s['as_of'] }}.</div>
    </div>
</x-filament-panels::page>
