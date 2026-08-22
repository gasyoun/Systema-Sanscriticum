@php
    $money = fn ($v): string => number_format((float) $v, 2, ',', ' ');
    $grid = $grid ?? ['weeks' => [], 'tochka' => ['ok' => false], 'paypal_note' => ''];
@endphp

<x-filament-panels::page>
    <div class="rounded-xl bg-primary-50 p-4 text-sm ring-1 ring-primary-500/20 dark:bg-primary-500/10">
        <p class="font-semibold text-primary-900 dark:text-primary-200">Только чтение</p>
        <p class="mt-1 text-primary-900/80 dark:text-primary-200/80">
            Эта страница не создаёт выплаты. Разметка легаси-«Расход» —
            <a href="{{ $attributionUrl }}" class="underline">Как размечать выплаты</a>.
            Начисление — <a href="{{ $salariesUrl }}" class="underline">Зарплаты</a>.
        </p>
    </div>

    <div class="mt-4 rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <p class="font-semibold">Недельный ритуал владельца</p>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-gray-700 dark:text-gray-300">
            <li>Ниже — кого платим на этой неделе (преподаватели) и кто молчит слишком долго (персонал).</li>
            <li>Проверить сборность должников по курсам получателя — раздел «Собранность должников».</li>
            <li>Заплатить: Точка ₽ / PayPal EUR (вручную).</li>
            <li>Записать: калькулятор «Записать выплату» на «Зарплатах» / занести opex в Финансы.</li>
            <li>Разметить новые «Расходы» в очереди разметки. НПД-чек самозанятым: зачёт до выплаты, −6 %.</li>
        </ol>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="font-semibold">Точка, к трате (ClosingAvailable)</p>
            @if (!empty($grid['tochka']['ok']))
                <p class="mt-1 text-lg">{{ $money($grid['tochka']['closing_total']) }} ₽</p>
            @else
                <p class="mt-1 text-danger-600">нет данных ({{ $grid['tochka']['error'] ?? 'банк' }})</p>
            @endif
        </div>
        <div class="rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="font-semibold">PayPal EUR</p>
            <p class="mt-1">{{ $grid['paypal_note'] ?? 'откройте PayPal' }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-left">Неделя ISO</th>
                    <th class="px-3 py-2 text-left">Даты</th>
                    <th class="px-3 py-2 text-left">Кто (id)</th>
                    <th class="px-3 py-2 text-left">₽ / EUR</th>
                    <th class="px-3 py-2 text-left">Триггер</th>
                    <th class="px-3 py-2 text-left">Точка</th>
                    <th class="px-3 py-2 text-left">PayPal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($grid['weeks'] as $week)
                    @if (count($week['due']) === 0)
                        @continue
                    @endif
                    @foreach ($week['due'] as $i => $due)
                        <tr class="border-t border-gray-100 dark:border-white/10">
                            @if ($i === 0)
                                <td class="px-3 py-2 align-top" rowspan="{{ count($week['due']) }}">{{ $week['iso_week'] }}</td>
                                <td class="px-3 py-2 align-top whitespace-nowrap" rowspan="{{ count($week['due']) }}">
                                    {{ \Illuminate\Support\Carbon::parse($week['start'])->format('d.m') }}
                                    –
                                    {{ \Illuminate\Support\Carbon::parse($week['end'])->format('d.m.Y') }}
                                </td>
                            @endif
                            <td class="px-3 py-2">
                                #{{ $due['teacher_id'] }} {{ $due['name'] }}
                                @if (!empty($due['preliminary']))
                                    <span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-800">предварительно</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $due['lane'] }}</td>
                            <td class="px-3 py-2">
                                {{ $due['trigger'] === 'block_4_end' ? 'конец блока 4' : 'годовщина прошлого месяца' }}
                                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($due['due_on'])->format('d.m.Y') }}</span>
                            </td>
                            @if ($i === 0)
                                <td class="px-3 py-2 align-top" rowspan="{{ count($week['due']) }}">
                                    @if ($week['tochka_cover'] === 'enough') хватит
                                    @elseif ($week['tochka_cover'] === 'short') не хватит
                                    @elseif ($week['tochka_cover'] === 'no_bank') нет банка
                                    @else —
                                    @endif
                                </td>
                                <td class="px-3 py-2 align-top" rowspan="{{ count($week['due']) }}">
                                    {{ $week['paypal_cover'] === 'open_the_bank' ? 'откройте PayPal' : '—' }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    @php
        $staff = $staff ?? ['payees' => [], 'totals' => ['monthly_estimate' => 0, 'owed_estimate' => 0]];
        $debts = $debts ?? [];
    @endphp

    <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Собранность должников по преподавателям</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        Студенты активных курсов, ещё не оплатившие текущий блок. Выплата без учёта несобранного — неполная картина.
    </p>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-left">Преподаватель</th>
                    <th class="px-3 py-2 text-left">Должников</th>
                    <th class="px-3 py-2 text-left">Не собрано, ₽</th>
                    <th class="px-3 py-2 text-left">Должники</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($debts as $d)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="px-3 py-2">#{{ $d['teacher_id'] }} {{ $d['name'] }}</td>
                        <td class="px-3 py-2">{{ $d['pairs'] }}</td>
                        <td class="px-3 py-2">{{ $money($d['amount']) }}</td>
                        <td class="px-3 py-2"><a href="{{ $debtorsUrl }}" class="underline">открыть раздел</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-2 text-gray-500">Должников нет.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @php
        $staff = $staff ?? ['payees' => [], 'totals' => ['monthly_estimate' => 0, 'owed_estimate' => 0]];
        $debts = $debts ?? [];
        $recentPayments = $recentPayments ?? [];
        $delayLabel = function ($d): string {
            if ($d === null) {
                return 'блоки без дат';
            }
            if ($d < 0) {
                return 'заранее на '.abs($d).' дн';
            }
            if ($d <= 3) {
                return 'вовремя';
            }

            return 'опоздал '.$d.' дн';
        };
    @endphp

    <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Должники платят: оплаты за последние 35 дней</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        По каждому преподавателю: кто оплатил, сколько, за какие блоки и с какой задержкой относительно старта самого раннего покрытого блока. Срез для ежемесячных выплат.
    </p>
    <div class="mt-2 space-y-4">
        @forelse ($recentPayments as $t)
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <p class="bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    #{{ $t['teacher_id'] }} {{ $t['name'] }} — {{ count($t['rows']) }} опл.
                </p>
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-1 text-left">Студент</th>
                            <th class="px-3 py-1 text-left">Курс</th>
                            <th class="px-3 py-1 text-left">Блоки</th>
                            <th class="px-3 py-1 text-right">Сумма</th>
                            <th class="px-3 py-1 text-left">Дата</th>
                            <th class="px-3 py-1 text-left">Задержка</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($t['rows'] as $r)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="px-3 py-1">{{ $r['student'] }}</td>
                                <td class="px-3 py-1">{{ $r['course'] }}</td>
                                <td class="px-3 py-1">{{ $r['blocks'] }}</td>
                                <td class="px-3 py-1 text-right">{{ $money($r['amount']) }}</td>
                                <td class="px-3 py-1">{{ $r['paid_at'] }}</td>
                                <td class="px-3 py-1">
                                    @if ($r['delay_days'] !== null && $r['delay_days'] > 3)
                                        <span class="rounded bg-danger-100 px-1 text-xs text-danger-800">{{ $delayLabel($r['delay_days']) }}</span>
                                    @else
                                        <span class="text-gray-600 dark:text-gray-300">{{ $delayLabel($r['delay_days']) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-sm text-gray-500">За последние 35 дней оплат не было.</p>
        @endforelse
    </div>

    <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Весь контур: персонал и повторяемые получатели (не преподаватели)</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        Ставка — среднее последних ≤3 плативших месяцев; «оценка долга» — ставка × полные месяцы тишины.
        Это экстраполяция (предварительно), а не начисление: решение о сумме — за человеком.
    </p>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-left">Получатель</th>
                    <th class="px-3 py-2 text-left">Категория</th>
                    <th class="px-3 py-2 text-left">Ставка/мес</th>
                    <th class="px-3 py-2 text-left">Последняя проводка</th>
                    <th class="px-3 py-2 text-left">Мес. тишины</th>
                    <th class="px-3 py-2 text-left">Оценка долга</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($staff['payees'] as $p)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="px-3 py-2">{{ $p['name'] }}</td>
                        <td class="px-3 py-2">{{ $p['category'] }}</td>
                        <td class="px-3 py-2">{{ $p['monthly_rate'] !== null ? $money($p['monthly_rate']).' ₽' : '—' }}</td>
                        <td class="px-3 py-2">{{ $p['last_month'] ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $p['silent_months'] > 0 ? $p['silent_months'] : '—' }}</td>
                        <td class="px-3 py-2">
                            {{ $p['owed_estimate'] > 0 ? $money($p['owed_estimate']).' ₽' : '—' }}
                            @if ($p['assumption'])
                                <span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-800">предварительно</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-2 text-gray-500">За 12 месяцев получателей не найдено.</td></tr>
                @endforelse
            </tbody>
            @if (count($staff['payees']) > 0)
                <tfoot class="bg-gray-50 text-xs dark:bg-white/5">
                    <tr>
                        <td class="px-3 py-2 font-semibold" colspan="2">Итого по персоналу (экстраполяция)</td>
                        <td class="px-3 py-2 font-semibold">{{ $money($staff['totals']['monthly_estimate']) }} ₽/мес</td>
                        <td class="px-3 py-2" colspan="2"></td>
                        <td class="px-3 py-2 font-semibold">{{ $money($staff['totals']['owed_estimate']) }} ₽</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
