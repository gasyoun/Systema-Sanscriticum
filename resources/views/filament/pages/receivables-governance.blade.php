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

    {{-- ── Алерт: заметная красная плашка при превышении порога/лимитов ── --}}
    @if (! empty($s['alerts']))
        <div class="rounded-xl bg-danger-50 p-4 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:ring-danger-400/30">
            <div class="flex items-center gap-2 text-danger-700 dark:text-danger-400 font-semibold">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
                Контроль дебиторки: превышение
            </div>
            <ul class="mt-2 list-disc pl-6 text-sm text-danger-700 dark:text-danger-300 space-y-1">
                @foreach ($s['alerts'] as $alert)
                    <li>{{ $alert }}</li>
                @endforeach
            </ul>
            <div class="mt-2 text-xs text-danger-600/80 dark:text-danger-300/70">
                Условия рассрочки утверждает финдир — не расширять лимиты в обход (см. CLAUDE.md репозитория).
            </div>
        </div>
    @else
        <div class="rounded-xl bg-success-50 p-4 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:ring-success-400/30 text-sm text-success-700 dark:text-success-300">
            <span class="font-semibold">Дебиторка под контролем.</span>
            Порог и лимиты рассрочки не пробиты.
        </div>
    @endif

    {{-- ── Дебиторка против порога ── --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2">
                <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$s['level']] }}"></span>
                Дебиторка сейчас
            </div>
            <div class="text-3xl font-bold {{ $light[$s['level']] }}">{{ $money($s['total']) }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                из порога <b>{{ $money($s['threshold']) }}</b>
                {{ $s['ratio_pct'] === null ? '' : '· '.number_format($s['ratio_pct'], 0).'% порога' }}
            </div>
        </div>

        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Неликвид (просрочка > {{ $s['illiquid_days'] }} дн.)</div>
            <div class="text-3xl font-bold {{ $s['overdue_pct_rising'] ? $light['danger'] : $light['gray'] }}">
                {{ number_format($s['overdue_pct'], 1) }}%
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $money($s['overdue']) }} · {{ $s['overdue_count'] }} шт ·
                нед. назад {{ number_format($s['prev_overdue_pct'], 1) }}%
                {{ $s['overdue_pct_rising'] ? '↑ растёт' : '' }}
            </div>
        </div>

        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Δ за неделю</div>
            <div class="text-3xl font-bold {{ $s['delta_abs'] > 0 ? $light['warning'] : $light['success'] }}">
                {{ ($s['delta_abs'] >= 0 ? '+' : '').$money($s['delta_abs']) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                было {{ $money($s['prev_total']) }}
                {{ $s['delta_pct'] === null ? '' : '· '.($s['delta_pct'] >= 0 ? '+' : '').number_format($s['delta_pct'], 1).'%' }}
            </div>
        </div>
    </div>

    {{-- ── Разрез: рассрочка vs прочая дебиторка ── --}}
    <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
        <div class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">
            Разрез дебиторки — рассрочка против прочей
        </div>
        <div class="flex items-center gap-3 text-xs mb-2">
            <div class="flex-1 bg-gray-100 dark:bg-white/5 rounded h-5 overflow-hidden flex">
                <div class="h-5 bg-info-500" style="width: {{ $s['installment_share_of_debt_pct'] }}%"></div>
                <div class="h-5 bg-gray-400 dark:bg-gray-500" style="width: {{ 100 - $s['installment_share_of_debt_pct'] }}%"></div>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-info-500"></span>
                Рассрочка: <b>{{ $money($s['installment']) }}</b>
                <span class="text-gray-500 dark:text-gray-400">({{ number_format($s['installment_share_of_debt_pct'], 1) }}%)</span>
            </div>
            <div>
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-gray-400"></span>
                Прочая: <b>{{ $money($s['other']) }}</b>
            </div>
        </div>
    </div>

    {{-- ── Лимиты рассрочки: факт против потолка ── --}}
    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">Лимит рассрочки</th>
                    <th class="px-4 py-2 text-right font-semibold">Сейчас</th>
                    <th class="px-4 py-2 text-right font-semibold">Потолок</th>
                    <th class="px-4 py-2 text-center font-semibold">Статус</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($s['limits'] as $limit)
                    <tr>
                        <td class="px-4 py-2">{{ $limit['label'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold {{ $limit['breached'] ? $light['danger'] : $light['gray'] }}">
                            {{ $limit['current'] === null ? '—' : $limit['current'] }} {{ $limit['unit'] }}
                        </td>
                        <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">
                            {{ $limit['limit'] }} {{ $limit['unit'] }}
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if ($limit['breached'])
                                <span class="inline-flex items-center gap-1 text-danger-600 dark:text-danger-400 font-semibold">
                                    <span class="inline-block h-2 w-2 rounded-full bg-danger-500"></span> превышен
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-success-600 dark:text-success-400">
                                    <span class="inline-block h-2 w-2 rounded-full bg-success-500"></span> в норме
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── Допущения (чтобы финдир читал цифры без интерпретации) ── --}}
    <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
        <div><b>Дебиторка</b> = сумма непогашенных обещаний оплаты (active/expired с заданной суммой); рассрочка — обещания, объединённые в план (installment_group_id).</div>
        <div><b>Порог</b> = {{ $s['threshold_basis'] }}. Меняется в config/receivables.php без деплоя.</div>
        <div><b>Неликвид</b> = долг с просрочкой сверх {{ $s['illiquid_days'] }} дн. от обещанной даты. <b>Δ за неделю</b> восстанавливается из леджера обещаний (без снапшотов).</div>
        <div>Снимок на {{ $s['as_of'] }}.</div>
    </div>
</x-filament-panels::page>
