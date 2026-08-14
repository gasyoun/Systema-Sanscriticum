<x-filament-panels::page>
    @php
        $s = $snap;
        $p = $s['pipeline'];
        $a = $s['actuals_previous_horizon'];
        $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', ' ').' ₽';
        $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).' %';
        $probs = $s['assumptions']['stage_probabilities'];
    @endphp

    @if ($scoped)
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            Показаны только ваши сделки (<code>deals.assigned_to</code>) и оплаты, которые оформили вы
            (<code>payments.created_by_user_id</code>). Чужие строки сюда не попадают.
        </p>
    @endif

    <div class="rounded-xl ring-1 ring-amber-500/30 bg-amber-50/70 dark:bg-amber-500/10 px-4 py-3 text-sm mb-4" data-testid="forecast-assumptions">
        <div class="font-semibold text-amber-900 dark:text-amber-200">Допущения — не точечный факт</div>
        <p class="mt-1 text-amber-900/80 dark:text-amber-100/80">
            Вероятности и окна живут в <code>config/crm_forecast.php</code>, не на этой странице.
            Горизонт {{ $s['horizon_days'] }} дн. Диапазон = сумма (сумма сделки × low…high).
            Журнал сделок доступен с {{ $s['assumptions']['history_available_from'] }}; более ранние окна — «нет данных», без выдуманных снимков.
        </p>
        <ul class="mt-2 grid gap-1 text-xs text-amber-900/80 dark:text-amber-100/80 sm:grid-cols-3">
            @foreach ($probs as $key => $band)
                @if (! in_array($key, ['won', 'lost'], true))
                    <li>
                        <span class="font-medium">{{ $key }}</span>:
                        {{ number_format($band['low'] * 100, 0) }}–{{ number_format($band['high'] * 100, 0) }} %
                        (середина {{ number_format($band['mid'] * 100, 0) }} %)
                    </li>
                @endif
            @endforeach
        </ul>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-4">
        <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Открытый пайплайн</div>
            <div class="mt-1 text-2xl font-bold" data-testid="forecast-open-amount">{{ $money($p['open_amount']) }}</div>
            <div class="text-xs text-gray-500">{{ $p['deal_count'] }} сделок</div>
        </div>
        <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Взвешенный прогноз</div>
            <div class="mt-1 text-2xl font-bold" data-testid="forecast-weighted-mid">{{ $money($p['weighted_mid']) }}</div>
            <div class="text-xs text-gray-500">{{ $money($p['weighted_low']) }} … {{ $money($p['weighted_high']) }}</div>
        </div>
        <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Факт за прошлый горизонт</div>
            <div class="mt-1 text-2xl font-bold" data-testid="forecast-actuals">{{ $money($a['paid_amount']) }}</div>
            <div class="text-xs text-gray-500">{{ $a['paid_count'] }} оплат · qualifying Payment</div>
        </div>
        <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500">Покрытие next action</div>
            <div class="mt-1 text-2xl font-bold" data-testid="forecast-next-action">{{ $pct($p['next_action']['coverage_pct']) }}</div>
            <div class="text-xs text-gray-500">{{ $p['next_action']['with_open_task'] }} / {{ $p['next_action']['open_deals'] }} открытых FollowUpTask</div>
        </div>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
        {{ $s['forecast_vs_actual']['reason'] }}
        Атрибуция: пайплайн = <code>{{ $s['attribution']['pipeline'] }}</code>, факт =
        <code>{{ $s['attribution']['actuals'] }}</code>.
    </p>

    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 mb-4">
        <div class="px-4 pt-3 text-sm font-semibold">По стадии</div>
        <table class="w-full text-sm mt-2">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">Стадия</th>
                    <th class="px-4 py-2 text-right font-semibold">Сделок</th>
                    <th class="px-4 py-2 text-right font-semibold">Сумма</th>
                    <th class="px-4 py-2 text-right font-semibold">p mid</th>
                    <th class="px-4 py-2 text-right font-semibold">Взвешено</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($p['by_stage'] as $row)
                    <tr>
                        <td class="px-4 py-2">{{ $row['stage_name'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['deal_count'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $money($row['open_amount']) }}</td>
                        <td class="px-4 py-2 text-right">{{ $pct($row['probability_mid'] * 100) }}</td>
                        <td class="px-4 py-2 text-right">{{ $money($row['weighted_mid']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-3 text-center text-gray-400">Нет открытых сделок</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 mb-4">
        <div class="px-4 pt-3 text-sm font-semibold">Возраст стадии</div>
        <table class="w-full text-sm mt-2">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">Дней в стадии</th>
                    <th class="px-4 py-2 text-right font-semibold">Сделок</th>
                    <th class="px-4 py-2 text-right font-semibold">Сумма</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($p['aging'] as $row)
                    <tr>
                        <td class="px-4 py-2">{{ $row['bucket'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['deal_count'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $money($row['open_amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @unless ($scoped)
        <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 mb-4">
            <div class="px-4 pt-3 text-sm font-semibold">По менеджеру (пайплайн)</div>
            <table class="w-full text-sm mt-2">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left font-semibold">Менеджер</th>
                        <th class="px-4 py-2 text-right font-semibold">Сделок</th>
                        <th class="px-4 py-2 text-right font-semibold">Пайплайн</th>
                        <th class="px-4 py-2 text-right font-semibold">Прогноз mid</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($p['by_manager'] as $row)
                        <tr>
                            <td class="px-4 py-2">{{ $row['manager'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $row['deal_count'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $money($row['open_amount']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $money($row['weighted_mid']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-center text-gray-400">Нет строк</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endunless

    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 mb-4">
        <div class="px-4 pt-3 text-sm font-semibold">Бэктест (закрытые окна)</div>
        <table class="w-full text-sm mt-2" data-testid="forecast-backtest">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">Окно</th>
                    <th class="px-4 py-2 text-left font-semibold">Статус</th>
                    <th class="px-4 py-2 text-right font-semibold">Прогноз mid</th>
                    <th class="px-4 py-2 text-right font-semibold">Факт</th>
                    <th class="px-4 py-2 text-right font-semibold">Ошибка</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($s['backtest'] as $row)
                    <tr>
                        <td class="px-4 py-2">{{ $row['window_days'] }} дн.</td>
                        <td class="px-4 py-2">
                            {{ $row['status'] === 'available' ? 'есть данные' : ($row['status'] === 'partial' ? 'частично' : 'нет данных') }}
                            @if ($row['reason'])
                                <div class="text-xs text-gray-500 mt-1">{{ $row['reason'] }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">{{ $money($row['forecast_mid']) }}</td>
                        <td class="px-4 py-2 text-right">{{ $money($row['actual_amount']) }}</td>
                        <td class="px-4 py-2 text-right">{{ $money($row['error']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Снимок на {{ $s['as_of'] }}. Деньги только читаются. Знаменатель факта:
        {{ $s['assumptions']['actuals_denominator'] }}.
    </p>
</x-filament-panels::page>
